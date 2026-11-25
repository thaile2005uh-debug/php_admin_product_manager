-- Import once after database/init.sql (or migrations 001 + 002).
-- Creates 500 catalog items, 500 lots, 1,000 temporal prices, and 600 ledger rows.
-- Safe guard: refuses to run if SKU DEMO-AI-0001 already exists.
USE admin_product_db;
CREATE TEMPORARY TABLE demo_import_guard (ok TINYINT NOT NULL CHECK(ok=1));
INSERT INTO demo_import_guard
SELECT NOT EXISTS(SELECT 1 FROM products WHERE sku='DEMO-AI-0001');

INSERT INTO categories(name,description,status)
VALUES ('Demo AI · Văn phòng','Dữ liệu mẫu cho truy vấn AI',1),
       ('Demo AI · Thiết bị','Dữ liệu mẫu cho báo cáo',1);
INSERT INTO units(code,name) VALUES ('DEMO-BOX','Hộp'),('DEMO-PACK','Gói');
INSERT INTO warehouses(code,name) VALUES ('DEMO-NORTH','Kho demo miền Bắc'),('DEMO-SOUTH','Kho demo miền Nam');
INSERT INTO warehouse_locations(warehouse_id,code,zone,rack,bin)
SELECT w.id,x.code,x.zone,x.rack,x.bin FROM warehouses w
CROSS JOIN (SELECT 'A-01' code,'A' zone,'R01' rack,'B01' bin UNION ALL
            SELECT 'B-02','B','R02','B02') x
WHERE w.code IN ('DEMO-NORTH','DEMO-SOUTH');

INSERT INTO products(category_id,name,sku,description,status,unit_id,min_stock)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<500)
SELECT IF(MOD(n,2)=0,(SELECT id FROM categories WHERE name='Demo AI · Văn phòng'),(SELECT id FROM categories WHERE name='Demo AI · Thiết bị')),
       CONCAT('Sản phẩm AI Demo ',LPAD(n,4,'0')),CONCAT('DEMO-AI-',LPAD(n,4,'0')),
       CONCAT('Dữ liệu mẫu quy mô lớn · nhóm ',MOD(n,12)+1),1,
       IF(MOD(n,3)=0,(SELECT id FROM units WHERE code='DEMO-BOX'),IF(MOD(n,3)=1,(SELECT id FROM units WHERE code='DEMO-PACK'),(SELECT id FROM units WHERE code='CAI'))),
       MOD(n,20)+5 FROM seq;

INSERT INTO product_lots(product_id,code,expires_on)
SELECT id,CONCAT('LOT-DEMO-',SUBSTRING(sku,9)),
       IF(MOD(CAST(SUBSTRING(sku,9) AS UNSIGNED),5)=0,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 365 DAY))
FROM products WHERE sku LIKE 'DEMO-AI-%';

INSERT INTO product_prices(product_id,price_type,amount,valid_from,created_by)
SELECT p.id,t.price_type,
       IF(t.price_type='purchase',15000+CAST(SUBSTRING(p.sku,9) AS UNSIGNED)*725,22000+CAST(SUBSTRING(p.sku,9) AS UNSIGNED)*1190),
       '2020-01-01 00:00:00',(SELECT id FROM admins WHERE username='admin' LIMIT 1)
FROM products p CROSS JOIN (SELECT 'purchase' price_type UNION ALL SELECT 'sale') t
WHERE p.sku LIKE 'DEMO-AI-%';

INSERT INTO inventory_documents(number,document_type,status,document_date,partner_name,note,created_by,posted_by,posted_at)
SELECT CONCAT('DEMO-AI-IN-',DATE_FORMAT(NOW(),'%Y%m%d%H%i%s')),'receipt','posted',NOW(),
       'Nhà cung cấp demo','Phiếu nhập mẫu sinh tự động',id,id,NOW()
FROM admins WHERE username='admin' LIMIT 1;
SET @demo_receipt_id=LAST_INSERT_ID();
INSERT INTO inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,product_name,sku,unit_name,location_label,lot_code)
SELECT @demo_receipt_id,p.id,loc.id,l.id,MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),80)+20,p.unit_id,pp.amount,pp.id,p.name,p.sku,u.name,
       CONCAT(w.code,' / ',loc.code),l.code
FROM products p JOIN product_lots l ON l.product_id=p.id AND l.code=CONCAT('LOT-DEMO-',SUBSTRING(p.sku,9))
JOIN units u ON u.id=p.unit_id JOIN product_prices pp ON pp.product_id=p.id AND pp.price_type='purchase'
JOIN warehouses w ON w.code=IF(MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),2)=0,'DEMO-NORTH','DEMO-SOUTH')
JOIN warehouse_locations loc ON loc.warehouse_id=w.id AND loc.code=IF(MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),2)=0,'A-01','B-02')
WHERE p.sku LIKE 'DEMO-AI-%';
INSERT INTO stock_balances(product_id,location_id,lot_id,quantity)
SELECT product_id,location_id,lot_id,quantity FROM inventory_lines WHERE document_id=@demo_receipt_id;
INSERT INTO stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity)
SELECT document_id,id,product_id,location_id,lot_id,quantity FROM inventory_lines WHERE document_id=@demo_receipt_id;

INSERT INTO inventory_documents(number,document_type,status,document_date,partner_name,note,created_by,posted_by,posted_at)
SELECT CONCAT('DEMO-AI-OUT-',DATE_FORMAT(NOW(),'%Y%m%d%H%i%s')),'issue','posted',NOW(),
       'Khách hàng demo','Phiếu xuất mẫu; mỗi sản phẩm xuất một đơn vị',id,id,NOW()
FROM admins WHERE username='admin' LIMIT 1;
SET @demo_issue_id=LAST_INSERT_ID();
INSERT INTO inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,product_name,sku,unit_name,location_label,lot_code)
SELECT @demo_issue_id,p.id,loc.id,l.id,1,p.unit_id,pp.amount,pp.id,p.name,p.sku,u.name,CONCAT(w.code,' / ',loc.code),l.code
FROM products p JOIN product_lots l ON l.product_id=p.id AND l.code=CONCAT('LOT-DEMO-',SUBSTRING(p.sku,9))
JOIN units u ON u.id=p.unit_id JOIN product_prices pp ON pp.product_id=p.id AND pp.price_type='sale'
JOIN warehouses w ON w.code=IF(MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),2)=0,'DEMO-NORTH','DEMO-SOUTH')
JOIN warehouse_locations loc ON loc.warehouse_id=w.id AND loc.code=IF(MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),2)=0,'A-01','B-02')
WHERE p.sku LIKE 'DEMO-AI-%' AND MOD(CAST(SUBSTRING(p.sku,9) AS UNSIGNED),5)=0;
UPDATE stock_balances b JOIN inventory_lines l ON l.product_id=b.product_id AND l.location_id=b.location_id AND l.lot_id=b.lot_id
SET b.quantity=b.quantity-l.quantity WHERE l.document_id=@demo_issue_id;
INSERT INTO stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity)
SELECT document_id,id,product_id,location_id,lot_id,-quantity FROM inventory_lines WHERE document_id=@demo_issue_id;
DROP TEMPORARY TABLE demo_import_guard;

-- Expected scale: 500 products; 600 posted lines and signed movements; 500 positive balances.
SELECT COUNT(*) AS demo_products FROM products WHERE sku LIKE 'DEMO-AI-%';
SELECT COUNT(*) AS demo_movements FROM stock_movements m JOIN products p ON p.id=m.product_id WHERE p.sku LIKE 'DEMO-AI-%';
