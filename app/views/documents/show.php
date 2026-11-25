<div class="panel">
    <div class="page-actions actions">
        <a class="btn secondary" href="/documents">Danh sách phiếu</a>
        <?php if ($document['status']==='posted' && can('documents.print')): ?><a class="btn primary" href="/documents/print?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener">Bản in</a><?php endif; ?>
        <?php if ($document['status']==='draft' && can(document_permission($document['document_type'],'post'))): ?>
        <form method="post" action="/documents/post" class="inline" onsubmit="return confirm('Ghi sổ phiếu và cập nhật tồn kho? Phiếu ghi sổ sẽ không được sửa hoặc hủy.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$document['id'] ?>"><button class="btn primary">Ghi sổ</button></form>
        <?php endif; ?>
        <?php if ($document['status']==='draft' && can(document_permission($document['document_type'],'create'))): ?>
        <form method="post" action="/documents/cancel" class="inline" onsubmit="return confirm('Hủy phiếu nháp này?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$document['id'] ?>"><button class="btn danger">Hủy nháp</button></form>
        <?php endif; ?>
    </div>
    <h2><?= e(document_type_label($document['document_type']) . ' · ' . $document['number']) ?></h2>
    <p><span class="badge <?= $document['status']==='posted'?'success':'muted' ?>"><?= e(document_status_label($document['status'])) ?></span></p>
    <div class="grid-2"><p>Ngày chứng từ: <strong><?= e($document['document_date']) ?></strong></p><p>Đối tác / người giao nhận: <strong><?= e($document['partner_name']) ?></strong></p>
    <p>Người lập: <?= e($document['creator_name'] ?: $document['creator_username']) ?></p><p>Ghi sổ: <?= e($document['posted_at'] ?? 'Chưa ghi sổ') ?> <?= e($document['poster_name'] ?: ($document['poster_username'] ?? '')) ?></p></div>
    <?php if ($document['status']==='draft'): ?><p class="hint">Giá đã được chụp tại thời điểm lập theo ngày chứng từ. Muốn thay đổi phiếu, hủy nháp rồi lập phiếu mới. Tồn kho được kiểm tra lại khi ghi sổ.</p><?php endif; ?>
    <div class="table-scroll"><?php require __DIR__ . '/table.php'; ?></div>
    <p>Ghi chú: <?= nl2br(e($document['note'])) ?></p>
</div>
