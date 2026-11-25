<?php $old = $old ?? []; $type = $old['document_type'] ?? ($_GET['type'] ?? 'receipt'); $lines = $old['lines'] ?? [['quantity'=>1]]; ?>
<div class="panel">
    <h2>Lập phiếu <?= $type==='receipt'?'nhập':'xuất' ?> kho</h2>
    <p>Giá <?= $type==='receipt'?'mua':'bán' ?> được lấy từ lịch sử giá tại ngày chứng từ. Phiếu nháp chưa thay đổi tồn kho; ghi sổ mới cập nhật tồn.</p>
    <?php foreach (($errors ?? []) as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" action="/documents/create?type=<?= e($type) ?>" id="documentForm">
        <?= csrf_field() ?><input type="hidden" name="document_type" value="<?= e($type) ?>">
        <div class="grid-2">
            <div class="form-group"><label for="document_date">Ngày chứng từ</label><input id="document_date" type="datetime-local" name="document_date" required max="<?= date('Y-m-d\TH:i') ?>" value="<?= e($old['document_date'] ?? date('Y-m-d\TH:i')) ?>"></div>
            <div class="form-group"><label for="partner_name">Đối tác / người giao nhận</label><input id="partner_name" name="partner_name" maxlength="200" required value="<?= e($old['partner_name'] ?? '') ?>"></div>
        </div>
        <div class="table-scroll"><table class="table"><thead><tr><th>Sản phẩm / đơn vị cơ sở</th><th>Kho / vị trí</th><th>Lô hàng</th><th>Số lượng</th><th></th></tr></thead><tbody id="documentLines">
        <?php foreach (array_values($lines) as $index=>$line): ?>
            <tr class="document-line"><td><select name="lines[<?= $index ?>][product_id]" required data-product><option value="">Chọn sản phẩm</option>
                <?php foreach ($options['products'] as $product): ?><option value="<?= (int)$product['id'] ?>" <?= (int)($line['product_id'] ?? 0)===(int)$product['id']?'selected':'' ?>><?= e($product['name'] . ' · ' . $product['unit_name']) ?></option><?php endforeach; ?>
            </select></td><td><select name="lines[<?= $index ?>][location_id]" required><option value="">Chọn vị trí</option>
                <?php foreach ($options['locations'] as $location): ?><option value="<?= (int)$location['id'] ?>" <?= (int)($line['location_id'] ?? 0)===(int)$location['id']?'selected':'' ?>><?= e($location['label']) ?></option><?php endforeach; ?>
            </select></td><td><select name="lines[<?= $index ?>][lot_id]" required data-lot><option value="">Chọn lô</option>
                <?php foreach ($options['lots'] as $lot): ?><option data-product-id="<?= (int)$lot['product_id'] ?>" value="<?= (int)$lot['id'] ?>" <?= (int)($line['lot_id'] ?? 0)===(int)$lot['id']?'selected':'' ?>><?= e($lot['code'] . ' · ' . $lot['product_name'] . ($lot['expires_on']?' · HSD ' . $lot['expires_on']:'')) ?></option><?php endforeach; ?>
            </select></td><td><input aria-label="Số lượng" type="number" min="1" max="1000000" step="1" name="lines[<?= $index ?>][quantity]" value="<?= e((string)($line['quantity'] ?? 1)) ?>" required></td>
            <td><button type="button" class="btn small danger" data-remove-line>Xóa</button></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="help">Lô phải thuộc sản phẩm. Sản phẩm không theo dõi lô sử dụng lô NOLOT. Cùng sản phẩm ở hai vị trí cần hai dòng riêng.</p>
        <button type="button" class="btn secondary" id="addDocumentLine">+ Thêm dòng hàng</button>
        <div class="form-group" style="margin-top:18px"><label for="document_note">Ghi chú</label><textarea id="document_note" name="note" maxlength="5000" rows="3"><?= e($old['note'] ?? '') ?></textarea></div>
        <div class="actions"><button class="btn primary">Lưu phiếu nháp</button><a class="btn secondary" href="/documents">Quay lại</a></div>
    </form>
</div>
<script>
(() => {
    const body = document.getElementById('documentLines');
    let next = body.children.length;
    const filterLots = row => {
        const product = row.querySelector('[data-product]').value;
        const select = row.querySelector('[data-lot]');
        [...select.options].forEach(option => {
            option.hidden = !!option.value && option.dataset.productId !== product;
            option.disabled = option.hidden;
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
    };
    body.querySelectorAll('tr').forEach(filterLots);
    body.addEventListener('change', event => { if (event.target.matches('[data-product]')) filterLots(event.target.closest('tr')); });
    body.addEventListener('click', event => {
        if (event.target.matches('[data-remove-line]') && body.children.length>1) event.target.closest('tr').remove();
    });
    document.getElementById('addDocumentLine').addEventListener('click', () => {
        if (body.children.length>=100) return;
        const row = body.firstElementChild.cloneNode(true);
        row.querySelectorAll('[name]').forEach(field => { field.name=field.name.replace(/lines\[\d+\]/, 'lines['+next+']'); field.value=field.tagName==='INPUT'?'1':''; });
        next++; body.appendChild(row); filterLots(row);
    });
})();
</script>
