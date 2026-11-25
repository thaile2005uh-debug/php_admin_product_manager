<?php
/**
 * Run only against a freshly initialized disposable MySQL database:
 * DB_DATABASE=admin_product_test php tests/integration.php
 * The runner creates isolated fixtures and removes those fixtures in finally.
 * Import database/init.sql into that test DB before running (rewrite its USE).
 * For a copied runner inside Docker, set TEST_APP_DIR=/var/www/html.
 */
if (PHP_SAPI !== 'cli' || getenv('DB_DATABASE') !== 'admin_product_test') {
    fwrite(STDERR, "Refusing to run: CLI and DB_DATABASE=admin_product_test are required.\n");
    exit(2);
}
date_default_timezone_set('Asia/Ho_Chi_Minh');
$_SERVER['REQUEST_METHOD'] = 'CLI';
$testAppDir = getenv('TEST_APP_DIR') ?: __DIR__ . '/../app';
require_once $testAppDir . '/src/inventory_repository.php';
require_once $testAppDir . '/src/price_repository.php';
require_once $testAppDir . '/src/product_repository.php';

$checks = 0;
function test_ok(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo "PASS: $message\n";
}
function test_scalar(string $sql, array $params = []) {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn();
}
function test_reject(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException $e) { test_ok(true, $message); return; }
    throw new RuntimeException('FAIL: expected validation rejection: ' . $message);
}
function test_insert(string $sql, array $params): int {
    db()->prepare($sql)->execute($params); return (int)db()->lastInsertId();
}

$documentIds = []; $extraProductIds = []; $productId = $lotId = $otherLotId = $unitId = $roleId = $userId = 0;
$prefix = 'TEST-' . strtoupper(bin2hex(random_bytes(4)));
$actorId = (int)test_scalar('SELECT a.id FROM admins a JOIN user_roles ur ON ur.user_id=a.id JOIN roles r ON r.id=ur.role_id WHERE r.code=\'ADMIN\' ORDER BY a.id LIMIT 1');
if (!$actorId) $actorId = (int)test_scalar('SELECT id FROM admins ORDER BY id LIMIT 1');
$_SESSION['admin_id'] = $actorId;

try {
    test_ok(test_scalar('SELECT DATABASE()')==='admin_product_test', 'connection points to the disposable test database');
    test_ok(can('receipts.create') && can('receipts.post') && can('issues.create') && can('issues.post'), 'seed administrator can execute warehouse examples');
    test_ok(permission_decide(['inventory.view'], [], 'inventory.view'), 'group grant allows');
    test_ok(!permission_decide(['inventory.view'], ['inventory.view'=>'deny'], 'inventory.view'), 'user deny overrides group grant');
    test_ok(permission_decide([], ['inventory.view'=>'allow'], 'inventory.view'), 'user allow works without a group grant');
    test_ok(!permission_decide([], [], 'inventory.view'), 'missing grant defaults to deny');

    // Migration must preserve all old quantities/prices and stop exposing writable product prices.
    $legacy = inventory_query('SELECT * FROM legacy_product_stock_prices ORDER BY product_id');
    test_ok(count($legacy)>0, 'legacy migration snapshot exists');
    foreach ($legacy as $row) {
        $quantity = (int)test_scalar('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE product_id=?', [$row['product_id']]);
        test_ok($quantity === (int)$row['quantity'], 'opening quantity preserved for product ' . $row['product_id']);
        foreach (['purchase','sale'] as $kind) {
            $price = test_scalar('SELECT amount FROM product_prices WHERE product_id=? AND price_type=? AND valid_from=\'2000-01-01 00:00:00\'', [$row['product_id'],$kind]);
            test_ok($price !== false && inventory_price_cents((string)$price) === inventory_price_cents((string)$row['unit_price']), 'legacy ' . $kind . ' price preserved for product ' . $row['product_id']);
        }
    }
    $columns = array_column(inventory_query('SHOW COLUMNS FROM products'), 'Field');
    test_ok(!in_array('quantity',$columns,true) && !in_array('unit_price',$columns,true), 'product no longer owns stock quantity or price');
    $mismatch = test_scalar('SELECT COUNT(*) FROM stock_balances b LEFT JOIN (SELECT product_id,location_id,lot_id,SUM(quantity) AS quantity FROM stock_movements GROUP BY product_id,location_id,lot_id) m
        ON m.product_id=b.product_id AND m.location_id=b.location_id AND m.lot_id=b.lot_id WHERE b.quantity<>COALESCE(m.quantity,0)');
    test_ok((int)$mismatch===0, 'opening balances reconcile with stock ledger');

    // Verify precedence using real database rows as well as the pure decision helper.
    $userId = test_insert('INSERT INTO admins(username,email,password_hash,full_name) VALUES(?,?,?,?)', [$prefix,$prefix.'@example.test',hash('sha256','test-only'),$prefix]);
    $roleId = test_insert('INSERT INTO roles(code,name) VALUES(?,?)', [$prefix,$prefix]);
    $viewId = (int)test_scalar('SELECT id FROM permissions WHERE code=\'inventory.view\'');
    db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$roleId,$viewId]);
    db()->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)')->execute([$userId,$roleId]);
    test_ok(permission_for_user($userId,'inventory.view'), 'database group grant resolves');
    db()->prepare('INSERT INTO user_permissions(user_id,permission_id,effect) VALUES(?,?,\'deny\')')->execute([$userId,$viewId]);
    test_ok(!permission_for_user($userId,'inventory.view'), 'database user deny overrides group');
    db()->prepare('UPDATE user_permissions SET effect=\'allow\' WHERE user_id=? AND permission_id=?')->execute([$userId,$viewId]);
    db()->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
    test_ok(permission_for_user($userId,'inventory.view'), 'database user allow survives removal from groups');
    test_ok(!permission_for_user($userId,'issues.post'), 'unassigned action remains denied');

    $unitId = test_insert('INSERT INTO units(code,name) VALUES(?,?)', [$prefix,$prefix.' unit']);
    $categoryId = (int)test_scalar('SELECT id FROM categories ORDER BY id LIMIT 1');
    $locationId = (int)test_scalar('SELECT id FROM warehouse_locations ORDER BY id LIMIT 1');
    $productId = test_insert('INSERT INTO products(category_id,name,sku,unit_id,min_stock,status) VALUES(?,?,?,?,?,1)', [$categoryId,$prefix,$prefix,$unitId,4]);
    $differentUnit=(int)test_scalar('SELECT id FROM units WHERE id<>? ORDER BY id LIMIT 1',[$unitId]);
    $changedUnitData=product_data(['category_id'=>$categoryId,'unit_id'=>$differentUnit,'name'=>$prefix,'sku'=>$prefix,'description'=>'','min_stock'=>4,'status'=>1],null);
    product_update($productId,$changedUnitData);
    test_ok((int)test_scalar('SELECT unit_id FROM products WHERE id=?',[$productId])===$differentUnit, 'base unit may change before any inventory line exists');
    $originalUnitData=array_replace($changedUnitData,['unit_id'=>$unitId]);
    product_update($productId,$originalUnitData);
    $noBalanceLowStock = array_filter(inventory_dashboard()['low_stock'], static fn($row)=>(int)$row['id']===$productId && (int)$row['quantity']===0);
    test_ok(count($noBalanceLowStock)===1, 'product without any balance row appears as zero in low-stock alerts');
    for ($index=1; $index<=4; $index++) {
        $extraProductIds[] = test_insert('INSERT INTO products(category_id,name,sku,unit_id,min_stock,status) VALUES(?,?,?,?,0,1)',
            [$categoryId,$prefix.' page '.$index,$prefix.'-P'.$index,$unitId]);
    }
    $firstPage = product_paginate($prefix,$categoryId,1,2);
    $lastPage = product_paginate($prefix,$categoryId,999,2);
    test_ok($firstPage['total']===5 && count($firstPage['items'])===2 && $firstPage['pages']===3, 'pagination count covers all matches while LIMIT bounds the current page');
    test_ok($lastPage['total']===5 && $lastPage['page']===3 && count($lastPage['items'])===1, 'out-of-range page clamps to the last page without changing filtered total');
    test_ok(product_paginate($prefix,$categoryId+999999,1,2)['total']===0, 'pagination count uses the same category filter as page rows');
    $lotId = test_insert('INSERT INTO product_lots(product_id,code) VALUES(?,?)', [$productId,'NOLOT']);
    $otherLotId = test_insert('INSERT INTO product_lots(product_id,code,expires_on) VALUES(?,?,?)', [$productId,'EXPIRY',(new DateTimeImmutable('+30 days'))->format('Y-m-d')]);
    $pastPriceInput=['product_id'=>$productId,'price_type'=>'purchase','amount'=>'100.00','valid_from'=>'2000-01-01T00:00','valid_to'=>'2020-01-01T00:00'];
    $currentPriceInput=['product_id'=>$productId,'price_type'=>'purchase','amount'=>'120.00','valid_from'=>'2020-01-01T00:00','valid_to'=>''];
    price_create($pastPriceInput,$actorId);
    price_create($currentPriceInput,$actorId);
    test_ok((int)test_scalar('SELECT COUNT(*) FROM product_prices WHERE product_id=? AND price_type=\'purchase\'',[$productId])===2, 'price API allows adjacent half-open intervals sharing exactly one boundary');
    $currentPriceId=(int)test_scalar('SELECT id FROM product_prices WHERE product_id=? AND price_type=\'purchase\' AND valid_from=\'2020-01-01 00:00:00\'',[$productId]);
    $overlapInput=array_replace($pastPriceInput,['valid_from'=>'2019-01-01T00:00','valid_to'=>'2021-01-01T00:00']);
    test_reject(static fn()=>price_create($overlapInput,$actorId), 'price API rejects a range overlapping two existing intervals');
    test_reject(static fn()=>price_create(array_replace($currentPriceInput,['valid_from'=>'2030-01-01T00:00']),$actorId), 'price API rejects a new open interval overlapping an existing open interval');
    test_reject(static fn()=>price_create(array_replace($pastPriceInput,['amount'=>'-1.00']),$actorId), 'price API rejects a negative amount');
    test_reject(static fn()=>price_create(array_replace($pastPriceInput,['amount'=>'1.234']),$actorId), 'price API rejects more than two fractional digits');
    test_reject(static fn()=>price_create(array_replace($pastPriceInput,['amount'=>'abc']),$actorId), 'price API rejects a non-numeric amount');
    test_reject(static fn()=>price_create(array_replace($pastPriceInput,['valid_to'=>'2000-01-01T00:00']),$actorId), 'price API rejects an empty time interval');
    test_reject(static fn()=>price_create(array_replace($pastPriceInput,['valid_to'=>'1999-01-01T00:00']),$actorId), 'price API rejects reversed interval endpoints');
    test_reject(static fn()=>price_create($pastPriceInput,$actorId+9999), 'price API rejects a forged actor');
    test_ok((int)test_scalar('SELECT COUNT(*) FROM product_prices WHERE product_id=?',[$productId])===2, 'rejected price writes leave both valid intervals unchanged');
    price_create(['product_id'=>$productId,'price_type'=>'sale','amount'=>'200.00','valid_from'=>'2000-01-01T00:00','valid_to'=>''],$actorId);

    $makeInput = static function(string $type, array $quantities, string $date='2020-01-01T00:00', ?int $lot=null) use ($productId,$locationId,$lotId,$prefix): array {
        $lines=[];
        foreach ($quantities as $quantity) $lines[]=['product_id'=>$productId,'location_id'=>$locationId,'lot_id'=>$lot ?? $lotId,'quantity'=>$quantity];
        return ['document_type'=>$type,'document_date'=>$date,'partner_name'=>$prefix,'note'=>'Integration fixture','lines'=>$lines];
    };
    $create = static function(array $input) use (&$documentIds,$actorId): int {
        $id=document_create($input,$actorId); $documentIds[]=$id; return $id;
    };
    $balance = static function(?int $lot=null) use ($productId,$locationId,$lotId): int {
        return (int)test_scalar('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE product_id=? AND location_id=? AND lot_id=?', [$productId,$locationId,$lot ?? $lotId]);
    };
    $beforeBoundary = $create($makeInput('receipt',[1],'2019-12-31T23:59'));
    test_ok(inventory_price_cents((string)document_find($beforeBoundary)['lines'][0]['unit_price'])===10000, 'previous price used before exclusive boundary');
    test_reject(static fn()=>product_update($productId,$changedUnitData), 'product API forbids changing base unit after even a draft line exists');
    test_ok((int)test_scalar('SELECT unit_id FROM products WHERE id=?',[$productId])===$unitId, 'rejected unit change preserves the original base unit');
    product_update($productId,array_replace($originalUnitData,['name'=>$prefix.' same-unit rename']));
    test_ok(test_scalar('SELECT name FROM products WHERE id=?',[$productId])===$prefix.' same-unit rename', 'same-unit metadata updates remain allowed after a line exists');
    product_update($productId,$originalUnitData);
    document_cancel($beforeBoundary,$actorId);
    test_ok($balance()===0, 'cancelled draft never changes stock');
    $receiptId = $create($makeInput('receipt',[2,3]));
    test_ok($balance()===0, 'receipt draft does not change stock');
    test_ok(inventory_price_cents((string)document_find($receiptId)['lines'][0]['unit_price'])===12000, 'new price used exactly at valid_from boundary');
    db()->prepare('UPDATE product_prices SET amount=\'125.00\' WHERE id=?')->execute([$currentPriceId]);
    test_ok(inventory_price_cents((string)document_find($receiptId)['lines'][0]['unit_price'])===12000, 'draft price snapshot survives later price changes');
    document_post($receiptId,$actorId);
    test_ok($balance()===5, 'posting receipt aggregates duplicate stock keys and increments by five');
    test_ok((int)test_scalar('SELECT COUNT(*) FROM stock_movements WHERE document_id=?',[$receiptId])===2, 'every posted line has exactly one ledger entry');
    test_reject(static fn()=>document_post($receiptId,$actorId), 'repeat receipt posting rejected');
    test_ok($balance()===5, 'repeat posting does not add stock twice');

    $oversellId = $create($makeInput('issue',[3,3]));
    test_reject(static fn()=>document_post($oversellId,$actorId), 'duplicate issue lines cannot bypass stock check');
    test_ok($balance()===5 && document_find($oversellId)['status']==='draft', 'insufficient issue remains draft with unchanged stock');
    test_ok((int)test_scalar('SELECT COUNT(*) FROM stock_movements WHERE document_id=?',[$oversellId])===0, 'failed posting leaves no ledger rows');

    $issueId = $create($makeInput('issue',[2,1]));
    test_ok(inventory_price_cents((string)document_find($issueId)['lines'][0]['unit_price'])===20000, 'issue uses sale history rather than purchase history');
    db()->prepare('UPDATE products SET name=? WHERE id=?')->execute([$prefix.' renamed',$productId]);
    document_post($issueId,$actorId);
    test_ok($balance()===2, 'posting issue decrements quantity once');
    test_ok(document_find($issueId)['lines'][0]['product_name']===$prefix, 'printed product name remains original snapshot');
    test_reject(static fn()=>document_post($issueId,$actorId), 'repeat issue posting rejected');
    test_reject(static fn()=>document_cancel($issueId,$actorId), 'posted issue cannot be cancelled');
    test_ok($balance()===2, 'rejected repeat/cancellation leaves stock unchanged');

    $atomicInput = $makeInput('issue',[1]);
    $atomicInput['lines'][]=['product_id'=>$productId,'location_id'=>$locationId,'lot_id'=>$otherLotId,'quantity'=>1];
    $atomicId = $create($atomicInput);
    test_reject(static fn()=>document_post($atomicId,$actorId), 'one insufficient stock key rolls back a whole multi-key issue');
    test_ok($balance()===2 && $balance($otherLotId)===0, 'earlier key update rolled back atomically');

    $expiryReceipt = $create($makeInput('receipt',[1],'2020-01-01T00:00',$otherLotId));
    document_post($expiryReceipt,$actorId);
    $expiryIssue = $create($makeInput('issue',[1],'2020-01-01T00:00',$otherLotId));
    db()->prepare('UPDATE product_lots SET expires_on=? WHERE id=?')->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d'),$otherLotId]);
    test_reject(static fn()=>document_post($expiryIssue,$actorId), 'expiry is checked again on posting against today, even for a backdated document');
    test_ok($balance($otherLotId)===1, 'expiry rejection leaves stock intact');
    test_reject(static fn()=>document_create($makeInput('receipt',[0]),$actorId), 'zero quantity rejected');
    test_reject(static fn()=>document_create($makeInput('receipt',['1.5']),$actorId), 'fractional quantity rejected for base-unit sample');
    test_reject(static fn()=>document_create($makeInput('receipt',[1]),$actorId+9999), 'forged creator actor ID rejected');
    test_reject(static fn()=>document_post($oversellId,$actorId+9999), 'forged posting actor ID rejected');
    test_reject(static fn()=>document_cancel($oversellId,$actorId+9999), 'forged cancellation actor ID rejected');

    $dashboard = inventory_dashboard();
    $unitRows = array_values(array_filter($dashboard['quantity_by_unit'],static fn($row)=>(int)$row['unit_id']===$unitId));
    test_ok(count($unitRows)===1 && (int)$unitRows[0]['quantity']===3, 'dashboard reports the test unit separately from other product units');
    test_ok((int)$dashboard['low_stock_count']>=1, 'low stock summary includes the sample below its threshold');
    $allBalance = (int)test_scalar('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE product_id=?',[$productId]);
    $allLedger = (int)test_scalar('SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE product_id=?',[$productId]);
    test_ok($allBalance===$allLedger, 'all fixture balances reconcile with the posted ledger');
    echo "\n$checks checks passed.\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
    foreach ($documentIds as $documentId) {
        db()->prepare('DELETE FROM stock_movements WHERE document_id=?')->execute([$documentId]);
        db()->prepare('DELETE FROM inventory_lines WHERE document_id=?')->execute([$documentId]);
        db()->prepare('DELETE FROM inventory_documents WHERE id=?')->execute([$documentId]);
    }
    if ($productId) {
        db()->prepare('DELETE FROM stock_balances WHERE product_id=?')->execute([$productId]);
        db()->prepare('DELETE FROM product_prices WHERE product_id=?')->execute([$productId]);
        db()->prepare('DELETE FROM product_lots WHERE product_id=?')->execute([$productId]);
        db()->prepare('DELETE FROM products WHERE id=?')->execute([$productId]);
    }
    foreach ($extraProductIds as $extraProductId) db()->prepare('DELETE FROM products WHERE id=?')->execute([$extraProductId]);
    if ($unitId) db()->prepare('DELETE FROM units WHERE id=?')->execute([$unitId]);
    if ($userId) {
        db()->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM admins WHERE id=?')->execute([$userId]);
    }
    if ($roleId) {
        db()->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
        db()->prepare('DELETE FROM roles WHERE id=?')->execute([$roleId]);
    }
}
