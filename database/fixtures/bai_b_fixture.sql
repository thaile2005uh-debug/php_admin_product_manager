-- BÀI B: tạo tài khoản thử cho 4 nhóm. Chạy 1 lần trên database đã nâng cấp lên tuần 2.
-- Mật khẩu lưu SHA256 (kiểu cũ); lần đăng nhập đầu hệ thống tự đổi sang password_hash().
-- Nhóm có sẵn: ADMIN (Quản trị), STOCK_CLERK (Người lập phiếu), STOCK_MANAGER (Thủ kho), VIEWER (Người xem).
SET NAMES utf8mb4;

INSERT INTO admins (username, email, password_hash, full_name)
SELECT * FROM (
  SELECT 'clerk'   AS username, 'clerk@example.com'   AS email, SHA2('clerk123',256)   AS password_hash, 'Nhân viên lập phiếu' AS full_name UNION ALL
  SELECT 'manager', 'manager@example.com', SHA2('manager123',256), 'Thủ kho' UNION ALL
  SELECT 'viewer',  'viewer@example.com',  SHA2('viewer123',256),  'Người xem' UNION ALL
  SELECT 'tester',  'tester@example.com',  SHA2('tester123',256),  'Tài khoản thử quyền (không nhóm)'
) t
WHERE NOT EXISTS (SELECT 1 FROM admins a WHERE a.username = t.username);

-- Gán nhóm: tester cố ý KHÔNG có nhóm và KHÔNG có ngoại lệ (dùng cho các tình huống 1-6).
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT a.id, r.id FROM admins a JOIN roles r
  ON (a.username='clerk' AND r.code='STOCK_CLERK')
  OR (a.username='manager' AND r.code='STOCK_MANAGER')
  OR (a.username='viewer' AND r.code='VIEWER');

SELECT a.username, COALESCE(GROUP_CONCAT(r.code),'(không nhóm)') AS nhom
FROM admins a LEFT JOIN user_roles ur ON ur.user_id=a.id LEFT JOIN roles r ON r.id=ur.role_id
GROUP BY a.id, a.username ORDER BY a.id;
