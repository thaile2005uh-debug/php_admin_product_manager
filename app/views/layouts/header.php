<?php
$config = require __DIR__ . '/../../src/config.php';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title><?= e($title ?? $config['app']['name']) ?></title><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="stylesheet" href="/assets/css/admin.css"></head><body>
<?php if (!empty($_SESSION['admin_id'])): ?>
<div class="admin-layout"><aside class="sidebar">
<div class="brand"><div class="brand-icon">K</div><div><strong>Kho & Sản phẩm</strong><small>PHP · Bài thực hành mở rộng</small></div></div>
<nav class="nav">
<?php foreach ([['/dashboard','dashboard.view','Tổng quan tồn kho'],['/inventory','inventory.view','Tồn kho theo vị trí'],['/movements','inventory.view','Sổ biến động'],['/ai','ai.ask','Hỏi dữ liệu AI'],['/documents','documents.view','Phiếu nhập / xuất'],['/products','products.view','Sản phẩm'],['/categories','categories.view','Danh mục sản phẩm'],['/prices','prices.view','Giá theo thời gian'],['/warehouse-catalog','warehouse.manage','Danh mục kho'],['/access','access.manage','Nhóm & phân quyền']] as [$href,$permission,$label]): ?>
<?php if (can($permission)): ?><a class="<?= str_starts_with($currentPath,$href) ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endif; ?>
<?php endforeach; ?>
</nav><form class="logout-form" method="POST" action="/logout"><?= csrf_field() ?><button class="btn secondary" type="submit">Đăng xuất</button></form>
</aside><main class="main"><header class="topbar"><div><small class="eyebrow">QUẢN TRỊ KHO HÀNG</small><h1><?= e($title ?? 'Admin') ?></h1><p><?= e($_SESSION['admin_full_name'] ?: $_SESSION['admin_username']) ?> · Giờ Việt Nam (UTC+7)</p></div></header>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert success" role="status"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php endif; ?>
