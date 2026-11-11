"""Read page dimensions for the WF-C5390 usage counter; never modifies a PDF."""
import json
import sys
from pypdf import PdfReader

reader = PdfReader(sys.argv[1])
if reader.is_encrypted:
    raise RuntimeError("PDF terenkripsi tidak didukung")
sizes = []
for page in reader.pages:
    box = page.mediabox
    sizes.append(sorted([round(float(box.width) * 25.4 / 72, 2),
                         round(float(box.height) * 25.4 / 72, 2)]))
print(json.dumps({"sizes": sizes}))
