-- MySQL 8.0.16+. Run ONCE against the original three-table database.
-- Back up the database first. DDL in MySQL commits automatically.
-- init.sql embeds this migration verbatim after the original sample data.

-- Refuse an empty users table: ownership and price provenance need an actor.
CREATE TEMPORARY TABLE migration_001_preflight (
    has_admin TINYINT NOT NULL,
    CONSTRAINT chk_migration_001_has_admin CHECK (has_admin = 1)
);
INSERT INTO migration_001_preflight(has_admin) SELECT EXISTS(SELECT 1 FROM admins);
DROP TEMPORARY TABLE migration_001_preflight;

-- Fail safely if legacy stock/prices are invalid; correct them before retrying.
ALTER TABLE products
    ADD CONSTRAINT chk_legacy_quantity_nonnegative CHECK(quantity >= 0),
    ADD CONSTRAINT chk_legacy_price_nonnegative CHECK(unit_price >= 0);
ALTER TABLE admins MODIFY password_hash VARCHAR(255) NOT NULL;

CREATE TABLE schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE warehouse_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT NOT NULL,
    code VARCHAR(50) NOT NULL,
    zone VARCHAR(50) NOT NULL DEFAULT '',
    rack VARCHAR(50) NOT NULL DEFAULT '',
    bin VARCHAR(50) NOT NULL DEFAULT '',
    UNIQUE KEY uq_location_code (warehouse_id, code),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO units(code,name) VALUES ('CAI','Cái');
INSERT INTO warehouses(code,name) VALUES ('MAIN','Kho chính');
INSERT INTO warehouse_locations(warehouse_id,code,zone,rack,bin)
SELECT id,'DEFAULT','Khu A','Kệ 01','Ô 01' FROM warehouses WHERE code='MAIN';

ALTER TABLE products
    ADD COLUMN unit_id INT NULL,
    ADD COLUMN min_stock INT NOT NULL DEFAULT 0,
    ADD CONSTRAINT fk_product_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_product_min_stock CHECK (min_stock >= 0);
UPDATE products SET unit_id=(SELECT id FROM units WHERE code='CAI');

CREATE TABLE product_lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    code VARCHAR(100) NOT NULL,
    expires_on DATE NULL,
    UNIQUE KEY uq_product_lot (product_id,code),
    UNIQUE KEY uq_lot_product (id,product_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
INSERT INTO product_lots(product_id,code) SELECT id,'NOLOT' FROM products;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY(user_id,role_id),
    FOREIGN KEY(user_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY(role_id,permission_id),
    FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- UserPermission: one explicit decision per user/function. No row = inherit.
CREATE TABLE user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    effect ENUM('allow','deny') NOT NULL,
    PRIMARY KEY(user_id,permission_id),
    FOREIGN KEY(user_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO permissions(code,name) VALUES
('dashboard.view','Xem dashboard tồn kho'),
('products.view','Xem sản phẩm'),('products.manage','Thêm / sửa / xóa sản phẩm'),
('categories.view','Xem danh mục sản phẩm'),('categories.manage','Quản lý danh mục sản phẩm'),
('inventory.view','Xem tồn kho và sổ kho'),('warehouse.manage','Quản lý kho / vị trí / lô / đơn vị'),
('prices.view','Xem lịch sử giá'),('prices.manage','Thiết lập giá theo thời gian'),
('documents.view','Xem phiếu nhập xuất'),('receipts.create','Lập / hủy phiếu nhập nháp'),
('receipts.post','Ghi sổ phiếu nhập'),('issues.create','Lập / hủy phiếu xuất nháp'),
('issues.post','Ghi sổ phiếu xuất'),('documents.print','In phiếu nhập xuất'),
('access.manage','Quản lý nhóm và quyền người dùng');
INSERT INTO roles(code,name) VALUES
('ADMIN','Quản trị hệ thống'),('STOCK_CLERK','Nhân viên lập phiếu'),
('STOCK_MANAGER','Quản lý kho / duyệt phiếu'),('VIEWER','Người xem báo cáo');
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='ADMIN';
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='STOCK_CLERK' AND p.code IN
('dashboard.view','products.view','categories.view','inventory.view','prices.view',
 'documents.view','receipts.create','issues.create','documents.print');
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='STOCK_MANAGER' AND p.code IN
('dashboard.view','products.view','categories.view','inventory.view','warehouse.manage',
 'prices.view','documents.view','receipts.post','issues.post','documents.print');
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='VIEWER' AND p.code IN ('dashboard.view','products.view','categories.view','inventory.view','documents.view');
INSERT INTO user_roles(user_id,role_id)
SELECT a.id,r.id FROM admins a CROSS JOIN roles r WHERE r.code='ADMIN';

CREATE TABLE product_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_type ENUM('purchase','sale') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_to DATETIME NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_price_amount CHECK(amount >= 0),
    CONSTRAINT chk_price_period CHECK(valid_to IS NULL OR valid_to > valid_from),
    INDEX idx_price_effective(product_id,price_type,valid_from,valid_to),
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY(created_by) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- Keep an exact source snapshot before dropping legacy columns.
CREATE TABLE legacy_product_stock_prices (
    product_id INT PRIMARY KEY,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO legacy_product_stock_prices(product_id,quantity,unit_price)
SELECT id,quantity,unit_price FROM products;
INSERT INTO product_prices(product_id,price_type,amount,valid_from,created_by)
SELECT p.id,t.price_type,p.unit_price,'2000-01-01 00:00:00',a.id
FROM products p CROSS JOIN (SELECT 'purchase' AS price_type UNION ALL SELECT 'sale') t
CROSS JOIN (SELECT id FROM admins ORDER BY id LIMIT 1) a;

CREATE TABLE inventory_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    document_type ENUM('receipt','issue') NOT NULL,
    status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
    document_date DATETIME NOT NULL,
    partner_name VARCHAR(200) NOT NULL,
    note TEXT NULL,
    created_by INT NOT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_date(document_date,id),
    INDEX idx_document_type_status(document_type,status),
    FOREIGN KEY(created_by) REFERENCES admins(id) ON DELETE RESTRICT,
    -- Default NO ACTION keeps the CHECK column out of explicit FK actions.
    FOREIGN KEY(posted_by) REFERENCES admins(id),
    CONSTRAINT chk_document_posted CHECK
        ((status='posted' AND posted_by IS NOT NULL AND posted_at IS NOT NULL)
        OR (status<>'posted' AND posted_by IS NULL AND posted_at IS NULL))
) ENGINE=InnoDB;
CREATE TABLE inventory_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_id INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    price_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) NULL,
    unit_name VARCHAR(100) NOT NULL,
    location_label VARCHAR(255) NOT NULL,
    lot_code VARCHAR(100) NOT NULL,
    INDEX idx_line_document(document_id),
    FOREIGN KEY(document_id) REFERENCES inventory_documents(id) ON DELETE RESTRICT,
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY(location_id) REFERENCES warehouse_locations(id) ON DELETE RESTRICT,
    FOREIGN KEY(lot_id,product_id) REFERENCES product_lots(id,product_id) ON DELETE RESTRICT,
    FOREIGN KEY(unit_id) REFERENCES units(id) ON DELETE RESTRICT,
    FOREIGN KEY(price_id) REFERENCES product_prices(id) ON DELETE RESTRICT,
    CONSTRAINT chk_line_quantity CHECK(quantity > 0),
    CONSTRAINT chk_line_price CHECK(unit_price >= 0)
) ENGINE=InnoDB;
CREATE TABLE stock_balances (
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    PRIMARY KEY(product_id,location_id,lot_id),
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY(location_id) REFERENCES warehouse_locations(id) ON DELETE RESTRICT,
    FOREIGN KEY(lot_id,product_id) REFERENCES product_lots(id,product_id) ON DELETE RESTRICT,
    CONSTRAINT chk_balance_quantity CHECK(quantity >= 0)
) ENGINE=InnoDB;
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    line_id INT NOT NULL UNIQUE,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movement_stock(product_id,location_id,lot_id,id),
    FOREIGN KEY(document_id) REFERENCES inventory_documents(id) ON DELETE RESTRICT,
    FOREIGN KEY(line_id) REFERENCES inventory_lines(id) ON DELETE RESTRICT,
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY(location_id) REFERENCES warehouse_locations(id) ON DELETE RESTRICT,
    FOREIGN KEY(lot_id,product_id) REFERENCES product_lots(id,product_id) ON DELETE RESTRICT,
    CONSTRAINT chk_movement_quantity CHECK(quantity <> 0)
) ENGINE=InnoDB;

-- Opening balance is a real posted receipt, so ledger and balances reconcile.
INSERT INTO inventory_documents(number,document_type,status,document_date,partner_name,note,created_by,posted_by,posted_at)
SELECT 'OPENING-MIGRATION-001','receipt','posted',NOW(),'Số dư đầu kỳ',
       'Chuyển dữ liệu products.quantity; đơn giá cũ dùng làm giá nhập đầu kỳ (giả định cần xác nhận).',id,id,NOW()
FROM admins WHERE EXISTS(SELECT 1 FROM products WHERE quantity>0) ORDER BY id LIMIT 1;
INSERT INTO inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,
    product_name,sku,unit_name,location_label,lot_code)
SELECT d.id,p.id,loc.id,l.id,p.quantity,p.unit_id,pp.amount,pp.id,
       p.name,p.sku,u.name,CONCAT(w.code,' / ',loc.code),l.code
FROM products p
JOIN product_lots l ON l.product_id=p.id AND l.code='NOLOT'
JOIN units u ON u.id=p.unit_id
JOIN product_prices pp ON pp.product_id=p.id AND pp.price_type='purchase'
CROSS JOIN inventory_documents d CROSS JOIN warehouse_locations loc
JOIN warehouses w ON w.id=loc.warehouse_id
WHERE p.quantity>0 AND d.number='OPENING-MIGRATION-001' AND w.code='MAIN' AND loc.code='DEFAULT';
INSERT INTO stock_balances(product_id,location_id,lot_id,quantity)
SELECT product_id,location_id,lot_id,quantity FROM inventory_lines;
INSERT INTO stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity)
SELECT document_id,id,product_id,location_id,lot_id,quantity FROM inventory_lines;

-- Fail before removing source columns if any migrated quantity/price is missing.
CREATE TEMPORARY TABLE migration_001_reconciled (
    stock_ok TINYINT NOT NULL,
    prices_ok TINYINT NOT NULL,
    CONSTRAINT chk_migration_001_stock CHECK(stock_ok = 1),
    CONSTRAINT chk_migration_001_prices CHECK(prices_ok = 1)
);
INSERT INTO migration_001_reconciled(stock_ok,prices_ok)
SELECT NOT EXISTS (
    SELECT 1 FROM legacy_product_stock_prices old
    LEFT JOIN (SELECT product_id,SUM(quantity) AS quantity FROM stock_balances GROUP BY product_id) b
        ON b.product_id=old.product_id
    WHERE old.quantity <> COALESCE(b.quantity,0)
), NOT EXISTS (
    SELECT 1 FROM legacy_product_stock_prices old
    WHERE (SELECT COUNT(*) FROM product_prices pp
        WHERE pp.product_id=old.product_id AND pp.amount=old.unit_price) <> 2
);
DROP TEMPORARY TABLE migration_001_reconciled;

ALTER TABLE products
    DROP CHECK chk_legacy_quantity_nonnegative,
    DROP CHECK chk_legacy_price_nonnegative,
    DROP COLUMN quantity, DROP COLUMN unit_price;
INSERT INTO schema_migrations(version) VALUES ('001_inventory_access');
