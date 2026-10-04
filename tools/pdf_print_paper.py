"""Determine the paper size of the selected PDF pages without resizing them."""
import json
import re
import sys
from pypdf import PdfReader


def selected_paper(path, settings):
    reader = PdfReader(path)
    if reader.is_encrypted:
        raise ValueError('Encrypted PDF is not supported')
    tokens = settings.lower().split(',')
    selected = list(range(1, len(reader.pages) + 1))
    for token in tokens:
        match = re.fullmatch(r'(\d+)(?:-(\d*))?', token.strip())
        if match:
            start = int(match[1])
            end = int(match[2]) if match[2] else (len(reader.pages) if '-' in token else start)
            selected = list(range(start, min(end, len(reader.pages)) + 1))
            break
    if 'odd' in tokens:
        selected = [n for n in selected if n % 2]
    elif 'even' in tokens:
        selected = [n for n in selected if not n % 2]
    sizes = set()
    for number in selected:
        page = reader.pages[number - 1]
        dimensions = sorted(float(v) * float(page.get('/UserUnit', 1)) * 25.4 / 72
                            for v in (page.mediabox.width, page.mediabox.height))
        paper = next((name for name, w, h in [('A4', 210, 297), ('A5', 148, 210),
                     ('A6', 105, 148), ('B5', 182, 257), ('Letter', 215.9, 279.4)]
                     if abs(dimensions[0] - w) <= 1 and abs(dimensions[1] - h) <= 1), None)
        if paper is None:
            raise ValueError(f'Unsupported PDF paper size: {dimensions}')
        sizes.add(paper)
    if len(sizes) != 1:
        raise ValueError('Selected PDF pages must use one paper size; print mixed sizes separately')
    return sizes.pop()


if __name__ == '__main__':
    print(json.dumps({'paper': selected_paper(sys.argv[1], sys.argv[2])}))
