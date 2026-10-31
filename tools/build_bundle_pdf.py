"""Build a print bundle from the exact page ranges of its component mappings."""
import json
import sys
from pathlib import Path

from pypdf import PdfReader, PdfWriter


def build(components, output):
    writer = PdfWriter()
    counts = []
    for component in components:
        reader = PdfReader(component["file_path"])
        start = int(component["page_from"])
        end = int(component["page_to"]) or len(reader.pages)
        if not 1 <= start <= end <= len(reader.pages):
            raise ValueError(f"Invalid page range: {component['sku_id']}")
        count = end - start + 1
        for _ in range(int(component["copies"])):
            for index in range(start - 1, end):
                writer.add_page(reader.pages[index])
            # Each workbook must begin on the front of a new sheet in duplex mode.
            if count % 2:
                page = reader.pages[end - 1]
                writer.add_blank_page(width=page.mediabox.width, height=page.mediabox.height)
        counts.append({"sku": component["sku_id"], "pages": count})
    with Path(output).open("wb") as stream:
        writer.write(stream)
    return {"pages": len(writer.pages), "components": counts}


if __name__ == "__main__":
    print(json.dumps(build(json.loads(Path(sys.argv[1]).read_text(encoding="utf-8")), sys.argv[2])))
