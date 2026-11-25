-- Seed bổ sung 32 sản phẩm cho bản TUẦN 1 (schema có cột quantity, unit_price).
-- Cộng với 3 sản phẩm mẫu trong init.sql => 35 sản phẩm: Laptop 12, Điện thoại 12, Phụ kiện 11.
-- Có 3 cặp tên trùng nhau (SKU khác nhau) để thử thứ tự ổn định khi phân trang.
-- Chạy TRƯỚC khi nâng cấp lên tuần 2 (migration sẽ chuyển quantity/unit_price sang kho và lịch sử giá).
-- Chạy 1 lần:
--   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"' < seed_35_products_week1.sql

SET NAMES utf8mb4;

SET @laptop = 1;  -- id danh mục Laptop
SET @phone  = 2;  -- id danh mục Điện thoại
SET @acc    = 3;  -- id danh mục Phụ kiện

INSERT INTO products (category_id, name, sku, description, quantity, unit_price, image_path, status) VALUES
-- Laptop (11)
(@laptop, 'Laptop Dell Inspiron 15',   'LAP-DELL-002', 'Bản RAM 16GB',            8, 16900000, NULL, 1),
(@laptop, 'Laptop Dell Vostro 14',     'LAP-DELL-003', 'Dòng doanh nghiệp nhỏ',   6, 14200000, NULL, 1),
(@laptop, 'Laptop HP Pavilion 15',     'LAP-HP-001',   'Màn hình 15.6 inch',      9, 15800000, NULL, 1),
(@laptop, 'Laptop HP 240 G9',          'LAP-HP-002',   'Máy văn phòng',          12, 12500000, NULL, 1),
(@laptop, 'Laptop Lenovo IdeaPad 3',   'LAP-LEN-001',  'Sinh viên',              15, 11900000, NULL, 1),
(@laptop, 'Laptop Lenovo ThinkPad E14','LAP-LEN-002',  'Bền, bàn phím tốt',       5, 19800000, NULL, 1),
(@laptop, 'Laptop Asus Vivobook 15',   'LAP-ASU-001',  'Mỏng nhẹ',               11, 13400000, NULL, 1),
(@laptop, 'Laptop Asus TUF Gaming F15','LAP-ASU-002',  'Chơi game tầm trung',     4, 22500000, NULL, 1),
(@laptop, 'Laptop Acer Aspire 5',      'LAP-ACE-001',  'Học tập văn phòng',      10, 12900000, NULL, 1),
(@laptop, 'Laptop MacBook Air M1',     'LAP-APP-001',  'Hàng trưng bày',          3, 18500000, NULL, 1),
(@laptop, 'Laptop MSI Modern 14',      'LAP-MSI-001',  'Văn phòng cao cấp',       7, 14700000, NULL, 0),
-- Điện thoại (11)
(@phone,  'iPhone Demo 128GB',         'PHONE-IP-002', 'Màu đen',                 6, 21900000, NULL, 1),
(@phone,  'iPhone 13 128GB',           'PHONE-IP-003', 'Hàng chính hãng',         7, 14900000, NULL, 1),
(@phone,  'iPhone 15 256GB',           'PHONE-IP-004', 'Mới 100%',                4, 24900000, NULL, 1),
(@phone,  'Samsung Galaxy S23',        'PHONE-SS-001', 'Bản 8/256GB',             9, 17900000, NULL, 1),
(@phone,  'Samsung Galaxy A54',        'PHONE-SS-002', 'Tầm trung',              14,  8900000, NULL, 1),
(@phone,  'Samsung Galaxy A15',        'PHONE-SS-003', 'Giá rẻ',                 20,  4500000, NULL, 1),
(@phone,  'Xiaomi Redmi Note 12',      'PHONE-XM-001', 'Pin trâu',               18,  4900000, NULL, 1),
(@phone,  'Xiaomi 13T',                'PHONE-XM-002', 'Camera Leica',            5, 12500000, NULL, 1),
(@phone,  'OPPO Reno 10',              'PHONE-OP-001', 'Chụp chân dung',          8,  9900000, NULL, 1),
(@phone,  'Realme C55',                'PHONE-RM-001', 'Giá rẻ',                 16,  4200000, NULL, 1),
(@phone,  'Nokia G22',                 'PHONE-NK-001', 'Dễ sửa chữa',             0,  3500000, NULL, 0),
-- Phụ kiện (10)
(@acc,    'Chuột không dây Logitech',  'ACC-MOUSE-002','Màu xám',                25,   350000, NULL, 1),
(@acc,    'Chuột Logitech MX Master 3','ACC-MOUSE-003','Cao cấp',                 6,  2300000, NULL, 1),
(@acc,    'Bàn phím cơ Keychron K2',   'ACC-KEY-001',  'Switch brown',            9,  1900000, NULL, 1),
(@acc,    'Bàn phím Logitech K380',    'ACC-KEY-002',  'Bluetooth đa thiết bị',  22,   650000, NULL, 1),
(@acc,    'Tai nghe Sony WH-CH520',    'ACC-HEAD-001', 'Bluetooth',              13,  1100000, NULL, 1),
(@acc,    'Tai nghe AirPods Pro 2',    'ACC-HEAD-002', 'Chống ồn',                5,  5200000, NULL, 1),
(@acc,    'Sạc nhanh Anker 65W',       'ACC-CHG-001',  '2 cổng USB-C',           30,   750000, NULL, 1),
(@acc,    'Cáp USB-C 1m',              'ACC-CAB-001',  'Sạc và truyền dữ liệu',  50,    90000, NULL, 1),
(@acc,    'Balo laptop 15.6 inch',     'ACC-BAG-001',  'Chống nước',             17,   420000, NULL, 1),
(@acc,    'Đế tản nhiệt laptop',       'ACC-STD-001',  '5 quạt',                  0,   280000, NULL, 0);

-- Kiểm tra: phải ra 35 dòng tổng, chia 12 / 12 / 11
SELECT c.name AS category, COUNT(*) AS total
FROM products p JOIN categories c ON c.id = p.category_id
GROUP BY c.id, c.name;
SELECT COUNT(*) AS total_products FROM products;
