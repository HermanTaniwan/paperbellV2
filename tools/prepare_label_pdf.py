import io
import math
import sys
from pathlib import Path
from typing import Any

from PIL import Image, ImageChops
from pypdf import PdfReader, PdfWriter, Transformation
from pypdf._page import PageObject
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas


MM_TO_POINTS = 72 / 25.4
PAPER_WIDTH_POINTS = 105 * MM_TO_POINTS
PAPER_HEIGHT_POINTS = 182 * MM_TO_POINTS
LETTER_WIDTH_POINTS = 215.9 * MM_TO_POINTS
LETTER_HEIGHT_POINTS = 279.4 * MM_TO_POINTS
B6_WIDTH_POINTS = 128.02 * MM_TO_POINTS
B6_HEIGHT_POINTS = 182.12 * MM_TO_POINTS
REFERENCE_A6_HEIGHT_POINTS = 148 * MM_TO_POINTS
LABEL_SCALE = 0.72
LABEL_RIGHT_SHIFT_POINTS = 5 * MM_TO_POINTS
DEFAULT_TOP_MARGIN_MM = 2
PAGE_GAP_POINTS = 1.5 * MM_TO_POINTS
PROMO_BOTTOM_POINTS = 2 * MM_TO_POINTS
PROMO_GAP_POINTS = 0
CONTENT_PADDING_POINTS = 0.25 * MM_TO_POINTS
CONTINUATION_NOISE_LIMIT_POINTS = 7 * MM_TO_POINTS
PROMO_WHITE_THRESHOLD = 18
PROMO_IMAGE = Path(__file__).resolve().parents[1] / "assets" / "label-unboxing.jpeg"


def color_is_white(color: object) -> bool:
    if color is None:
        return False
    if isinstance(color, (int, float)):
        return float(color) >= 0.98
    if isinstance(color, (list, tuple)):
        values = [float(value) for value in color]
        if len(values) == 4:  # CMYK white contains no ink.
            return all(value <= 0.02 for value in values)
        return bool(values) and all(value >= 0.98 for value in values)
    return False


def object_is_visible(object_type: str, item: dict) -> bool:
    if object_type in ("char", "image"):
        return True
    if object_type == "line":
        return item.get("stroke", True) is not False and not color_is_white(
            item.get("stroking_color")
        )
    if object_type in ("rect", "curve"):
        fill_visible = item.get("fill", False) and not color_is_white(
            item.get("non_stroking_color")
        )
        stroke_visible = item.get("stroke", False) and not color_is_white(
            item.get("stroking_color")
        )
        return fill_visible or stroke_visible
    return True


def page_content_height(page: Any, page_height: float) -> float:
    """Return the visible content height measured down from the top edge."""
    bottoms = []
    for object_type in ("char", "line", "rect", "curve", "image"):
        for item in page.objects.get(object_type, []):
            if not object_is_visible(object_type, item):
                continue
            bottom = item.get("bottom")
            if bottom is not None:
                bottoms.append(float(bottom))
    if not bottoms:
        return 0
    return min(page_height, max(bottoms) + CONTENT_PADDING_POINTS)


def multiply_pdf_matrices(left: list[float], right: list[float]) -> list[float]:
    """Multiply two six-value PDF transformation matrices."""
    return [
        left[0] * right[0] + left[1] * right[2],
        left[0] * right[1] + left[1] * right[3],
        left[2] * right[0] + left[3] * right[2],
        left[2] * right[1] + left[3] * right[3],
        left[4] * right[0] + left[5] * right[2] + right[4],
        left[4] * right[1] + left[5] * right[3] + right[5],
    ]


def fast_text_content_height(page: PageObject) -> float:
    """Estimate visible content depth using pypdf's lightweight text visitor.

    Marketplace labels end with a text footer. Reading its transformed baseline
    avoids retaining the unused bottom of a one-page A6 document without the
    substantially slower pdfplumber layout pass.
    """
    page_height = float(page.mediabox.height)
    bottoms: list[float] = []

    def visit_text(
        text: str,
        current_matrix: list[float],
        text_matrix: list[float],
        _font: Any,
        font_size: float,
    ) -> None:
        if not text.strip():
            return
        matrix = multiply_pdf_matrices(text_matrix, current_matrix)
        vertical_scale = math.hypot(matrix[2], matrix[3])
        if vertical_scale <= 0:
            return
        # The transformed origin lies near the glyph baseline. Half the scaled
        # font size is a conservative estimate of the descender below it.
        bottom = page_height - matrix[5] + (float(font_size) * vertical_scale * 0.5)
        if 0 <= bottom <= page_height:
            bottoms.append(bottom)

    page.extract_text(visitor_text=visit_text)
    if not bottoms:
        return page_height
    return min(page_height, max(bottoms) + CONTENT_PADDING_POINTS)


def top_crop(source_page: PageObject, crop_height: float) -> PageObject:
    """Create a page containing only the top crop, preserving vector quality."""
    source_width = float(source_page.mediabox.width)
    source_height = float(source_page.mediabox.height)
    cropped_page = PageObject.create_blank_page(width=source_width, height=crop_height)
    cropped_page.merge_transformed_page(
        source_page,
        Transformation().translate(0, crop_height - source_height),
        expand=False,
    )
    return cropped_page


def promo_image_and_aspect() -> tuple[ImageReader, float]:
    if not PROMO_IMAGE.is_file():
        raise RuntimeError(f"Gambar pemberitahuan unboxing tidak ditemukan: {PROMO_IMAGE}")

    with Image.open(PROMO_IMAGE) as source:
        bitmap = source.convert("RGB")
    white = Image.new("RGB", bitmap.size, "white")
    difference = ImageChops.difference(bitmap, white).convert("L")
    content_box = difference.point(
        lambda value: 255 if value > PROMO_WHITE_THRESHOLD else 0
    ).getbbox()
    if content_box and content_box[1] > 0:
        # Only trim the top: this is the edge that must touch the end of the
        # resi. Preserve the original horizontal alignment and bottom artwork.
        bitmap = bitmap.crop((0, content_box[1], bitmap.width, bitmap.height))

    image = ImageReader(bitmap)
    image_width, image_height = bitmap.size
    if image_width <= 0 or image_height <= 0:
        raise RuntimeError("Ukuran gambar pemberitahuan unboxing tidak valid")

    return image, image_height / image_width


def promo_overlay(
    image: ImageReader,
    rendered_x: float,
    rendered_y: float,
    rendered_width: float,
    rendered_height: float,
    page_width: float,
    page_height: float,
) -> PageObject:
    stream = io.BytesIO()
    overlay_canvas = canvas.Canvas(stream, pagesize=(page_width, page_height))
    overlay_canvas.drawImage(
        image,
        rendered_x,
        rendered_y,
        width=rendered_width,
        height=rendered_height,
        preserveAspectRatio=True,
        mask="auto",
    )
    overlay_canvas.save()
    stream.seek(0)
    return PdfReader(stream).pages[0]


def prepare_label(
    source_path: str,
    output_path: str,
    top_margin_mm: float = DEFAULT_TOP_MARGIN_MM,
    driver_page_mode: str = "custom",
) -> None:
    if top_margin_mm < 0 or top_margin_mm >= 20:
        raise ValueError("Margin atas harus antara 0 dan kurang dari 20 mm")
    if driver_page_mode not in ("custom", "letter", "b6"):
        raise ValueError("Mode halaman driver harus custom, letter, atau b6")

    output_width = PAPER_WIDTH_POINTS
    output_height = PAPER_HEIGHT_POINTS
    if driver_page_mode == "letter":
        output_width = LETTER_WIDTH_POINTS
        output_height = LETTER_HEIGHT_POINTS
    elif driver_page_mode == "b6":
        output_width = B6_WIDTH_POINTS
        output_height = B6_HEIGHT_POINTS
    physical_left = max(0, (output_width - PAPER_WIDTH_POINTS) / 2)
    physical_bottom = output_height - PAPER_HEIGHT_POINTS

    reader = PdfReader(source_path)
    if reader.is_encrypted:
        raise RuntimeError("PDF label terenkripsi tidak didukung")
    if not reader.pages:
        raise RuntimeError("PDF label tidak memiliki halaman")
    if len(reader.pages) > 2:
        raise RuntimeError("PDF label lebih dari 2 halaman; tidak aman digabung otomatis")

    promo_image, promo_aspect = promo_image_and_aspect()
    crop_heights = []
    if len(reader.pages) == 1:
        source_page = reader.pages[0]
        if source_page.rotation:
            source_page.transfer_rotation_to_content()
        crop_heights.append((source_page, fast_text_content_height(source_page)))
    else:
        import pdfplumber

        with pdfplumber.open(source_path) as layout_pdf:
            for index, source_page in enumerate(reader.pages):
                if source_page.rotation:
                    # Rotated marketplace labels are uncommon. Keeping the complete
                    # page avoids accidentally clipping content in a different axis.
                    source_page.transfer_rotation_to_content()
                    content_height = float(source_page.mediabox.height)
                else:
                    source_height = float(source_page.mediabox.height)
                    content_height = page_content_height(layout_pdf.pages[index], source_height)

                if index > 0 and content_height <= CONTINUATION_NOISE_LIMIT_POINTS:
                    continue
                crop_heights.append((source_page, max(content_height, CONTENT_PADDING_POINTS)))

    if not crop_heights:
        raise RuntimeError("PDF label tidak memiliki konten yang dapat dicetak")

    max_source_width = max(float(page.mediabox.width) for page, _ in crop_heights)
    max_source_height = max(float(page.mediabox.height) for page, _ in crop_heights)
    reference_fit = min(
        PAPER_WIDTH_POINTS / max_source_width,
        REFERENCE_A6_HEIGHT_POINTS / max_source_height,
    )
    base_scale = reference_fit * LABEL_SCALE

    label_top = output_height - (top_margin_mm * MM_TO_POINTS)
    gaps_height = PAGE_GAP_POINTS * max(0, len(crop_heights) - 1)
    available_combined_height = (
        label_top
        - physical_bottom
        - PROMO_BOTTOM_POINTS
        - PROMO_GAP_POINTS
        - gaps_height
    )
    if available_combined_height <= 0:
        raise RuntimeError("Ruang resi habis oleh gambar pemberitahuan unboxing")

    total_source_height = sum(height for _, height in crop_heights)
    # Banner mengikuti lebar resi setelah diperkecil. Karena tinggi banner juga
    # ikut berubah bersama skala, masukkan rasio banner dalam perhitungan fit.
    combined_source_height = total_source_height + (max_source_width * promo_aspect)
    scale = min(base_scale, available_combined_height / combined_source_height)
    if scale <= 0:
        raise RuntimeError("Skala gabungan resi tidak valid")

    output_page = PageObject.create_blank_page(
        width=output_width,
        height=output_height,
    )
    cursor_top = label_top
    for index, (source_page, crop_height) in enumerate(crop_heights):
        cropped_page = top_crop(source_page, crop_height)
        rendered_width = float(cropped_page.mediabox.width) * scale
        rendered_height = crop_height * scale
        horizontal_space = max(0, PAPER_WIDTH_POINTS - rendered_width)
        left_offset = physical_left + min(LABEL_RIGHT_SHIFT_POINTS, horizontal_space)
        bottom_offset = cursor_top - rendered_height
        output_page.merge_transformed_page(
            cropped_page,
            Transformation().scale(scale, scale).translate(left_offset, bottom_offset),
            expand=False,
        )
        cursor_top = bottom_offset
        if index < len(crop_heights) - 1:
            cursor_top -= PAGE_GAP_POINTS

    promo_width = max_source_width * scale
    promo_height = promo_width * promo_aspect
    promo_x = physical_left + min(
        LABEL_RIGHT_SHIFT_POINTS,
        max(0, PAPER_WIDTH_POINTS - promo_width),
    )
    promo_y = cursor_top - PROMO_GAP_POINTS - promo_height
    promo_bottom = physical_bottom + PROMO_BOTTOM_POINTS
    if promo_y < promo_bottom - 0.1:
        raise RuntimeError("Gabungan resi dan gambar melebihi tinggi kertas 182 mm")
    promo_y = max(promo_bottom, promo_y)
    output_page.merge_page(
        promo_overlay(
            promo_image,
            promo_x,
            promo_y,
            promo_width,
            promo_height,
            output_width,
            output_height,
        )
    )
    writer = PdfWriter()
    writer.add_page(output_page)
    with open(output_path, "wb") as output:
        writer.write(output)


if __name__ == "__main__":
    if len(sys.argv) not in (3, 4, 5):
        raise SystemExit(
            "usage: prepare_label_pdf.py source.pdf output.pdf [top_margin_mm] [custom|letter|b6]"
        )
    prepare_label(
        sys.argv[1],
        sys.argv[2],
        float(sys.argv[3]) if len(sys.argv) >= 4 else DEFAULT_TOP_MARGIN_MM,
        sys.argv[4] if len(sys.argv) == 5 else "custom",
    )
