-- BÀI D: đối soát từng bộ khóa (sản phẩm · vị trí · lô).
-- stock_balances.quantity phải = SUM(stock_movements.quantity). Cột lech phải bằng 0 ở mọi dòng.
-- Chỉ ĐỌC dữ liệu, không sửa bảng số dư.
SET NAMES utf8mb4;
SELECT p.sku, CONCAT(w.code,'/',loc.code) AS vi_tri, lot.code AS lo,
       b.quantity AS so_du,
       COALESCE(m.tong_bien_dong,0) AS tong_bien_dong,
       b.quantity - COALESCE(m.tong_bien_dong,0) AS lech
FROM stock_balances b
JOIN products p ON p.id=b.product_id
JOIN warehouse_locations loc ON loc.id=b.location_id
JOIN warehouses w ON w.id=loc.warehouse_id
JOIN product_lots lot ON lot.id=b.lot_id
LEFT JOIN (SELECT product_id, location_id, lot_id, SUM(quantity) AS tong_bien_dong
           FROM stock_movements GROUP BY product_id, location_id, lot_id) m
  ON m.product_id=b.product_id AND m.location_id=b.location_id AND m.lot_id=b.lot_id
ORDER BY p.sku, w.code, loc.code, lot.code;

-- Chiều ngược lại: có biến động nhưng thiếu dòng số dư (phải ra 0 dòng).
SELECT m.product_id, m.location_id, m.lot_id, SUM(m.quantity) AS tong_bien_dong
FROM stock_movements m
LEFT JOIN stock_balances b ON b.product_id=m.product_id AND b.location_id=m.location_id AND b.lot_id=m.lot_id
WHERE b.product_id IS NULL
GROUP BY m.product_id, m.location_id, m.lot_id HAVING SUM(m.quantity) <> 0;
