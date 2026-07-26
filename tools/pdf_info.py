import json
import sys
from pypdf import PdfReader

reader = PdfReader(sys.argv[1])
if reader.is_encrypted:
    raise RuntimeError("PDF terenkripsi tidak didukung")
print(json.dumps({"pages": len(reader.pages)}))
