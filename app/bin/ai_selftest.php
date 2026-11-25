<?php
// Tự kiểm tra (không cần MySQL/Ollama): bộ kiểm tra SELECT và bộ truy hồi RAG.
// Chạy: docker compose exec php-app php /var/www/html/bin/ai_selftest.php
require __DIR__ . '/../src/ai_service.php';
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $fail++; }
function rejects(string $sql): bool { try { ai_validate_select($sql); return false; } catch (InvalidArgumentException $e) { return true; } }

echo "== Kiểm tra SQL ==\n";
check('SELECT hợp lệ được chấp nhận và tự thêm LIMIT 200', ai_validate_select('SELECT p.id FROM products p') === 'SELECT p.id FROM products p LIMIT 200');
check('Giữ nguyên LIMIT nhỏ hơn 200', ai_validate_select('SELECT id FROM products LIMIT 10;') === 'SELECT id FROM products LIMIT 10');
check('JOIN các bảng cho phép', !rejects('SELECT p.name, SUM(b.quantity) q FROM stock_balances b JOIN products p ON p.id=b.product_id GROUP BY p.name'));
check('Chuỗi chứa chữ "update" không bị chặn nhầm', !rejects("SELECT id FROM inventory_documents WHERE partner_name = 'update corp'"));
check('Cột created_at không bị chặn nhầm là CREATE', !rejects("SELECT DATE_FORMAT(created_at, '%Y-%m') m FROM stock_movements"));
check('Từ chối LIMIT lớn hơn 200', rejects('SELECT id FROM products LIMIT 1000'));
check('Từ chối DELETE', rejects('DELETE FROM products'));
check('Từ chối UPDATE', rejects('UPDATE products SET name=1'));
check('Từ chối DROP TABLE', rejects('DROP TABLE products'));
check('Từ chối nhiều câu lệnh', rejects('SELECT 1 FROM products; DROP TABLE products'));
check('Từ chối bảng admins', rejects('SELECT username, password_hash FROM admins'));
check('Từ chối bảng quyền user_permissions', rejects('SELECT * FROM user_permissions'));
check('Từ chối nối bảng bằng dấu phẩy tới bảng ngoài danh sách', rejects('SELECT * FROM products p, admins a'));
check('Từ chối bảng có tiền tố database', rejects('SELECT * FROM mysql.user'));
check('Từ chối information_schema', rejects('SELECT table_name FROM information_schema.tables'));
check('Từ chối INTO OUTFILE', rejects("SELECT id FROM products INTO OUTFILE '/tmp/x'"));
check('Từ chối SLEEP', rejects('SELECT SLEEP(10) FROM products'));
check('Từ chối chú thích', rejects('SELECT id FROM products -- bo qua'));
check('Từ chối FOR UPDATE', rejects('SELECT id FROM products FOR UPDATE'));
check('Từ chối biến phiên @', rejects('SELECT @@version FROM products'));
check('Từ chối câu rỗng', rejects('   ;  '));
check('Từ chối câu không bắt đầu bằng SELECT', rejects('WITH x AS (SELECT 1) SELECT * FROM x'));

echo "== Truy hồi RAG ==\n";
$chunks = ai_schema_chunks();
check('Tách được 11 đoạn bảng', count($chunks['tables']) === 11);
check('Có phần quy tắc chung', str_contains($chunks['rules'], 'SELECT'));
$cases = [
    'Tồn hiện tại theo kho và đơn vị' => ['stock_balances', 'warehouses', 'warehouse_locations', 'units'],
    'Top 10 sản phẩm có tổng tồn nhiều nhất' => ['products', 'stock_balances'],
    'Sản phẩm nào có tồn thấp hơn mức tồn tối thiểu' => ['products', 'stock_balances'],
    'Tổng nhập và xuất đã ghi sổ theo tháng' => ['stock_movements', 'inventory_documents'],
    'Giá bán hiện tại của từng sản phẩm' => ['product_prices', 'products'],
    'Lô nào sắp hết hạn' => ['product_lots'],
];
foreach ($cases as $question => $expected) {
    $selected = ai_retrieve($question, $chunks);
    $missing = array_diff($expected, $selected);
    check($question . ' -> ' . implode(', ', $selected), !$missing);
}
check('Câu hỏi không khớp gì thì đưa toàn bộ schema', count(ai_retrieve('xyz qwerty', $chunks)) === 11);
$messages = ai_build_messages('Tồn theo kho', $chunks, ai_retrieve('Tồn theo kho', $chunks));
check('Prompt có quy tắc, bảng liên quan và câu hỏi', str_contains($messages[0]['content'], 'Relevant tables') && $messages[1]['content'] === 'Tồn theo kho');
echo $fail === 0 ? "TẤT CẢ ĐẠT\n" : "CÓ $fail CA LỖI\n";
exit($fail === 0 ? 0 : 1);
