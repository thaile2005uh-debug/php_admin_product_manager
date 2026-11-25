<div class="page-actions"><?php if (can('products.manage')): ?><a class="btn primary" href="/products/create">+ Thêm sản phẩm</a><?php endif; ?></div>
<div class="panel"><form method="GET" action="/products" class="filter-form">
<input aria-label="Từ khóa" name="keyword" placeholder="Tìm tên hoặc SKU" value="<?= e($keyword) ?>">
<select name="category_id" aria-label="Danh mục"><option value="0">Tất cả danh mục</option><?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select>
<select name="per_page" aria-label="Số dòng"><?php foreach ([10,20,50] as $size): ?><option value="<?= $size ?>" <?= $result['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> dòng / trang</option><?php endforeach; ?></select><button class="btn secondary">Lọc</button></form></div>
<div class="panel table-scroll"><table class="table"><thead><tr><th>Ảnh</th><th>Sản phẩm</th><th>Danh mục</th><th>Tồn toàn hệ thống</th><th>Ngưỡng cảnh báo</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach ($result['items'] as $product): ?><tr>
<td><?php if ($product['image_path']): ?><img class="thumb" src="/<?= e($product['image_path']) ?>" alt="<?= e($product['name']) ?>"><?php else: ?><div class="no-image">Chưa có ảnh</div><?php endif; ?></td>
<td><strong><?= e($product['name']) ?></strong><small><?= e($product['sku'] ?: 'Chưa có SKU') ?></small></td><td><?= e($product['category_name']) ?></td>
<td><?= number_format((int)$product['stock_quantity']) ?> <?= e($product['unit_name']) ?></td><td><?= number_format((int)$product['min_stock']) ?></td><td><span class="badge <?= $product['status'] ? 'success' : 'muted' ?>"><?= $product['status'] ? 'Hoạt động' : 'Ẩn' ?></span></td><td>
<?php if (can('prices.view')): ?><a class="btn small secondary" href="/prices?product_id=<?= e($product['id']) ?>">Lịch sử giá</a><?php endif; ?>
<?php if (can('products.manage')): ?><a class="btn small secondary" href="/products/edit?id=<?= e($product['id']) ?>">Sửa</a><form class="inline" method="POST" action="/products/delete" onsubmit="return confirmDelete('Xóa sản phẩm chưa có giá và nghiệp vụ kho?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($product['id']) ?>"><button class="btn small danger">Xóa</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$result['items']): ?><tr><td colspan="7" class="empty">Không có sản phẩm phù hợp.</td></tr><?php endif; ?></tbody></table>
<?= pagination_links($result,'/products',['keyword'=>$keyword,'category_id'=>$categoryId]) ?>
<p class="help">Tồn được tổng hợp từ sổ kho. Đơn giá được quản lý tại màn hình Giá theo thời gian.</p></div>
