-- BÀI B: truy vấn chứng minh QUYỀN HIỆU LỰC của 1 tài khoản với 1 quyền.
-- Sửa 2 giá trị ở dưới rồi chạy. Thứ tự: deny riêng > allow riêng > nhóm > từ chối mặc định.
SET @username = 'tester';
SET @perm     = 'receipts.create';

SELECT a.username, p.code AS quyen,
  (SELECT GROUP_CONCAT(r.code) FROM user_roles ur JOIN roles r ON r.id=ur.role_id
     JOIN role_permissions rp ON rp.role_id=r.id WHERE ur.user_id=a.id AND rp.permission_id=p.id) AS nhom_cap_quyen,
  up.effect AS ngoai_le_rieng,
  CASE
    WHEN up.effect='deny'  THEN 'TỪ CHỐI (deny riêng)'
    WHEN up.effect='allow' THEN 'CHO PHÉP (allow riêng)'
    WHEN EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
                 WHERE ur.user_id=a.id AND rp.permission_id=p.id) THEN 'CHO PHÉP (từ nhóm)'
    ELSE 'TỪ CHỐI (mặc định)'
  END AS ket_qua
FROM admins a
JOIN permissions p ON p.code=@perm
LEFT JOIN user_permissions up ON up.user_id=a.id AND up.permission_id=p.id
WHERE a.username=@username;

-- Toàn bộ quyền hiệu lực của tài khoản (xem cả bảng):
SELECT p.code AS quyen,
  CASE
    WHEN up.effect='deny'  THEN 'TỪ CHỐI (deny riêng)'
    WHEN up.effect='allow' THEN 'CHO PHÉP (allow riêng)'
    WHEN EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
                 WHERE ur.user_id=a.id AND rp.permission_id=p.id) THEN 'CHO PHÉP (từ nhóm)'
    ELSE 'TỪ CHỐI (mặc định)'
  END AS ket_qua
FROM admins a CROSS JOIN permissions p
LEFT JOIN user_permissions up ON up.user_id=a.id AND up.permission_id=p.id
WHERE a.username=@username ORDER BY p.id;
