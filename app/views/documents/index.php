<div class="page-actions actions">
    <?php if (can('receipts.create')): ?><a class="btn primary" href="/documents/create?type=receipt">+ Phiếu nhập</a><?php endif; ?>
    <?php if (can('issues.create')): ?><a class="btn secondary" href="/documents/create?type=issue">+ Phiếu xuất</a><?php endif; ?>
</div>
<div class="panel">
    <form method="get" action="/documents" class="grid-2">
        <input name="keyword" value="<?= e($filters['keyword'] ?? '') ?>" placeholder="Mã phiếu hoặc đối tác">
        <select name="type"><option value="">Tất cả loại phiếu</option><?php foreach (['receipt'=>'Nhập kho','issue'=>'Xuất kho'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($filters['type'] ?? '')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <select name="status"><option value="">Tất cả trạng thái</option><?php foreach (['draft','posted','cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= ($filters['status'] ?? '')===$status?'selected':'' ?>><?= e(document_status_label($status)) ?></option><?php endforeach; ?></select>
        <button class="btn secondary">Lọc phiếu</button>
    </form>
</div>
<div class="panel table-scroll"><table class="table"><thead><tr><th>Mã phiếu</th><th>Loại</th><th>Ngày chứng từ</th><th>Đối tác / người giao nhận</th><th>Trạng thái</th><th></th></tr></thead><tbody>
<?php foreach ($result['items'] as $item): ?><tr>
    <td><strong><?= e($item['number']) ?></strong></td><td><?= e(document_type_label($item['document_type'])) ?></td><td><?= e($item['document_date']) ?></td><td><?= e($item['partner_name']) ?></td>
    <td><span class="badge <?= $item['status']==='posted'?'success':'muted' ?>"><?= e(document_status_label($item['status'])) ?></span></td>
    <td><a class="btn small secondary" href="/documents/show?id=<?= (int)$item['id'] ?>">Xem phiếu</a></td>
</tr><?php endforeach; ?>
<?php if (!$result['items']): ?><tr><td colspan="6" class="empty">Không có phiếu phù hợp.</td></tr><?php endif; ?>
</tbody></table><?= pagination_links($result, '/documents', $filters) ?></div>
