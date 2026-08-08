"""Headless TWAIN ADF runner for Paperbell.

This intentionally mirrors the proven WFScanner defaults on this host:
Epson TWAIN, ADF, single-sided, A5 landscape, RGB, 200 dpi, and blank-page
analysis after capture so blank sheets remain part of the total count.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import sys
import tempfile
import threading
import traceback
from datetime import datetime
from pathlib import Path
from tkinter import Tk

from PIL import Image, ImageStat

try:
    import twain
    import twain.exceptions as twain_exceptions
except Exception as exc:  # pragma: no cover - reported as a useful runtime error
    twain = None
    twain_exceptions = None
    TWAIN_IMPORT_ERROR = exc
else:
    TWAIN_IMPORT_ERROR = None


PREFERRED_SCANNERS = ("wf-c5710/c5790", "wf-c5790", "wf-c5710")
A5_LANDSCAPE_INCHES = (210 / 25.4, 148 / 25.4)


def now_text() -> str:
    return datetime.now().isoformat(timespec="seconds")


def require_twain() -> None:
    if twain is None:
        raise RuntimeError(f"Package pytwain tidak tersedia: {TWAIN_IMPORT_ERROR}")


def source_names() -> list[str]:
    require_twain()
    manager = twain.SourceManager(0)
    try:
        return [str(name) for name in (manager.source_list or [])]
    finally:
        try:
            manager.destroy()
        except Exception:
            pass


def preferred_source(names: list[str], requested: str = "") -> str:
    if requested and requested in names:
        return requested
    for keyword in PREFERRED_SCANNERS:
        for name in names:
            if keyword in name.lower():
                return name
    for name in names:
        if "epson" in name.lower():
            return name
    if not names:
        raise RuntimeError("Tidak ada TWAIN source yang tersedia.")
    return names[0]


def set_capability(source, capability, type_id, value) -> bool:
    try:
        source.set_capability(capability, type_id, value)
        return True
    except Exception:
        return False


def configure_adf(source, dpi: int) -> None:
    set_capability(source, twain.CAP_FEEDERENABLED, twain.TWTY_BOOL, True)
    set_capability(source, twain.CAP_AUTOFEED, twain.TWTY_BOOL, True)
    set_capability(source, twain.CAP_DUPLEXENABLED, twain.TWTY_BOOL, False)
    set_capability(source, twain.CAP_XFERCOUNT, twain.TWTY_INT16, -1)
    set_capability(source, twain.ICAP_UNITS, twain.TWTY_UINT16, twain.TWUN_INCHES)
    a5 = getattr(twain, "TWSS_A5", None)
    if a5 is not None:
        set_capability(source, twain.ICAP_SUPPORTEDSIZES, twain.TWTY_UINT16, a5)
    set_capability(source, twain.ICAP_ORIENTATION, twain.TWTY_UINT16, twain.TWOR_LANDSCAPE)
    set_capability(source, twain.ICAP_PIXELTYPE, twain.TWTY_UINT16, twain.TWPT_RGB)
    set_capability(source, twain.ICAP_XRESOLUTION, twain.TWTY_FIX32, float(dpi))
    set_capability(source, twain.ICAP_YRESOLUTION, twain.TWTY_FIX32, float(dpi))
    try:
        source.set_image_layout((0.0, 0.0, *A5_LANDSCAPE_INCHES))
    except Exception:
        pass


def analyze_blank_page(path: Path, threshold_percent: float) -> dict:
    with Image.open(path) as original:
        image = original.convert("L")
    width, height = image.size
    margin_x = max(8, int(width * 0.025))
    margin_y = max(8, int(height * 0.025))
    if width > margin_x * 2 and height > margin_y * 2:
        image = image.crop((margin_x, margin_y, width - margin_x, height - margin_y))

    stats = ImageStat.Stat(image)
    mean = float(stats.mean[0])
    stddev = float(stats.stddev[0])
    histogram = image.histogram()
    dark_pixels = sum(histogram[:220])
    total_pixels = max(sum(histogram), 1)
    dark_ratio = (dark_pixels / total_pixels) * 100.0
    blank_score = max(dark_ratio, stddev / 2.0, max(0.0, 250.0 - mean) / 5.0)
    is_blank = dark_ratio <= threshold_percent and stddev < 12.0 and mean > 238.0
    return {
        "is_blank": is_blank,
        "dark_ratio": round(dark_ratio, 4),
        "blank_score": round(blank_score, 4),
        "mean": round(mean, 3),
        "stddev": round(stddev, 3),
    }


class JobState:
    def __init__(self, job_dir: Path):
        self.job_dir = job_dir.resolve()
        self.path = self.job_dir / "status.json"
        self.data = json.loads(self.path.read_text(encoding="utf-8"))

    def update(self, **changes) -> None:
        self.data.update(changes)
        self.data["updated_at"] = now_text()
        fd, temporary = tempfile.mkstemp(prefix="status_", suffix=".json", dir=self.job_dir)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                json.dump(self.data, handle, ensure_ascii=False, indent=2)
            os.replace(temporary, self.path)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)


def format_scan_error(exc: BaseException) -> str:
    if exc.__class__.__name__ == "CheckDeviceOnlineError":
        return (
            "TWAIN Epson terdaftar, tetapi scanner dianggap offline. Buka Epson Scan 2 Utility / "
            "Scanner Settings dan pastikan WF-C5710/C5790 Network 02 aktif."
        )
    text = str(exc).strip()
    if "32770" in text:
        return (
            "Driver Epson tidak dapat membuka scanner (ConditionCode 32770). Pastikan WF-C5790 menyala, "
            "terhubung ke jaringan, dan WF-C5710/C5790 Network 02 aktif di Epson Scan 2 Utility."
        )
    return text or exc.__class__.__name__


def is_end_of_feeder(exc: BaseException) -> bool:
    cancel_all = getattr(twain_exceptions, "CancelAll", ()) if twain_exceptions else ()
    if cancel_all and isinstance(exc, cancel_all):
        return True
    text = f"{exc.__class__.__name__} {exc}".lower()
    return any(
        marker in text
        for marker in (
            "paper empty",
            "feeder empty",
            "no document",
            "no documents",
            "document feeder",
            "check status",
            "end of",
            "eof",
        )
    )


def make_pdf(page_paths: list[Path], output_path: Path) -> None:
    if not page_paths:
        return
    images = []
    try:
        for page_path in page_paths:
            with Image.open(page_path) as image:
                images.append(image.convert("RGB"))
        images[0].save(output_path, "PDF", resolution=200.0, save_all=True, append_images=images[1:])
    finally:
        for image in images:
            image.close()


def write_report(pages: list[dict], output_path: Path) -> None:
    with output_path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.writer(handle)
        writer.writerow(["page", "status", "dark_ratio_percent", "blank_score", "file"])
        for page in pages:
            writer.writerow(
                [
                    page["number"],
                    "KOSONG" if page["is_blank"] else "TERCETAK",
                    f'{page["dark_ratio"]:.4f}',
                    f'{page["blank_score"]:.4f}',
                    page["file"],
                ]
            )


def run_scan_with_owner(job_dir: Path, owner_handle: int) -> int:
    require_twain()
    state = JobState(job_dir)
    settings = state.data.get("settings") or {}
    dpi = max(100, min(600, int(settings.get("dpi", 200))))
    threshold = max(0.01, min(5.0, float(settings.get("blank_threshold", 0.18))))
    requested_source = str(settings.get("source", ""))
    page_paths: list[Path] = []
    manager = None
    source = None

    try:
        state.update(status="starting", message="Menghubungkan ke driver TWAIN…", started_at=now_text())
        manager = twain.SourceManager(owner_handle)
        names = [str(name) for name in (manager.source_list or [])]
        selected_source = preferred_source(names, requested_source)
        state.update(source=selected_source, status="scanning", message=f"Membuka {selected_source}…")
        source = manager.open_source(selected_source)
        configure_adf(source, dpi)

        def before(_image_info):
            page_path = job_dir / f"page_{len(page_paths) + 1:04d}.png"
            page_paths.append(page_path)
            state.update(
                status="scanning",
                message=f"Memindai halaman {len(page_paths)} dari ADF…",
                captured_pages=len(page_paths) - 1,
            )
            return str(page_path)

        def after(_more):
            existing = sum(1 for path in page_paths if path.exists())
            state.update(captured_pages=existing, message=f"Halaman {existing} berhasil diterima…")

        try:
            source.acquire_file(before=before, after=after, show_ui=False, modal=True)
        except Exception as exc:
            if not is_end_of_feeder(exc):
                raise

        page_paths = [path for path in page_paths if path.exists()]
        if not page_paths:
            raise RuntimeError("Tidak ada kertas yang berhasil dipindai. Pastikan kertas sudah terpasang di ADF.")

        state.update(status="analyzing", message=f"Menganalisis {len(page_paths)} halaman…", captured_pages=len(page_paths))
        pages = []
        for number, page_path in enumerate(page_paths, start=1):
            analysis = analyze_blank_page(page_path, threshold)
            pages.append(
                {
                    "number": number,
                    "file": page_path.name,
                    "is_blank": analysis["is_blank"],
                    "status": "blank" if analysis["is_blank"] else "printed",
                    "dark_ratio": analysis["dark_ratio"],
                    "blank_score": analysis["blank_score"],
                }
            )
            state.update(message=f"Menganalisis halaman {number} dari {len(page_paths)}…", pages=pages)

        blank_numbers = [page["number"] for page in pages if page["is_blank"]]
        write_report(pages, job_dir / "report.csv")
        make_pdf(page_paths, job_dir / "scan.pdf")
        state.update(
            status="completed",
            message=f"Selesai: {len(pages)} lembar, {len(blank_numbers)} blank.",
            completed_at=now_text(),
            total_pages=len(pages),
            total_sheets=len(pages),
            printed_pages=len(pages) - len(blank_numbers),
            blank_pages=len(blank_numbers),
            blank_page_numbers=blank_numbers,
            pages=pages,
            pdf_available=(job_dir / "scan.pdf").exists(),
            report_available=True,
        )
        return 0
    except Exception as exc:
        state.update(
            status="failed",
            message="Scan gagal.",
            error=format_scan_error(exc),
            completed_at=now_text(),
            captured_pages=sum(1 for path in page_paths if path.exists()),
        )
        (job_dir / "error.log").write_text(traceback.format_exc(), encoding="utf-8")
        return 1
    finally:
        if source is not None:
            try:
                source.close()
            except Exception:
                pass
        if manager is not None:
            try:
                manager.destroy()
            except Exception:
                pass


def run_scan(job_dir: Path) -> int:
    """Run TWAIN on a worker while the hidden owner window pumps messages.

    This matches the working WFScanner architecture. Epson's data source posts
    messages to the owner HWND even in automatic mode, so merely creating a
    hidden window without running its message loop is not sufficient.
    """
    owner_window = Tk()
    owner_window.withdraw()
    owner_window.update_idletasks()
    result = {"code": 1}

    def worker() -> None:
        try:
            result["code"] = run_scan_with_owner(job_dir, owner_window.winfo_id())
        finally:
            try:
                owner_window.after(0, owner_window.quit)
            except Exception:
                pass

    thread = threading.Thread(target=worker, name="paperbell-twain", daemon=True)
    owner_window.after(50, thread.start)
    owner_window.mainloop()
    thread.join(timeout=2)
    owner_window.destroy()
    return int(result["code"])


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    subparsers.add_parser("list-sources")
    scan_parser = subparsers.add_parser("scan")
    scan_parser.add_argument("--job-dir", required=True, type=Path)
    args = parser.parse_args()

    if args.command == "list-sources":
        print(json.dumps({"sources": source_names()}, ensure_ascii=False))
        return 0
    return run_scan(args.job_dir.resolve())


if __name__ == "__main__":
    sys.exit(main())
