import sys

from pypdf import PdfReader, PdfWriter, Transformation


# Shipping labels are placed on A6 and kept at the top-left, matching the
# desktop application's layout. 80% keeps the label comfortably inside the
# printer margins while making text and barcodes noticeably easier to read.
A6_WIDTH_POINTS = 105 / 25.4 * 72
A6_HEIGHT_POINTS = 148 / 25.4 * 72
LABEL_SCALE = 0.72
LABEL_RIGHT_SHIFT_POINTS = 5 / 25.4 * 72


def prepare_label(source_path: str, output_path: str) -> None:
    reader = PdfReader(source_path)
    if reader.is_encrypted:
        raise RuntimeError("PDF label terenkripsi tidak didukung")

    writer = PdfWriter()
    for source_page in reader.pages:
        if source_page.rotation:
            source_page.transfer_rotation_to_content()

        source_width = float(source_page.mediabox.width)
        source_height = float(source_page.mediabox.height)
        if source_width <= 0 or source_height <= 0:
            raise RuntimeError("Ukuran halaman PDF label tidak valid")

        fit = min(A6_WIDTH_POINTS / source_width, A6_HEIGHT_POINTS / source_height)
        scale = fit * LABEL_SCALE
        rendered_width = source_width * scale
        rendered_height = source_height * scale
        # Most desktop printers have an unprintable strip along the left edge.
        # Keep the label near the top, but move it 5 mm right so barcodes and
        # text do not start at x=0. Clamp the shift for unusually wide PDFs.
        horizontal_space = max(0, A6_WIDTH_POINTS - rendered_width)
        left_offset = min(LABEL_RIGHT_SHIFT_POINTS, horizontal_space)
        top_offset = A6_HEIGHT_POINTS - rendered_height

        target_page = writer.add_blank_page(A6_WIDTH_POINTS, A6_HEIGHT_POINTS)
        transform = Transformation().scale(scale, scale).translate(left_offset, top_offset)
        target_page.merge_transformed_page(source_page, transform, expand=False)

    if not writer.pages:
        raise RuntimeError("PDF label tidak memiliki halaman")

    with open(output_path, "wb") as output:
        writer.write(output)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("usage: prepare_label_pdf.py source.pdf output.pdf")
    prepare_label(sys.argv[1], sys.argv[2])
