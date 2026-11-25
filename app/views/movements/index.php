<div class="panel">
    <p>Sổ biến động là lịch sử mọi lần <strong>ghi sổ</strong> nhập / xuất (số âm = xuất). Màn hình này chỉ xem, không làm thay đổi tồn kho.</p>
    <?php foreach ($filterErrors as $message): ?><div class="alert error" role="alert"><?= e($message) ?></div><?php endforeach; ?>
    <form method="get" action="/movements" class="filter-form" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
        <select name="product_id" aria-label="Sản phẩm"><option value="0">Tất cả sản phẩm</option>
            <?php foreach ($products as $product): ?><option value="<?= (int)$product['id'] ?>" <?= $filters['product_id']===(int)$product['id']?'selected':'' ?>><?= e($product['name']) ?><?= $product['sku'] ? ' (' . e($product['sku']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
        <select name="warehouse_id" aria-label="Kho"><option value="0">Tất cả kho</option>
            <?php foreach ($warehouses as $warehouse): ?><option value="<?= (int)$warehouse['id'] ?>" <?= $filters['warehouse_id']===(int)$warehouse['id']?'selected':'' ?>><?= e($warehouse['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="date_from" aria-label="Từ ngày" value="<?= e($filters['date_from']) ?>">
        <input type="date" name="date_to" aria-label="Đến ngày" value="<?= e($filters['date_to']) ?>">
        <select name="per_page" aria-label="Số dòng"><?php foreach ([10,20,50] as $size): ?><option value="<?= $size ?>" <?= $result['per_page']===$size?'selected':'' ?>><?= $size ?> dòng / trang</option><?php endforeach; ?></select>
        <button class="btn secondary">Lọc sổ biến động</button>
    </form>
</div>
<div class="panel table-scroll">
    <p>Tổng của toàn bộ kết quả lọc:
    <?php foreach ($result['totals'] as $total): ?>
        <span class="badge success">Nhập <?= number_format((int)$total['qty_in']) ?> <?= e($total['unit_name']) ?></span>
        <span class="badge muted">Xuất <?= number_format((int)$total['qty_out']) ?> <?= e($total['unit_name']) ?></span>
    <?php endforeach; ?>
    <?php if (!$result['totals']): ?>Không có biến động phù hợp.<?php endif; ?></p>
    <table class="table"><thead><tr><th>Ngày phiếu</th><th>Phiếu</th><th>Sản phẩm</th><th>Kho / vị trí</th><th>Lô</th><th>Biến động</th><th>Ghi sổ lúc</th></tr></thead><tbody>
    <?php foreach ($result['items'] as $row): $qty = (int)$row['quantity']; ?>
        <tr>
            <td><?= e(date('d/m/Y H:i', strtotime($row['document_date']))) ?></td>
            <td><?php if (can('documents.view')): ?><a href="/documents/show?id=<?= (int)$row['document_id'] ?>"><?= e($row['number']) ?></a><?php else: ?><?= e($row['number']) ?><?php endif; ?>
                <small><?= $row['document_type']==='receipt' ? 'Nhập' : 'Xuất' ?></small></td>
            <td><strong><?= e($row['product_name']) ?></strong><small><?= e($row['sku'] ?? '') ?></small></td>
            <td><?= e($row['warehouse_name']) ?><small><?= e($row['location_code']) ?></small></td>
            <td><?= e($row['lot_code']) ?></td>
            <td><strong><?= ($qty > 0 ? '+' : '') . number_format($qty) ?></strong> <?= e($row['unit_name']) ?></td>
            <td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$result['items']): ?><tr><td colspan="7" class="empty">Không có biến động phù hợp với bộ lọc.</td></tr><?php endif; ?>
    </tbody></table>
    <?= pagination_links($result, '/movements', array_filter($filters, fn($v) => $v !== '' && $v !== 0)) ?>
</div>
