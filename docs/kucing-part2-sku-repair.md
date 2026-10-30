# Kucing Part 2 SKU and thumbnail repair — 2026-10-30

Audited all 72 live Shopee variations of item `57215609713` against Paperbell's mapping names and existing PDF files. Eight seller SKU codes were incorrect. Three Kucing C grid variations shared Kucing D's codes, which made inventory thumbnails depend on whichever variation appeared in the latest order.

The following full Paperbell SKUs were corrected by updating only the Shopee model SKU suffix (parent SKU remains `LKU2`):

| Variation | Previous full SKU | Correct full SKU |
| --- | --- | --- |
| Kucing 2C-Bread, A5 grid, 20 holes | LKU2DGRIA5H20 | LKU2CGRIA5H20 |
| Kucing 2C-Bread, A5 grid, 6 holes | LKU2DGRIA5H6 | LKU2CGRIA5H6 |
| Kucing 2C-Bread, B5 grid | LKU2DGRIB5 | LKU2CGRIB5 |
| Kucing 2E-TV, A5 dots, 20 holes | LKU2EADOTA5H20 | LKU2EDOTA5H20 |
| Kucing 2E-TV, A5 dots, 6 holes | LKU2EADOTA5H6 | LKU2EDOTA5H6 |
| Kucing 2E-TV, B5 dots | LKU2EADOTB5 | LKU2EDOTB5 |
| Kucing 2E-TV, A5 grid, 6 holes | LKU2EGRIA5H | LKU2EGRIA5H6 |
| Kucing 2F-Blue, A5 grid, 6 holes | LKU2FGRIA5H | LKU2FGRIA5H6 |

`LKU2DGRIA5H20` remains Kucing 2D-Book, A5 grid, 20 holes. Its inventory thumbnail now resolves directly to Shopee model `421335186418`, rather than a historical order with a duplicate SKU.

All 72 reviewed Shopee item/model identities are bound to their canonical mapping through namespaced `mapping_aliases` entries. Order sync uses these bindings to keep historical payloads with old SKU text from overriding the canonical `order_process.item_key`. Original marketplace payloads and SKU fields remain available as source evidence.

Three existing Kucing C order lines (`36834`, `93811`, `95191`) received corrected item keys. All three were already marked printed; print flags, timestamps, quantities, inventory balances and print jobs were preserved. No reprints were submitted. The wrong SKU mapping does not establish which physical sheets were shipped for those historical orders.

The repair tool saves its catalog and line backup under ignored `storage/sku-repairs/` before applying local or marketplace changes. Inspect with:

```powershell
C:\xampp\php\php.exe tools/repair-kucing-part2-skus.php
```

Use `--apply` to apply the reviewed corrections and identity bindings. The operation accepts only the eight known differences, checks the canonical PDF files, and verifies all 72 marketplace model IDs and SKU codes afterward. If local identities are saved but a marketplace request fails, rerun the tool to finish verification; remote and local updates are not one transaction.

Validation: PHP lint; SKU identity and thumbnail regression tests; cancellation and nonblocking order-list tests; live Shopee re-read; HTTP 200 variant thumbnails for D inventory and C order; visual confirmation of the distinct 2D/Book and 2C/Bread images. Browser thumbnail URLs and the app asset version were refreshed to bypass existing cached images.
