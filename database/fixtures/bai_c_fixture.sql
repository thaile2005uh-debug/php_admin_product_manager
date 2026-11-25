-- BÀI C (bản 2): dữ liệu giá quá khứ, nhiều trường hợp, TẤT CẢ bắt đầu từ 10/11/2026.
-- Khoảng giá [valid_from, valid_to): gồm đầu, không gồm cuối. Hôm nay là 22/11/2026.
-- Dùng SKU riêng (GIA-...), không đụng bản fixture cũ. Chạy được nhiều lần (không nhân đôi).
SET NAMES utf8mb4;
SET @admin = (SELECT id FROM admins WHERE username='admin' LIMIT 1);
SET @cat   = (SELECT MIN(id) FROM categories);
SET @unit  = (SELECT id FROM units WHERE code='CAI' LIMIT 1);

INSERT INTO products (category_id, name, sku, description, status, unit_id, min_stock)
SELECT @cat, v.name, v.sku, v.descr, 1, @unit, 0
FROM (
  SELECT 'GIA-TANG' AS sku, 'Thử giá: tăng dần' AS name, 'Giá xuất tăng 3 lần, giá nhập 2 mức' AS descr UNION ALL
  SELECT 'GIA-GIAM',        'Thử giá: giảm giá',        'Giá xuất giảm lần 2' UNION ALL
  SELECT 'GIA-KHOANGTRONG', 'Thử giá: có khoảng trống', 'Giá xuất có lỗ hổng 13/11-16/11' UNION ALL
  SELECT 'GIA-KHONG',       'Thử giá: có giá 0 đồng',   'Giá 0 cấu hình rõ' UNION ALL
  SELECT 'GIA-MO',          'Thử giá: khoảng đang mở',  'Giá vô hạn, chặn giá kế tiếp' UNION ALL
  SELECT 'GIA-TRONG',       'Thử giá: chưa có giá nào', 'Không có dòng giá'
) v
WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.sku=v.sku);

INSERT INTO product_lots (product_id, code)
SELECT p.id, 'NOLOT' FROM products p
WHERE p.sku LIKE 'GIA-%' AND NOT EXISTS (SELECT 1 FROM product_lots l WHERE l.product_id=p.id AND l.code='NOLOT');

INSERT INTO product_prices (product_id, price_type, amount, valid_from, valid_to, created_by)
SELECT p.id, v.price_type, v.amount, v.valid_from, v.valid_to, @admin
FROM (
  -- GIA-TANG: giá xuất tăng dần, mức cuối đang mở; giá nhập 2 mức rồi hết giá từ 22/11
  SELECT 'GIA-TANG' AS sku,'sale' AS price_type,100000 AS amount,'2026-11-10 00:00:00' AS valid_from,'2026-11-14 00:00:00' AS valid_to UNION ALL
  SELECT 'GIA-TANG','sale',110000,'2026-11-14 00:00:00','2026-11-18 00:00:00' UNION ALL
  SELECT 'GIA-TANG','sale',125000,'2026-11-18 00:00:00','2026-11-22 00:00:00' UNION ALL
  SELECT 'GIA-TANG','sale',140000,'2026-11-22 00:00:00',NULL UNION ALL
  SELECT 'GIA-TANG','purchase',70000,'2026-11-10 00:00:00','2026-11-16 00:00:00' UNION ALL
  SELECT 'GIA-TANG','purchase',85000,'2026-11-16 00:00:00','2026-11-22 00:00:00' UNION ALL
  -- GIA-GIAM: giá giảm
  SELECT 'GIA-GIAM','sale',200000,'2026-11-10 00:00:00','2026-11-15 00:00:00' UNION ALL
  SELECT 'GIA-GIAM','sale',180000,'2026-11-15 00:00:00','2026-11-20 00:00:00' UNION ALL
  SELECT 'GIA-GIAM','purchase',150000,'2026-11-10 00:00:00','2026-11-20 00:00:00' UNION ALL
  -- GIA-KHOANGTRONG: lỗ hổng giá xuất 13/11 - 16/11
  SELECT 'GIA-KHOANGTRONG','sale',50000,'2026-11-10 00:00:00','2026-11-13 00:00:00' UNION ALL
  SELECT 'GIA-KHOANGTRONG','sale',60000,'2026-11-16 00:00:00','2026-11-20 00:00:00' UNION ALL
  SELECT 'GIA-KHOANGTRONG','purchase',40000,'2026-11-10 00:00:00','2026-11-20 00:00:00' UNION ALL
  -- GIA-KHONG: giá 0 rồi lên 30.000
  SELECT 'GIA-KHONG','sale',0,'2026-11-10 00:00:00','2026-11-15 00:00:00' UNION ALL
  SELECT 'GIA-KHONG','sale',30000,'2026-11-15 00:00:00','2026-11-20 00:00:00' UNION ALL
  SELECT 'GIA-KHONG','purchase',0,'2026-11-10 00:00:00','2026-11-20 00:00:00' UNION ALL
  -- GIA-MO: cả hai loại giá đang mở vô hạn từ 10/11
  SELECT 'GIA-MO','sale',75000,'2026-11-10 00:00:00',NULL UNION ALL
  SELECT 'GIA-MO','purchase',60000,'2026-11-10 00:00:00',NULL
  -- GIA-TRONG: cố ý không có dòng giá nào
) v
JOIN products p ON p.sku=v.sku
WHERE NOT EXISTS (SELECT 1 FROM product_prices x WHERE x.product_id=p.id);

SELECT p.sku, pp.price_type, pp.amount, pp.valid_from, pp.valid_to
FROM products p LEFT JOIN product_prices pp ON pp.product_id=p.id
WHERE p.sku LIKE 'GIA-%' ORDER BY p.sku, pp.price_type, pp.valid_from;
