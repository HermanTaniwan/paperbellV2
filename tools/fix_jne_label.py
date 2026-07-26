from pathlib import Path

from PIL import Image, ImageEnhance, ImageFilter, ImageOps
from reportlab.graphics.barcode import code128
from reportlab.lib.pagesizes import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas


SOURCE = Path(r"C:\Users\Herman Taniwan\Downloads\1000247396.jpg")
OUTPUT_DIR = Path("output/pdf")
TMP_DIR = Path("tmp/pdfs")
AWB = "TG3570989329"


def rectify_label(source: Path, output: Path) -> None:
    image = Image.open(source).convert("L")

    # The four visible corners of the printed content in the supplied photo.
    # PIL's QUAD transform maps the output rectangle back to these input points.
    # PIL expects UL, LL, LR, UR (not the more common UL, UR, LR, LL order).
    quad = (67, 239, 27, 1004, 659, 1032, 682, 253)
    rectified = image.transform(
        (1180, 1500),
        Image.Transform.QUAD,
        quad,
        resample=Image.Resampling.BICUBIC,
    )
    rectified = ImageOps.autocontrast(rectified, cutoff=(1, 1))
    rectified = ImageEnhance.Contrast(rectified).enhance(1.18)
    rectified = rectified.filter(ImageFilter.UnsharpMask(radius=1.2, percent=125, threshold=3))
    rectified.save(output, quality=95, dpi=(300, 300))


def make_pdf(rectified_path: Path, output_pdf: Path) -> None:
    page_w, page_h = 100 * mm, 150 * mm
    c = canvas.Canvas(str(output_pdf), pagesize=(page_w, page_h), pageCompression=1)

    # Keep a real print-safe margin even on inexpensive thermal printers.
    margin = 5 * mm
    image_w = page_w - 2 * margin
    image_h = image_w * (1500 / 1180)
    image_y = page_h - margin - image_h
    c.drawImage(
        ImageReader(str(rectified_path)),
        margin,
        image_y,
        width=image_w,
        height=image_h,
        preserveAspectRatio=True,
        mask="auto",
    )

    # Replace only the critical AWB area with a clean vector Code 128 barcode.
    # Large white quiet zones on both sides make handheld scanning more reliable.
    patch_x = 7 * mm
    patch_y = page_h - 76 * mm
    patch_w = page_w - 14 * mm
    patch_h = 29 * mm
    c.setFillColorRGB(1, 1, 1)
    c.rect(patch_x, patch_y, patch_w, patch_h, fill=1, stroke=0)

    c.setFillColorRGB(0, 0, 0)
    c.setFont("Helvetica-Bold", 7.5)
    c.drawCentredString(page_w / 2, patch_y + patch_h - 4.3 * mm, "AWB Number")

    barcode = code128.Code128(AWB, barHeight=14 * mm, barWidth=0.38 * mm, humanReadable=False)
    barcode_x = (page_w - barcode.width) / 2
    barcode_y = patch_y + 7.4 * mm
    barcode.drawOn(c, barcode_x, barcode_y)

    c.setFont("Helvetica-Bold", 15)
    c.drawCentredString(page_w / 2, patch_y + 2.2 * mm, AWB)

    c.showPage()
    c.save()


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    TMP_DIR.mkdir(parents=True, exist_ok=True)
    rectified = TMP_DIR / "jne_label_rectified.jpg"
    pdf = OUTPUT_DIR / "resi-jne-TG3570989329-siap-print.pdf"
    rectify_label(SOURCE, rectified)
    make_pdf(rectified, pdf)
    print(pdf.resolve())


if __name__ == "__main__":
    main()
