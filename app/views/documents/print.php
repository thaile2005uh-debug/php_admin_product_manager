<div class="panel print-sheet">
    <div class="no-print page-actions"><button class="btn primary" onclick="window.print()">In / lưu PDF</button><a class="btn secondary" href="/documents/show?id=<?= (int)$document['id'] ?>">Về chi tiết phiếu</a></div>
    <h2 style="text-align:center"><?= $document['document_type']==='receipt'?'PHIẾU NHẬP KHO':'PHIẾU XUẤT KHO' ?></h2>
    <p style="text-align:center"><strong><?= e($document['number']) ?></strong><br>Ngày chứng từ: <?= e($document['document_date']) ?></p>
    <p>Đối tác / người giao nhận: <strong><?= e($document['partner_name']) ?></strong></p>
    <p>Thời điểm ghi sổ: <?= e($document['posted_at']) ?></p>
    <?php require __DIR__ . '/table.php'; ?>
    <p>Ghi chú: <?= nl2br(e($document['note'])) ?></p>
    <div class="print-signatures"><div><strong>Người lập phiếu</strong><p>(Ký, ghi rõ họ tên)</p><p><?= e($document['creator_name'] ?: $document['creator_username']) ?></p></div><div><strong>Người giao / nhận</strong><p>(Ký, ghi rõ họ tên)</p></div><div><strong>Thủ kho</strong><p>(Ký, ghi rõ họ tên)</p></div><div><strong>Người ghi sổ</strong><p>(Ký, ghi rõ họ tên)</p><p><?= e($document['poster_name'] ?: ($document['poster_username'] ?? '')) ?></p></div></div>
    <p class="help">Chứng từ quản lý kho nội bộ. Không phải hóa đơn thuế GTGT.</p>
</div>
<style>
.print-signatures{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;text-align:center;margin-top:35px}.print-signatures p{min-height:30px}.print-sheet{max-width:1200px;margin:auto}
@media print{@page{size:A4 landscape;margin:12mm}body{background:#fff;font-size:11px}.sidebar,.topbar,.no-print{display:none!important}.main{margin:0!important;padding:0!important;width:100%!important}.admin-layout{display:block}.print-sheet{box-shadow:none;border-radius:0;padding:0}.table th,.table td{padding:8px 6px}.table tr{break-inside:avoid}.print-signatures{break-inside:avoid}.table th{background:#fff!important;color:#000}.table th,.table td{border:1px solid #777}.help{font-size:10px}}
</style>
