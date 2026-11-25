# SQL context for the inventory database

Generate exactly one MySQL 8 SELECT statement. The web application validates the SQL again and caps output at 200 rows. Never request write operations, system tables, user credentials, password hashes, or secrets. Do not select `admins`, `user_permissions` or any RBAC data.
Use explicit table aliases and JOINs with ON. Use only the tables and columns listed below; never invent columns. Do not use EXTRACT; use DATE_FORMAT(column, '%Y-%m') for months.
Current stock is stored only in `stock_balances`: sum `stock_balances.quantity`. Do not infer current stock from documents. Movements are signed: receipts positive, issues negative.
Document values: `inventory_documents.document_type` is 'receipt' or 'issue'; `inventory_documents.status` is 'draft', 'posted' or 'cancelled'. Only posted documents change stock.
Price values: `product_prices.price_type` is 'purchase' or 'sale'; the current price has valid_from <= NOW() and (valid_to IS NULL OR valid_to > NOW()).
Join rules: units are reached only through products.unit_id = units.id (warehouses has NO unit_id and stock_balances has NO unit_id). Warehouses are reached only through stock_balances.location_id = warehouse_locations.id and warehouse_locations.warehouse_id = warehouses.id. warehouses has only id, code, name; warehouse_locations has NO name.

- `categories(id,name,status)` product categories. Từ khóa: danh muc, loai hang, nhom hang, category
- `products(id,category_id,name,sku,description,status,unit_id,min_stock)` product catalog; no stock quantity or price is stored here; min_stock is the minimum stock threshold. Từ khóa: san pham, hang hoa, ma hang, sku, ton toi thieu, muc ton, product
- `units(id,code,name)` base units of measure. Từ khóa: don vi, don vi tinh, cai, hop, goi, unit
- `warehouses(id,code,name)` warehouses. Từ khóa: kho, nha kho, kho hang, warehouse
- `warehouse_locations(id,warehouse_id,code,zone,rack,bin)` storage locations inside a warehouse. Từ khóa: vi tri, khu, ke, bin, rack, location
- `product_lots(id,product_id,code,expires_on)` product lots and expiry dates. Từ khóa: lo, lo hang, han su dung, het han, sap het han, lot, expiry
- `stock_balances(product_id,location_id,lot_id,quantity)` current stock per product, location and lot. Từ khóa: ton, ton kho, so du, con lai, hien co, ton hien tai, stock, balance
- `inventory_documents(id,number,document_type,status,document_date,partner_name,posted_at)` receipt and issue documents. Từ khóa: phieu, chung tu, phieu nhap, phieu xuat, doi tac, ghi so, document
- `inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_price,product_name,sku,unit_name,location_label,lot_code)` immutable document line snapshots; line total is quantity * unit_price. Từ khóa: dong phieu, chi tiet phieu, don gia, thanh tien, tong tien, gia tri, line
- `stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity,created_at)` signed posted movements; filter by created_at for a period. Từ khóa: bien dong, so bien dong, lich su, nhap, xuat, theo thang, trong thang, tong nhap, tong xuat, movement
- `product_prices(id,product_id,price_type,amount,valid_from,valid_to)` time-based purchase and sale prices. Từ khóa: gia, bang gia, gia nhap, gia ban, gia xuat, hieu luc, price
