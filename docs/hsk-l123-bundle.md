# HSK 2 L1+L2+L3 bundle

The local SKU `WHSK2L1+L2+L3A5A5` represents Shopee variant
`48659135764:326630374010`, named `Paket L1+L2+L3,A5 (20 Lubang)`.
The marketplace variant SKU remains unchanged (empty). Its explicit
`shopee:` mapping alias lets `ShopeeSkuIdentity` resolve future syncs to the
local bundle SKU without assigning the shared parent SKU to this bundle.

The bundle PDF contains the mapped print ranges of `WHSK2L1A5A5`,
`WHSK2L2A5A5`, and `WHSK2L3A5A5`, in that order: 40 + 40 + 86 = 166 pages.
It uses A5, long-edge duplex, one full bundle per purchased unit, and the
components' existing printer. Preview, individual print, and order print use
the ordinary PDF mapping flow. Inventory treats this as its own bundle SKU;
it does not deduct separate component stock.

Preview or rebuild after component PDFs/page ranges change:

```powershell
C:\xampp\php\php.exe tools/register-hsk-bundle.php
C:\xampp\php\php.exe tools/register-hsk-bundle.php --apply
```

The command backs up the previous mapping, alias, and matching order lines to
`storage/bundles` before applying. It updates only the matching variant's
item keys and preserves printed flags/history. Generated PDFs and backups
are runtime data excluded from Git; rerun the command when restoring to a
different installation. It does not change marketplace listings or queue jobs.

Validation:

```powershell
storage/runtime/python-full/python.exe tests/build_bundle_pdf_test.py
C:\xampp\php\php.exe tests/ShopeeSkuIdentityTest.php
```
