import tempfile
import sys
from pathlib import Path

from pypdf import PdfReader
from reportlab.pdfgen import canvas

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from tools.prepare_label_pdf import A6_HEIGHT_POINTS, MM_TO_POINTS, PAPER_WIDTH_POINTS, prepare_label


def source_pdf(path: Path, height_mm: float, content_depth_mm: float) -> None:
    page_height = height_mm * MM_TO_POINTS
    output = canvas.Canvas(str(path), pagesize=(PAPER_WIDTH_POINTS, page_height))
    output.drawString(10, page_height - 15, "SHIPPING LABEL")
    output.drawString(10, page_height - content_depth_mm * MM_TO_POINTS, "END OF LABEL")
    output.save()


with tempfile.TemporaryDirectory(prefix="paperbell-a6-label-") as directory:
    root = Path(directory)

    long_source = root / "long.pdf"
    long_output = root / "long-ready.pdf"
    source_pdf(long_source, 375, 370)
    prepare_label(str(long_source), str(long_output), 2, "a6")
    long_pages = PdfReader(str(long_output)).pages
    assert len(long_pages) == 2
    assert all(abs(float(page.mediabox.width) - PAPER_WIDTH_POINTS) < 0.1 for page in long_pages)
    assert all(abs(float(page.mediabox.height) - A6_HEIGHT_POINTS) < 0.1 for page in long_pages)
    assert sum(len(list(page.images)) for page in long_pages) == 0

    short_source = root / "short.pdf"
    short_output = root / "short-ready.pdf"
    source_pdf(short_source, 148, 35)
    prepare_label(str(short_source), str(short_output), 2, "a6")
    short_pages = PdfReader(str(short_output)).pages
    assert len(short_pages) == 1
    assert sum(len(list(page.images)) for page in short_pages) > 0

print("A6 label preparation tests passed")
