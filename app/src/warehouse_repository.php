<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';
function warehouse_tabs(): array { return ['warehouses'=>'Kho','locations'=>'Khu / kệ / ô','lots'=>'Lô hàng','units'=>'Đơn vị sản phẩm']; }
function warehouse_options(): array {
    return ['warehouses'=>db()->query('SELECT * FROM warehouses ORDER BY code')->fetchAll(),
        'products'=>db()->query('SELECT id,name,sku FROM products ORDER BY name')->fetchAll(),
        'units'=>db()->query('SELECT * FROM units ORDER BY name')->fetchAll()];
}
function warehouse_paginate(string $tab, int $page = 1, int $perPage = 20): array {
    $queries = [
        'warehouses'=>['warehouses','SELECT id,code,name FROM warehouses ORDER BY code'],
        'units'=>['units','SELECT id,code,name FROM units ORDER BY code'],
        'locations'=>['warehouse_locations','SELECT loc.*,w.name AS warehouse_name FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id ORDER BY w.code,loc.code'],
        'lots'=>['product_lots','SELECT l.*,p.name AS product_name FROM product_lots l JOIN products p ON p.id=l.product_id ORDER BY p.name,l.code']
    ];
    if (!isset($queries[$tab])) throw new InvalidArgumentException('Danh mục kho không hợp lệ.');
    [$table,$sql] = $queries[$tab];
    $result = pagination_meta((int)db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $page, $perPage);
    $result['items'] = db()->query($sql . ' LIMIT ' . $result['per_page'] . ' OFFSET ' . $result['offset'])->fetchAll(); return $result;
}
function warehouse_text(array $input, string $key, int $max, bool $required = true): string {
    $value = trim($input[$key] ?? ''); $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $length === 0) || $length > $max) throw new InvalidArgumentException('Trường ' . $key . ' không hợp lệ, tối đa ' . $max . ' ký tự.');
    return $value;
}
function warehouse_create(string $tab, array $input): void {
    require_permission('warehouse.manage');
    if (!isset(warehouse_tabs()[$tab])) throw new InvalidArgumentException('Danh mục không hợp lệ.');
    $code = warehouse_text($input, 'code', $tab === 'lots' ? 100 : ($tab === 'locations' ? 50 : 30));
    if ($tab === 'warehouses' || $tab === 'units') {
        $name = warehouse_text($input,'name',$tab === 'units' ? 100 : 150);
        db()->prepare('INSERT INTO ' . $tab . '(code,name) VALUES(?,?)')->execute([$code,$name]);
    } elseif ($tab === 'locations') {
        db()->prepare('INSERT INTO warehouse_locations(warehouse_id,code,zone,rack,bin) VALUES(?,?,?,?,?)')->execute([
            (int)($input['warehouse_id'] ?? 0),$code,warehouse_text($input,'zone',50,false),warehouse_text($input,'rack',50,false),warehouse_text($input,'bin',50,false)]);
    } else {
        $expires = trim($input['expires_on'] ?? '');
        if ($expires !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d',$expires);
            if (!$date || $date->format('Y-m-d') !== $expires) throw new InvalidArgumentException('Ngày hết hạn không hợp lệ.');
        }
        db()->prepare('INSERT INTO product_lots(product_id,code,expires_on) VALUES(?,?,?)')->execute([(int)($input['product_id'] ?? 0),$code,$expires ?: null]);
    }
}
