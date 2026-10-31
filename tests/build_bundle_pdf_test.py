import importlib.util
import tempfile
import unittest
from pathlib import Path

from pypdf import PdfReader, PdfWriter

spec = importlib.util.spec_from_file_location("bundle", Path(__file__).resolve().parents[1] / "tools/build_bundle_pdf.py")
bundle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bundle)


class BundleTest(unittest.TestCase):
    def test_ranges_order_copies_and_duplex_boundaries(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "source.pdf"
            output = Path(directory) / "bundle.pdf"
            writer = PdfWriter()
            for width in [100, 200, 300, 400]:
                writer.add_blank_page(width=width, height=500)
            writer.write(source)
            components = [
                dict(sku_id="L1", file_path=str(source), page_from=2, page_to=3, copies=1),
                dict(sku_id="L2", file_path=str(source), page_from=4, page_to=0, copies=2),
                dict(sku_id="L3", file_path=str(source), page_from=1, page_to=2, copies=1),
            ]
            result = bundle.build(components, output)
            self.assertEqual(result["pages"], 8)
            self.assertEqual([int(p.mediabox.width) for p in PdfReader(output).pages], [200, 300, 400, 400, 400, 400, 100, 200])
            components[0]["page_to"] = 5
            with self.assertRaises(ValueError):
                bundle.build(components, output)


if __name__ == "__main__":
    unittest.main()
