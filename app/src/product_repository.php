<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function product_paginate(string $keyword = '', int $categoryId = 0, int $page = 1, int $perPage = 20): array {
    $where = ['1=1']; $params = [];
    if ($keyword !== '') { $where[] = '(p.name LIKE :name OR p.sku LIKE :sku)'; $params['name'] = $params['sku'] = '%' . $keyword . '%'; }
    if ($categoryId > 0) { $where[] = 'p.category_id=:category'; $params['category'] = $categoryId; }
    $condition = ' WHERE ' . implode(' AND ', $where);
    $stmt = db()->prepare('SELECT COUNT(*) FROM products p' . $condition); $stmt->execute($params);
    $result = pagination_meta((int)$stmt->fetchColumn(), $page, $perPage);
    $stmt = db()->prepare('SELECT p.*,c.name AS category_name,u.name AS unit_name,
        COALESCE(b.quantity,0) AS stock_quantity FROM products p JOIN categories c ON c.id=p.category_id
        LEFT JOIN units u ON u.id=p.unit_id
        LEFT JOIN (SELECT product_id,SUM(quantity) AS quantity FROM stock_balances GROUP BY product_id) b ON b.product_id=p.id' .
        $condition . ' ORDER BY p.id DESC LIMIT ' . $result['per_page'] . ' OFFSET ' . $result['offset']);
    $stmt->execute($params); $result['items'] = $stmt->fetchAll();
    return $result;
}
function product_find(int $id): ?array {
    $stmt = db()->prepare('SELECT p.*,c.name AS category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?');
    $stmt->execute([$id]); return $stmt->fetch() ?: null;
}
function product_validate(array $input): array {
    $errors = [];
    $nameLength = is_string($input['name'] ?? null) ? preg_match_all('/./us', trim($input['name'])) : false;
    $skuLength = is_string($input['sku'] ?? '') ? preg_match_all('/./us', trim($input['sku'] ?? '')) : false;
    if ($nameLength === false || $nameLength < 1 || $nameLength > 200) $errors[] = 'Tên sản phẩm không hợp lệ (từ 1 đến 200 ký tự UTF-8).';
    if ($skuLength === false || $skuLength > 100) $errors[] = 'SKU không hợp lệ (tối đa 100 ký tự UTF-8).';
    $stmt = db()->prepare('SELECT COUNT(*) FROM categories WHERE id=?'); $stmt->execute([(int)($input['category_id'] ?? 0)]);
    if (!(int)$stmt->fetchColumn()) $errors[] = 'Danh mục không tồn tại.';
    $stmt = db()->prepare('SELECT COUNT(*) FROM units WHERE id=?'); $stmt->execute([(int)($input['unit_id'] ?? 0)]);
    if (!(int)$stmt->fetchColumn()) $errors[] = 'Vui lòng chọn đơn vị cơ sở.';
    if (filter_var($input['min_stock'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>2147483647]]) === false) $errors[] = 'Ngưỡng tồn phải là số nguyên không âm.';
    return $errors;
}
function product_data(array $input, ?string $image): array {
    return ['category_id'=>(int)$input['category_id'],'unit_id'=>(int)$input['unit_id'], 'name'=>trim($input['name']),
        'sku'=>trim($input['sku'] ?? '') ?: null,'description'=>trim($input['description'] ?? ''),
        'min_stock'=>(int)$input['min_stock'],'image_path'=>$image,'status'=>isset($input['status'])?1:0];
}
function product_create(array $data): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO products(category_id,unit_id,name,sku,description,min_stock,image_path,status)
            VALUES(:category_id,:unit_id,:name,:sku,:description,:min_stock,:image_path,:status)')->execute($data);
        $pdo->prepare("INSERT INTO product_lots(product_id,code) VALUES(?,'NOLOT')")->execute([(int)$pdo->lastInsertId()]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function product_update(int $id, array $data): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT unit_id FROM products WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $old = $stmt->fetch();
        if (!$old) throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        if ((int)$old['unit_id'] !== (int)$data['unit_id']) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_lines WHERE product_id=?'); $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn()) throw new InvalidArgumentException('Sản phẩm đã có dòng phiếu; không được thay đổi đơn vị cơ sở.');
        }
        $pdo->prepare('UPDATE products SET category_id=:category_id,unit_id=:unit_id,name=:name,sku=:sku,description=:description,
            min_stock=:min_stock,image_path=:image_path,status=:status WHERE id=:id')->execute($data + ['id'=>$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function product_delete(int $id): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE')->execute([$id]);
        foreach (['inventory_lines','product_prices','stock_balances'] as $table) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE product_id=?'); $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn()) throw new InvalidArgumentException('Sản phẩm đã có giá hoặc nghiệp vụ kho; hãy ẩn thay vì xóa.');
        }
        $pdo->prepare('DELETE FROM product_lots WHERE product_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function product_count(): int { return (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn(); }
