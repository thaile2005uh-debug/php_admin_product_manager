<?php $canManageCategories = can('categories.manage'); ?>
<?php if ($canManageCategories): ?><div class="page-actions"><a class="btn primary" href="/categories/create">+ Thêm danh mục</a></div><?php endif; ?>
<div class="panel">
<form method="get" action="/categories" class="filter-form">
    <select name="per_page" aria-label="Số danh mục mỗi trang">
        <?php foreach ([10,20,50] as $size): ?><option value="<?= $size ?>" <?= $result['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> danh mục / trang</option><?php endforeach; ?>
    </select>
    <button class="btn secondary" type="submit">Áp dụng</button>
</form>
</div>
<div class="panel table-scroll">
<table class="table">
<thead><tr><th>ID</th><th>Tên danh mục</th><th>Mô tả</th><th>Trạng thái</th><?php if ($canManageCategories): ?><th>Thao tác</th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($categories as $category): ?>
<tr>
<td><?= e($category["id"]) ?></td>
<td><strong><?= e($category["name"]) ?></strong></td>
<td><?= e($category["description"]) ?></td>
<td><span class="badge <?= $category["status"] ? 'success' : 'muted' ?>"><?= $category["status"] ? "Hiển thị" : "Ẩn" ?></span></td>
<?php if ($canManageCategories): ?><td class="row-actions">
<a class="btn small secondary" href="/categories/edit?id=<?= e($category["id"]) ?>">Sửa</a>
<form class="inline" method="POST" action="/categories/delete" onsubmit="return confirmDelete('Bạn chắc chắn muốn xóa danh mục này?')">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= e($category["id"]) ?>"><button class="btn small danger" type="submit">Xóa</button>
</form>
</td><?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (empty($categories)): ?><tr><td colspan="<?= $canManageCategories ? 5 : 4 ?>" class="empty">Chưa có danh mục.</td></tr><?php endif; ?>
</tbody>
</table>
<?= pagination_links($result, '/categories') ?>
</div>
