<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/helpers.php";
$config = require __DIR__ . "/config.php";
if (session_status() === PHP_SESSION_NONE) {
    session_name($config["app"]["session_name"]);
    session_start();
}
function admin_login(string $username, string $password): array {
    if ($username === "" || $password === "") return ["success"=>false,"message"=>"Vui lòng nhập tài khoản và mật khẩu."];
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = :u OR email = :email LIMIT 1");
    $stmt->execute(["u"=>$username,"email"=>$username]);
    $admin = $stmt->fetch();
    if (!$admin) return ["success"=>false,"message"=>"Tài khoản không tồn tại."];
    $legacy = strlen($admin['password_hash']) === 64 && ctype_xdigit($admin['password_hash']);
    $valid = $legacy ? hash_equals($admin['password_hash'], hash('sha256', $password)) : password_verify($password, $admin['password_hash']);
    if (!$valid) return ["success"=>false,"message"=>"Mật khẩu không chính xác."];
    if ($legacy || password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }
    session_regenerate_id(true);
    $_SESSION["admin_id"] = $admin["id"];
    $_SESSION["admin_username"] = $admin["username"];
    $_SESSION["admin_full_name"] = $admin["full_name"];
    return ["success"=>true,"message"=>"Đăng nhập thành công."];
}
function require_admin(): void {
    if (empty($_SESSION["admin_id"])) redirect("/login");
}
function admin_logout(): void {
    $_SESSION = [];
    session_destroy();
    redirect("/login");
}
