<table class="table"><thead><tr><th>STT</th><th>Sản phẩm / SKU</th><th>Kho / vị trí</th><th>Lô</th><th>ĐVT</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>
<?php foreach ($document['lines'] as $index=>$line): ?><tr><td><?= $index+1 ?></td><td><strong><?= e($line['product_name']) ?></strong><small><?= e($line['sku']) ?></small></td>
    <td><?= e($line['location_label']) ?></td><td><?= e($line['lot_code']) ?></td><td><?= e($line['unit_name']) ?></td><td><?= number_format((int)$line['quantity']) ?></td>
    <td><?= inventory_money(inventory_price_cents((string)$line['unit_price'])) ?></td><td><?= inventory_money($line['amount_cents']) ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><td colspan="7"><strong>Tổng giá trị chứng từ</strong></td><td><strong><?= inventory_money($document['total_cents']) ?></strong></td></tr></tfoot></table>
