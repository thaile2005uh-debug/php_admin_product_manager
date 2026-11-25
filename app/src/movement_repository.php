<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inventory_repository.php';

/** Chuẩn hóa bộ lọc sổ biến động; trả về [bộ lọc sạch, danh sách lỗi]. */
function movement_filters(array $input): array {
    $filters = [
        'product_id'   => max(0, (int)($input['product_id'] ?? 0)),
        'warehouse_id' => max(0, (int)($input['warehouse_id'] ?? 0)),
        'date_from'    => '',
        'date_to'      => '',
    ];
    $errors = [];
    foreach (['date_from' => 'Từ ngày', 'date_to' => 'Đến ngày'] as $key => $label) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) $filters[$key] = $value;
        else $errors[] = $label . ' không hợp lệ (định dạng YYYY-MM-DD).';
    }
    if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
        $errors[] = '"Từ ngày" không được lớn hơn "Đến ngày".';
        $filters['date_from'] = $filters['date_to'] = '';
    }
    return [$filters, $errors];
}

/**
 * Sổ biến động kho có phân trang trên database (COUNT + LIMIT/OFFSET).
 * Chỉ đọc dữ liệu, không thay đổi stock_balances hay stock_movements.
 * Lọc theo ngày phiếu (document_date): [date_from 00:00, date_to + 1 ngày).
 */
function movement_paginate(array $filters, int $page = 1, int $perPage = 20): array {
    $where = ['1=1']; $params = [];
    if ($filters['product_id'] > 0)   { $where[] = 'm.product_id=:product';  $params['product'] = $filters['product_id']; }
    if ($filters['warehouse_id'] > 0) { $where[] = 'w.id=:warehouse';        $params['warehouse'] = $filters['warehouse_id']; }
    if ($filters['date_from'] !== '') { $where[] = 'd.document_date >= :date_from'; $params['date_from'] = $filters['date_from'] . ' 00:00:00'; }
    if ($filters['date_to'] !== '') {
        $where[] = 'd.document_date < :date_to';
        $params['date_to'] = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    $joins = ' FROM stock_movements m
        JOIN inventory_documents d ON d.id=m.document_id
        JOIN products p ON p.id=m.product_id
        JOIN units u ON u.id=p.unit_id
        JOIN warehouse_locations loc ON loc.id=m.location_id
        JOIN warehouses w ON w.id=loc.warehouse_id
        JOIN product_lots lot ON lot.id=m.lot_id';
    $condition = ' WHERE ' . implode(' AND ', $where);

    // Cùng $joins + $condition với truy vấn dữ liệu nên tổng khớp với danh sách.
    $count = inventory_query('SELECT COUNT(*) AS n' . $joins . $condition, $params)[0]['n'];
    $result = pagination_meta((int)$count, $page, $perPage);
    // m.id là khóa chính => thứ tự ổn định, không lặp/mất dòng giữa các trang.
    $result['items'] = inventory_query('SELECT m.id,m.quantity,m.created_at,d.id AS document_id,d.number,d.document_type,
        d.document_date,p.name AS product_name,p.sku,u.name AS unit_name,w.name AS warehouse_name,
        loc.code AS location_code,lot.code AS lot_code' . $joins . $condition .
        ' ORDER BY m.id DESC LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'], $params);
    // Tổng nhập/xuất của TOÀN BỘ kết quả lọc, tách theo đơn vị cơ sở.
    $result['totals'] = inventory_query('SELECT u.name AS unit_name,
        COALESCE(SUM(CASE WHEN m.quantity>0 THEN m.quantity ELSE 0 END),0) AS qty_in,
        COALESCE(SUM(CASE WHEN m.quantity<0 THEN -m.quantity ELSE 0 END),0) AS qty_out' .
        $joins . $condition . ' GROUP BY u.id,u.name ORDER BY u.name', $params);
    return $result;
}

function movement_product_options(): array {
    return inventory_query('SELECT id,name,sku FROM products ORDER BY name,id');
}
