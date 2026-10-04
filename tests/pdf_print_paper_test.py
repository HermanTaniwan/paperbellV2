import importlib.util
import tempfile
import unittest
from pathlib import Path
from reportlab.pdfgen.canvas import Canvas

spec = importlib.util.spec_from_file_location('paper', Path(__file__).resolve().parents[1] / 'tools/pdf_print_paper.py')
paper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(paper)


class PaperTest(unittest.TestCase):
    def test_selected_pages_and_landscape(self):
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'mixed.pdf')
            canvas = Canvas(path)
            for w, h in [(210, 297), (210.1, 147.9), (148, 210)]:
                canvas.setPageSize((w * 72 / 25.4, h * 72 / 25.4))
                canvas.drawString(10, 10, 'fixture')
                canvas.showPage()
            canvas.save()
            self.assertEqual(paper.selected_paper(path, '2-3,noscale'), 'A5')
            self.assertEqual(paper.selected_paper(path, '1'), 'A4')
            self.assertEqual(paper.selected_paper(path, '1-3,even'), 'A5')
            with self.assertRaisesRegex(ValueError, 'one paper size'):
                paper.selected_paper(path, '1-3')


if __name__ == '__main__':
    unittest.main()
