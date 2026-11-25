<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';
function price_paginate(int $productId = 0, int $page = 1, int $perPage = 20): array {
    $where = $productId > 0 ? ' WHERE pp.product_id=?' : ''; $params = $productId > 0 ? [$productId] : [];
    $stmt = db()->prepare('SELECT COUNT(*) FROM product_prices pp' . $where); $stmt->execute($params);
    $result = pagination_meta((int)$stmt->fetchColumn(), $page, $perPage);
    $stmt = db()->prepare('SELECT pp.*,p.name,p.sku FROM product_prices pp JOIN products p ON p.id=pp.product_id' . $where .
        ' ORDER BY pp.valid_from DESC,pp.id DESC LIMIT ' . $result['per_page'] . ' OFFSET ' . $result['offset']);
    $stmt->execute($params); $result['items'] = $stmt->fetchAll(); return $result;
}
function price_create(array $input, int $actorId): void {
    require_permission('prices.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $type = $input['price_type'] ?? '';
    if (!in_array($type, ['purchase','sale'], true)) throw new InvalidArgumentException('Loại giá không hợp lệ.');
    $amount = trim($input['amount'] ?? '');
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) throw new InvalidArgumentException('Giá phải không âm, tối đa 13 chữ số và 2 chữ số thập phân (dấu chấm).');
    $from = valid_datetime($input['valid_from'] ?? '');
    $to = trim($input['valid_to'] ?? '') === '' ? null : valid_datetime($input['valid_to']);
    if ($to !== null && $to <= $from) throw new InvalidArgumentException('Thời điểm kết thúc phải sau thời điểm bắt đầu.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        // All writers lock this product, including draft quotation and unit changes.
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE'); $stmt->execute([(int)($input['product_id'] ?? 0)]);
        $product = $stmt->fetchColumn(); if (!$product) throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        $sql = 'SELECT id FROM product_prices WHERE product_id=:product AND price_type=:type AND (valid_to IS NULL OR valid_to>:start)';
        $params = ['product'=>$product,'type'=>$type,'start'=>$from];
        if ($to !== null) { $sql .= ' AND valid_from<:end'; $params['end'] = $to; }
        $stmt = $pdo->prepare($sql . ' FOR UPDATE'); $stmt->execute($params);
        if ($stmt->fetch()) throw new InvalidArgumentException('Khoảng giá bị chồng lấn. Khoảng đang mở phải được kết thúc trước khi tạo giá kế tiếp; bài mẫu chỉ hỗ trợ thêm khoảng mới không chồng lấn.');
        $pdo->prepare('INSERT INTO product_prices(product_id,price_type,amount,valid_from,valid_to,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$product,$type,$amount,$from,$to,$actorId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/**
 * Lập mức giá kế tiếp: đóng khoảng đang mở tại T và tạo khoảng mới bắt đầu đúng T,
 * trong CÙNG MỘT transaction (hoặc cả hai thành công, hoặc không đổi gì).
 * Quy tắc: T phải ở tương lai (sau thời điểm hiện tại) và sau thời điểm bắt đầu của khoảng đang mở.
 * Vì chứng từ không được lập ở tương lai nên không phiếu nào (kể cả nháp) rơi vào [T, ...);
 * đơn giá đã chụp trên dòng phiếu không bị đổi.
 */
function price_set_next(array $input, int $actorId): void {
    require_permission('prices.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $type = $input['price_type'] ?? '';
    if (!in_array($type, ['purchase','sale'], true)) throw new InvalidArgumentException('Loại giá không hợp lệ.');
    $amount = trim($input['amount'] ?? '');
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) throw new InvalidArgumentException('Giá phải không âm, tối đa 13 chữ số và 2 chữ số thập phân (dấu chấm).');
    $start = valid_datetime($input['valid_from'] ?? '');
    if ($start <= date('Y-m-d H:i:s')) throw new InvalidArgumentException('Thời điểm áp dụng giá mới phải ở tương lai.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        // Cùng khóa sản phẩm với price_create() và document_create(): các thao tác về giá/phiếu của sản phẩm này tuần tự hóa.
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE'); $stmt->execute([(int)($input['product_id'] ?? 0)]);
        $product = $stmt->fetchColumn(); if (!$product) throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        // Đọc khoảng đang mở SAU khi có khóa: request đến sau thấy kết quả của request trước.
        $stmt = $pdo->prepare('SELECT id,valid_from FROM product_prices WHERE product_id=? AND price_type=? AND valid_to IS NULL ORDER BY valid_from DESC FOR UPDATE');
        $stmt->execute([$product,$type]); $open = $stmt->fetchAll();
        if (count($open) === 0) throw new InvalidArgumentException('Không có khoảng giá đang mở của loại giá này. Hãy dùng form "Thêm khoảng giá".');
        if (count($open) > 1) throw new InvalidArgumentException('Dữ liệu giá không nhất quán: có nhiều khoảng đang mở. Cần kiểm tra thủ công.');
        if ($start <= $open[0]['valid_from']) throw new InvalidArgumentException('Thời điểm áp dụng phải sau thời điểm bắt đầu của khoảng đang mở (' . $open[0]['valid_from'] . ').');
        $close = $pdo->prepare('UPDATE product_prices SET valid_to=? WHERE id=? AND valid_to IS NULL');
        $close->execute([$start,$open[0]['id']]);
        if ($close->rowCount() !== 1) throw new InvalidArgumentException('Khoảng giá đã bị thay đổi bởi người khác. Hãy tải lại trang.');
        $pdo->prepare('INSERT INTO product_prices(product_id,price_type,amount,valid_from,valid_to,created_by) VALUES(?,?,?,?,NULL,?)')
            ->execute([$product,$type,$amount,$start,$actorId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
