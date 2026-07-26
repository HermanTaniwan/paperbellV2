import json
import os
import random
import sys
from pypdf import PdfReader, PdfWriter

if len(sys.argv) != 3:
    raise SystemExit("usage: random_pages.py request.json output.pdf")

with open(sys.argv[1], encoding="utf-8") as handle:
    request = json.load(handle)

mode = request.get("mode", "planner")
count = max(1, int(request.get("count", 1)))
paths = list(dict.fromkeys(p for p in request.get("paths", []) if os.path.isfile(p)))
random.shuffle(paths)
readers = []
errors = []
minimum = 2 if mode == "loose" else 1
for path in paths:
    try:
        reader = PdfReader(path)
        if len(reader.pages) >= minimum and not reader.is_encrypted:
            readers.append((path, reader))
    except Exception as exc:
        errors.append(f"{os.path.basename(path)}: {exc}")

if not readers:
    raise RuntimeError("Tidak ada PDF valid dalam pool Random Pages.")

picked = []
while len(picked) < count:
    batch = readers[:]
    random.shuffle(batch)
    picked.extend(batch[: count - len(picked)])
random.shuffle(picked)

writer = PdfWriter()
summary = []
for path, reader in picked:
    if mode == "loose":
        first = random.randrange(0, len(reader.pages) // 2) * 2
        writer.add_page(reader.pages[first])
        writer.add_page(reader.pages[first + 1])
        summary.append(f"{os.path.splitext(os.path.basename(path))[0]} pg.{first + 1}+{first + 2}")
    else:
        page = random.randrange(0, len(reader.pages))
        writer.add_page(reader.pages[page])
        summary.append(f"{os.path.splitext(os.path.basename(path))[0]} pg.{page + 1}")

with open(sys.argv[2], "wb") as handle:
    writer.write(handle)
print(json.dumps({"pages": len(writer.pages), "summary": " | ".join(summary), "skipped": errors}, ensure_ascii=False))
