<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';

function inventory_query(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inventory_warehouses(): array {
    return inventory_query('SELECT * FROM warehouses ORDER BY code');
}

function inventory_paginate(array $filters = [], int $page = 1, int $perPage = 20): array {
    $where = ['1=1']; $params = [];
    if (($filters['keyword'] ?? '') !== '') {
        $where[] = '(p.name LIKE :name OR p.sku LIKE :sku OR lot.code LIKE :lot)';
        foreach (['name', 'sku', 'lot'] as $key) $params[$key] = '%' . $filters['keyword'] . '%';
    }
    if ((int)($filters['warehouse_id'] ?? 0) > 0) {
        $where[] = 'w.id=:warehouse'; $params['warehouse'] = (int)$filters['warehouse_id'];
    }
    $joins = ' FROM stock_balances b JOIN products p ON p.id=b.product_id
        JOIN units u ON u.id=p.unit_id JOIN warehouse_locations loc ON loc.id=b.location_id
        JOIN warehouses w ON w.id=loc.warehouse_id JOIN product_lots lot ON lot.id=b.lot_id';
    $condition = ' WHERE ' . implode(' AND ', $where);
    $count = inventory_query('SELECT COUNT(*) AS n' . $joins . $condition, $params)[0]['n'];
    $result = pagination_meta((int)$count, $page, $perPage);
    $result['items'] = inventory_query('SELECT b.*,p.name,p.sku,p.min_stock,u.name AS unit_name,
        w.name AS warehouse_name,loc.code AS location_code,lot.code AS lot_code,lot.expires_on' . $joins . $condition .
        ' ORDER BY w.code,loc.code,p.name,lot.code LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'], $params);
    // Report totals by base unit; e.g. boxes and individual pieces are never added together.
    $result['quantity_by_unit'] = inventory_query('SELECT u.id AS unit_id,u.name AS unit_name,u.code AS unit_code,SUM(b.quantity) AS quantity' .
        $joins . $condition . ' GROUP BY u.id,u.name,u.code ORDER BY u.code', $params);
    return $result;
}

function inventory_dashboard(): array {
    $summary = inventory_query('SELECT COALESCE(SUM(quantity),0) AS total_quantity,
        COUNT(DISTINCT CASE WHEN quantity>0 THEN product_id END) AS stocked_products FROM stock_balances')[0];
    $low = ' FROM products p LEFT JOIN (SELECT product_id,SUM(quantity) AS quantity FROM stock_balances GROUP BY product_id) b
        ON b.product_id=p.id JOIN units u ON u.id=p.unit_id WHERE p.status=1 AND COALESCE(b.quantity,0)<=p.min_stock';
    return [
        'total_quantity' => (int)$summary['total_quantity'],
        'stocked_products' => (int)$summary['stocked_products'],
        'low_stock_count' => (int)inventory_query('SELECT COUNT(*) AS n' . $low)[0]['n'],
        'pending_documents' => (int)inventory_query("SELECT COUNT(*) AS n FROM inventory_documents WHERE status='draft'")[0]['n'],
        'quantity_by_unit' => inventory_query('SELECT u.id AS unit_id,u.name AS unit_name,u.code AS unit_code,SUM(b.quantity) AS quantity
            FROM stock_balances b JOIN products p ON p.id=b.product_id JOIN units u ON u.id=p.unit_id
            GROUP BY u.id,u.name,u.code ORDER BY u.code'),
        'low_stock' => inventory_query('SELECT p.id,p.name,p.sku,p.min_stock,COALESCE(b.quantity,0) AS quantity,u.name AS unit_name' . $low . ' ORDER BY quantity,p.id LIMIT 10'),
        'warehouses' => inventory_query("SELECT w.id,w.name,w.code,COALESCE(u.name,'Chưa phát sinh') AS unit_name,COALESCE(SUM(b.quantity),0) AS quantity,
            COUNT(DISTINCT CASE WHEN b.quantity>0 THEN b.product_id END) AS product_count
            FROM warehouses w LEFT JOIN warehouse_locations loc ON loc.warehouse_id=w.id
            LEFT JOIN stock_balances b ON b.location_id=loc.id LEFT JOIN products p ON p.id=b.product_id
            LEFT JOIN units u ON u.id=p.unit_id GROUP BY w.id,w.name,w.code,u.id,u.name ORDER BY w.code,u.name"),
        'recent_documents' => inventory_query('SELECT * FROM inventory_documents ORDER BY id DESC LIMIT 6'),
    ];
}

function document_type_label(string $type): string { return $type === 'receipt' ? 'Nhập kho' : 'Xuất kho'; }
function document_status_label(string $status): string {
    return ['draft'=>'Nháp', 'posted'=>'Đã ghi sổ', 'cancelled'=>'Đã hủy'][$status] ?? $status;
}
function document_permission(string $type, string $action): string {
    if (!in_array($type, ['receipt', 'issue'], true)) throw new InvalidArgumentException('Loại phiếu không hợp lệ.');
    return ($type === 'receipt' ? 'receipts.' : 'issues.') . $action;
}

function document_paginate(array $filters = [], int $page = 1, int $perPage = 20): array {
    $where = ['1=1']; $params = [];
    foreach (['type'=>'document_type', 'status'=>'status'] as $key=>$column) {
        if (($filters[$key] ?? '') !== '') { $where[] = "$column=:$key"; $params[$key] = $filters[$key]; }
    }
    if (($filters['keyword'] ?? '') !== '') {
        $where[] = '(number LIKE :number OR partner_name LIKE :partner)';
        $params['number'] = $params['partner'] = '%' . $filters['keyword'] . '%';
    }
    $condition = ' WHERE ' . implode(' AND ', $where);
    $result = pagination_meta((int)inventory_query('SELECT COUNT(*) AS n FROM inventory_documents' . $condition, $params)[0]['n'], $page, $perPage);
    $result['items'] = inventory_query('SELECT * FROM inventory_documents' . $condition . ' ORDER BY id DESC LIMIT ' .
        (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'], $params);
    return $result;
}

function document_find(int $id): ?array {
    $rows = inventory_query('SELECT d.*,a.full_name AS creator_name,a.username AS creator_username,
        poster.full_name AS poster_name,poster.username AS poster_username FROM inventory_documents d
        JOIN admins a ON a.id=d.created_by LEFT JOIN admins poster ON poster.id=d.posted_by WHERE d.id=:id', ['id'=>$id]);
    if (!$rows) return null;
    $document = $rows[0];
    $document['lines'] = inventory_query('SELECT * FROM inventory_lines WHERE document_id=:id ORDER BY id', ['id'=>$id]);
    $document['total_cents'] = 0;
    foreach ($document['lines'] as &$line) {
        $line['amount_cents'] = inventory_price_cents((string)$line['unit_price']) * (int)$line['quantity'];
        $document['total_cents'] += $line['amount_cents'];
    }
    unset($line);
    return $document;
}

function inventory_price_cents(string $amount): int {
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) throw new InvalidArgumentException('Đơn giá không hợp lệ.');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}
function inventory_money(int $cents): string {
    return number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT) . ' đ';
}

function document_form_options(): array {
    return [
        'products'=>inventory_query('SELECT p.id,p.name,p.sku,u.name AS unit_name FROM products p JOIN units u ON u.id=p.unit_id WHERE p.status=1 ORDER BY p.name'),
        'locations'=>inventory_query("SELECT loc.*,CONCAT(w.code,' / ',loc.code) AS label FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id ORDER BY w.code,loc.code"),
        'lots'=>inventory_query('SELECT lot.*,p.name AS product_name FROM product_lots lot JOIN products p ON p.id=lot.product_id ORDER BY p.name,lot.code'),
    ];
}

function inventory_document_date(string $value): string {
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            if ($date > new DateTimeImmutable()) throw new InvalidArgumentException('Ngày chứng từ không được ở tương lai.');
            return $date->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Ngày chứng từ không hợp lệ.');
}

function document_create(array $input, int $actorId): int {
    $type = (string)($input['document_type'] ?? '');
    require_permission(document_permission($type, 'create'));
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người lập phiếu không hợp lệ.');
    $date = inventory_document_date((string)($input['document_date'] ?? ''));
    $partner = trim((string)($input['partner_name'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $partnerLength = preg_match_all('/./us', $partner);
    $noteLength = preg_match_all('/./us', $note);
    if ($partnerLength === false || $partnerLength < 1 || $partnerLength > 200) throw new InvalidArgumentException('Đối tác/người giao nhận phải có từ 1 đến 200 ký tự.');
    if ($noteLength === false || $noteLength > 5000) throw new InvalidArgumentException('Ghi chú tối đa 5.000 ký tự.');
    $lines = $input['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) throw new InvalidArgumentException('Phiếu cần từ 1 đến 100 dòng hàng.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $number = ($type === 'receipt' ? 'PN-' : 'PX-') . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('INSERT INTO inventory_documents(number,document_type,status,document_date,partner_name,note,created_by) VALUES(:number,:type,\'draft\',:date,:partner,:note,:actor)');
        $stmt->execute(['number'=>$number,'type'=>$type,'date'=>$date,'partner'=>$partner,'note'=>$note,'actor'=>$actorId]);
        $id = (int)$pdo->lastInsertId();
        $documentTotal = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) throw new InvalidArgumentException('Dòng hàng không hợp lệ.');
            $quantity = filter_var($line['quantity'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1000000]]);
            if ($quantity === false) throw new InvalidArgumentException('Số lượng phải là số nguyên từ 1 đến 1.000.000.');
            $productId = (int)($line['product_id'] ?? 0); $locationId = (int)($line['location_id'] ?? 0); $lotId = (int)($line['lot_id'] ?? 0);
            // Product lock serializes price scheduling with quote creation and prevents a unit change mid-document.
            $products = inventory_query('SELECT p.*,u.name AS unit_name FROM products p JOIN units u ON u.id=p.unit_id WHERE p.id=:id AND p.status=1 FOR UPDATE', ['id'=>$productId]);
            if (!$products) throw new InvalidArgumentException('Sản phẩm phải đang hoạt động và có đơn vị cơ sở.');
            $product = $products[0];
            $locations = inventory_query("SELECT CONCAT(w.code,' / ',loc.code) AS label FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id WHERE loc.id=:id", ['id'=>$locationId]);
            if (!$locations) throw new InvalidArgumentException('Vị trí kho không tồn tại.');
            $lots = inventory_query('SELECT * FROM product_lots WHERE id=:id AND product_id=:product', ['id'=>$lotId,'product'=>$productId]);
            if (!$lots) throw new InvalidArgumentException('Lô hàng phải thuộc đúng sản phẩm.');
            if ($type === 'issue') inventory_assert_lot_usable($lots[0]['expires_on'], $date);
            $prices = inventory_query('SELECT * FROM product_prices WHERE product_id=:product AND price_type=:type
                AND valid_from<=:start AND (valid_to IS NULL OR valid_to>:end) ORDER BY valid_from DESC,id DESC LIMIT 2',
                ['product'=>$productId,'type'=>$type === 'receipt' ? 'purchase' : 'sale','start'=>$date,'end'=>$date]);
            if (count($prices) !== 1) throw new InvalidArgumentException('Sản phẩm phải có đúng một giá hiệu lực tại ngày chứng từ. Hãy thiết lập giá trước.');
            $price = $prices[0]; $lineTotal = inventory_price_cents((string)$price['amount']) * $quantity;
            if ($lineTotal > 900000000000000 || $documentTotal + $lineTotal > 900000000000000) throw new InvalidArgumentException('Tổng tiền phiếu vượt giới hạn 9.000.000.000.000 đ.');
            $documentTotal += $lineTotal;
            $stmt = $pdo->prepare('INSERT INTO inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,product_name,sku,unit_name,location_label,lot_code)
                VALUES(:document,:product,:location,:lot,:quantity,:unit,:price,:price_id,:name,:sku,:unit_name,:location_label,:lot_code)');
            $stmt->execute(['document'=>$id,'product'=>$productId,'location'=>$locationId,'lot'=>$lotId,'quantity'=>$quantity,
                'unit'=>$product['unit_id'],'price'=>$price['amount'],'price_id'=>$price['id'],'name'=>$product['name'],'sku'=>$product['sku'],
                'unit_name'=>$product['unit_name'],'location_label'=>$locations[0]['label'],'lot_code'=>$lots[0]['code']]);
        }
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function inventory_assert_lot_usable(?string $expiry, string $documentDate): void {
    if ($expiry !== null && ($expiry < substr($documentDate, 0, 10) || $expiry < date('Y-m-d'))) {
        throw new InvalidArgumentException('Không được xuất lô đã hết hạn theo ngày chứng từ hoặc ngày hiện tại.');
    }
}

function document_post(int $id, int $actorId): void {
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người ghi sổ không hợp lệ.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $rows = inventory_query('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$rows) throw new InvalidArgumentException('Không tìm thấy phiếu.');
        $document = $rows[0];
        require_permission(document_permission($document['document_type'], 'post'));
        if ($document['status'] !== 'draft') throw new InvalidArgumentException('Chỉ phiếu nháp mới được ghi sổ.');
        $lines = inventory_query('SELECT * FROM inventory_lines WHERE document_id=:id ORDER BY product_id,location_id,lot_id,id', ['id'=>$id]);
        if (!$lines) throw new InvalidArgumentException('Phiếu không có dòng hàng.');
        // Lock master data before balances, in product/lot order. Unit or expiry edits cannot
        // race with posting. Document lock above already protects its immutable draft lines.
        $productIds = array_values(array_unique(array_map('intval', array_column($lines, 'product_id'))));
        sort($productIds, SORT_NUMERIC);
        $products = [];
        foreach ($productIds as $productId) {
            $rows = inventory_query('SELECT unit_id FROM products WHERE id=:id FOR UPDATE', ['id'=>$productId]);
            if (!$rows) throw new InvalidArgumentException('Sản phẩm không còn tồn tại.');
            $products[$productId] = $rows[0];
        }
        $lotIds = array_values(array_unique(array_map('intval', array_column($lines, 'lot_id'))));
        sort($lotIds, SORT_NUMERIC);
        $lots = [];
        foreach ($lotIds as $lotId) {
            $rows = inventory_query('SELECT product_id,expires_on FROM product_lots WHERE id=:id FOR UPDATE', ['id'=>$lotId]);
            if (!$rows) throw new InvalidArgumentException('Lô hàng không còn tồn tại.');
            $lots[$lotId] = $rows[0];
        }
        $keys = [];
        foreach ($lines as $line) {
            if ((int)$products[$line['product_id']]['unit_id'] !== (int)$line['unit_id']) throw new InvalidArgumentException('Đơn vị sản phẩm đã thay đổi; không thể ghi sổ phiếu.');
            if ((int)$lots[$line['lot_id']]['product_id'] !== (int)$line['product_id']) throw new InvalidArgumentException('Lô không thuộc sản phẩm.');
            if ($document['document_type'] === 'issue') inventory_assert_lot_usable($lots[$line['lot_id']]['expires_on'], $document['document_date']);
            $key = $line['product_id'] . ':' . $line['location_id'] . ':' . $line['lot_id'];
            if (!isset($keys[$key])) $keys[$key] = ['product_id'=>(int)$line['product_id'],'location_id'=>(int)$line['location_id'],'lot_id'=>(int)$line['lot_id'],'quantity'=>0];
            $keys[$key]['quantity'] += (int)$line['quantity'];
        }
        foreach ($keys as $key) {
            $ids = ['product'=>$key['product_id'],'location'=>$key['location_id'],'lot'=>$key['lot_id']];
            $stmt = $pdo->prepare('INSERT INTO stock_balances(product_id,location_id,lot_id,quantity) VALUES(:product,:location,:lot,0) ON DUPLICATE KEY UPDATE quantity=quantity');
            $stmt->execute($ids);
            $balances = inventory_query('SELECT quantity FROM stock_balances WHERE product_id=:product AND location_id=:location AND lot_id=:lot FOR UPDATE', $ids);
            $change = $key['quantity'] * ($document['document_type'] === 'receipt' ? 1 : -1);
            $next = (int)$balances[0]['quantity'] + $change;
            if ($next < 0) throw new InvalidArgumentException('Tồn kho không đủ tại vị trí và lô đã chọn. Toàn bộ phiếu chưa được ghi sổ.');
            if ($next > 2147483647) throw new InvalidArgumentException('Tồn kho vượt giới hạn lưu trữ.');
            $stmt = $pdo->prepare('UPDATE stock_balances SET quantity=:quantity WHERE product_id=:product AND location_id=:location AND lot_id=:lot');
            $stmt->execute($ids + ['quantity'=>$next]);
        }
        $stmt = $pdo->prepare('INSERT INTO stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity) VALUES(:document,:line,:product,:location,:lot,:quantity)');
        foreach ($lines as $line) $stmt->execute(['document'=>$id,'line'=>$line['id'],'product'=>$line['product_id'],'location'=>$line['location_id'],'lot'=>$line['lot_id'],
            'quantity'=>(int)$line['quantity'] * ($document['document_type'] === 'receipt' ? 1 : -1)]);
        $stmt = $pdo->prepare("UPDATE inventory_documents SET status='posted',posted_by=:actor,posted_at=NOW() WHERE id=:id");
        $stmt->execute(['actor'=>$actorId,'id'=>$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function document_cancel(int $id, int $actorId): void {
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người hủy phiếu không hợp lệ.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $rows = inventory_query('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$rows) throw new InvalidArgumentException('Không tìm thấy phiếu.');
        require_permission(document_permission($rows[0]['document_type'], 'create'));
        if ($rows[0]['status'] !== 'draft') throw new InvalidArgumentException('Chỉ được hủy phiếu nháp. Phiếu ghi sổ không được sửa hay hủy.');
        $stmt = $pdo->prepare("UPDATE inventory_documents SET status='cancelled' WHERE id=:id"); $stmt->execute(['id'=>$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
