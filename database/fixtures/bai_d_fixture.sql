-- BÀI D: dữ liệu mẫu nhập / xuất / đối soát. Chạy được nhiều lần (không nhân đôi).
--  - 2 kho: MAIN (có sẵn) + KHO2; mỗi kho >= 2 vị trí.
--  - 2 sản phẩm: DS-A (2 lô: LOT-A1 còn hạn, LOT-A2 ĐÃ HẾT HẠN 15/11/2026) và DS-B (lô LOT-B1).
--  - Có giá nhập / giá xuất hiệu lực từ 01/01/2026 (vô hạn) để lập phiếu được.
--  - KHÔNG có tồn đầu: mọi số dư sẽ do phiếu nhập ghi sổ tạo ra.
SET NAMES utf8mb4;
SET @admin = (SELECT id FROM admins WHERE username='admin' LIMIT 1);
SET @cat   = (SELECT MIN(id) FROM categories);
SET @unit  = (SELECT id FROM units WHERE code='CAI' LIMIT 1);

-- Kho và vị trí
INSERT INTO warehouses (code, name) SELECT 'KHO2', 'Kho phụ' WHERE NOT EXISTS (SELECT 1 FROM warehouses WHERE code='KHO2');
INSERT INTO warehouse_locations (warehouse_id, code, zone, rack, bin)
SELECT w.id, v.code, v.zone, v.rack, v.bin FROM warehouses w
JOIN (
  SELECT 'MAIN' AS wcode, 'A2' AS code, 'Khu A' AS zone, 'Kệ 02' AS rack, 'Ô 01' AS bin UNION ALL
  SELECT 'KHO2', 'B1', 'Khu B', 'Kệ 01', 'Ô 01' UNION ALL
  SELECT 'KHO2', 'B2', 'Khu B', 'Kệ 02', 'Ô 01'
) v ON v.wcode = w.code
WHERE NOT EXISTS (SELECT 1 FROM warehouse_locations l WHERE l.warehouse_id=w.id AND l.code=v.code);

-- Sản phẩm
INSERT INTO products (category_id, name, sku, description, status, unit_id, min_stock)
SELECT @cat, v.name, v.sku, v.descr, 1, @unit, 0 FROM (
  SELECT 'DS-A' AS sku, 'Đối soát A (2 lô)' AS name, 'Có lô còn hạn và lô hết hạn' AS descr UNION ALL
  SELECT 'DS-B', 'Đối soát B', 'Sản phẩm thứ hai'
) v WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.sku=v.sku);

-- Lô (NOLOT do hệ thống tạo cho sản phẩm mới; ở đây tạo thêm lô có tên)
INSERT INTO product_lots (product_id, code, expires_on)
SELECT p.id, v.code, v.expires_on FROM (
  SELECT 'DS-A' AS sku, 'LOT-A1' AS code, '2030-12-31' AS expires_on UNION ALL
  SELECT 'DS-A', 'LOT-A2', '2026-11-15' UNION ALL
  SELECT 'DS-B', 'LOT-B1', '2030-12-31'
) v JOIN products p ON p.sku=v.sku
WHERE NOT EXISTS (SELECT 1 FROM product_lots l WHERE l.product_id=p.id AND l.code=v.code);

-- Giá: nhập 10.000, xuất 15.000 (hiệu lực từ 01/01/2026, vô hạn)
INSERT INTO product_prices (product_id, price_type, amount, valid_from, valid_to, created_by)
SELECT p.id, t.price_type, t.amount, '2026-01-01 00:00:00', NULL, @admin
FROM products p JOIN (SELECT 'purchase' AS price_type, 10000 AS amount UNION ALL SELECT 'sale', 15000) t
WHERE p.sku IN ('DS-A','DS-B')
  AND NOT EXISTS (SELECT 1 FROM product_prices x WHERE x.product_id=p.id);

SELECT w.code AS kho, l.code AS vi_tri FROM warehouses w JOIN warehouse_locations l ON l.warehouse_id=w.id ORDER BY w.code, l.code;
SELECT p.sku, l.code AS lo, l.expires_on FROM products p JOIN product_lots l ON l.product_id=p.id WHERE p.sku IN ('DS-A','DS-B') ORDER BY p.sku, l.code;
SELECT p.sku, pp.price_type, pp.amount FROM product_prices pp JOIN products p ON p.id=pp.product_id WHERE p.sku IN ('DS-A','DS-B') ORDER BY p.sku, pp.price_type;
