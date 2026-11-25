<?php
// Hỏi dữ liệu kho bằng AI: hàng đợi RabbitMQ + RAG (truy hồi schema) + Ollama local + kiểm tra SELECT.
const AI_MAX_ROWS = 200;
const AI_VENDOR = '/opt/ai/vendor/autoload.php';
const AI_TABLES = ['categories','products','units','warehouses','warehouse_locations','product_lots',
    'product_prices','inventory_documents','inventory_lines','stock_balances','stock_movements'];

function ai_env(string $key, string $default = ''): string {
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function ai_connect(string $host, string $port, string $database, string $user, string $pass): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}

// Kết nối tài khoản ứng dụng (ghi ai_jobs). Tự nối lại nếu worker chạy lâu bị MySQL ngắt kết nối.
function ai_app_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        try { $pdo->query('SELECT 1'); return $pdo; } catch (Throwable $e) { $pdo = null; }
    }
    $pdo = ai_connect(ai_env('DB_HOST', 'mysql'), ai_env('DB_PORT', '3306'), ai_env('DB_DATABASE', 'admin_product_db'),
        ai_env('DB_USERNAME', 'admin_user'), ai_env('DB_PASSWORD', 'admin_password'));
    return $pdo;
}

// ---------------------------------------------------------------- Công việc (ai_jobs)
function ai_is_uuid(string $value): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value);
}
function ai_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}
function ai_job_create(int $userId, string $question): string {
    $id = ai_uuid();
    $stmt = ai_app_db()->prepare("INSERT INTO ai_jobs(id,user_id,job_type,payload) VALUES(?,?, 'question', ?)");
    $stmt->execute([$id, $userId, json_encode(['question' => $question], JSON_UNESCAPED_UNICODE)]);
    return $id;
}
function ai_job_active_count(int $userId): int {
    $stmt = ai_app_db()->prepare("SELECT COUNT(*) FROM ai_jobs WHERE user_id=? AND status IN ('queued','running')");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
function ai_job_find(string $id, int $userId = 0): ?array {
    $sql = 'SELECT * FROM ai_jobs WHERE id=?' . ($userId > 0 ? ' AND user_id=?' : '');
    $stmt = ai_app_db()->prepare($sql);
    $stmt->execute($userId > 0 ? [$id, $userId] : [$id]);
    return $stmt->fetch() ?: null;
}
function ai_jobs_recent(int $userId, int $limit = 10): array {
    $stmt = ai_app_db()->prepare('SELECT id,status,payload,created_at FROM ai_jobs WHERE user_id=? AND job_type=\'question\' ORDER BY created_at DESC LIMIT ' . (int)$limit);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $payload = json_decode($row['payload'], true);
        $row['question'] = is_array($payload) ? (string)($payload['question'] ?? '') : '';
    }
    return $rows;
}
function ai_job_fail(string $id, string $message, ?array $partial = null): void {
    $stmt = ai_app_db()->prepare("UPDATE ai_jobs SET status='failed', error_message=?, result_json=?, finished_at=NOW() WHERE id=? AND status IN ('queued','running')");
    $stmt->execute([mb_substr($message, 0, 500), $partial ? json_encode($partial, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null, $id]);
}
function ai_job_public(array $job): array {
    $payload = json_decode($job['payload'] ?? '', true);
    $result = json_decode($job['result_json'] ?? '', true);
    return [
        'id' => $job['id'], 'status' => $job['status'],
        'question' => is_array($payload) ? (string)($payload['question'] ?? '') : '',
        'result' => is_array($result) ? $result : null,
        'error' => $job['error_message'],
        'created_at' => $job['created_at'], 'started_at' => $job['started_at'], 'finished_at' => $job['finished_at'],
    ];
}

// ---------------------------------------------------------------- RabbitMQ
function ai_queue_name(): string { return ai_env('RABBITMQ_QUEUE', 'ai_jobs'); }
function ai_queue_connection(float $readWriteTimeout = 3.0): \PhpAmqpLib\Connection\AMQPStreamConnection {
    if (!is_file(AI_VENDOR)) throw new RuntimeException('Chưa cài php-amqplib. Hãy build lại image: docker compose up -d --build');
    require_once AI_VENDOR;
    return new \PhpAmqpLib\Connection\AMQPStreamConnection(
        ai_env('RABBITMQ_HOST', 'rabbitmq'), (int)ai_env('RABBITMQ_PORT', '5672'),
        ai_env('RABBITMQ_USER', 'guest'), ai_env('RABBITMQ_PASSWORD', 'guest'),
        '/', false, 'AMQPLAIN', null, 'en_US', 5.0, $readWriteTimeout);
}
// Gửi UUID của công việc vào hàng đợi bền vững (durable); nội dung và trạng thái nằm ở MySQL.
function ai_queue_publish(string $jobId): void {
    $connection = ai_queue_connection();
    try {
        $channel = $connection->channel();
        $channel->queue_declare(ai_queue_name(), false, true, false, false);
        $channel->basic_publish(new \PhpAmqpLib\Message\AMQPMessage($jobId, [
            'content_type' => 'text/plain',
            'delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]), '', ai_queue_name());
        $channel->close();
    } finally {
        $connection->close();
    }
}

// ---------------------------------------------------------------- RAG: truy hồi đoạn schema
function ai_fold(string $text): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
                  'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'] as $plain => $chars) {
            foreach (mb_str_split($chars) as $char) $map[$char] = $plain;
        }
    }
    $text = strtr(mb_strtolower($text, 'UTF-8'), $map);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
}
// Tách file schema_context.md: các dòng "- `bảng(...)`" là từng đoạn bảng, phần còn lại là quy tắc luôn đưa vào prompt.
function ai_schema_chunks(?string $path = null): array {
    $path = $path ?? __DIR__ . '/ai/schema_context.md';
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $rules = []; $tables = [];
    foreach ($lines as $line) {
        if (preg_match('/^- `([a-z_]+)\(/', $line, $m)) {
            [$text, $keywords] = array_pad(explode(' Từ khóa:', $line, 2), 2, '');
            $tables[$m[1]] = [
                'name' => $m[1], 'text' => trim($text),
                'keywords' => array_values(array_filter(array_map('ai_fold', explode(',', $keywords)))),
            ];
        } elseif (trim($line) !== '') {
            $rules[] = $line;
        }
    }
    return ['rules' => implode("\n", $rules), 'tables' => $tables];
}
const AI_STOPWORDS = ['theo','cua','cho','cac','nhung','trong','voi','mot','hien','tai','that','which','with','from','that','this','each','and','the','all'];
// Xếp hạng từng đoạn theo từ khóa (cụm từ khớp nguyên từ), tên bảng và mô tả; lấy $limit đoạn cao nhất.
function ai_retrieve(string $question, array $chunks, int $limit = 4): array {
    $q = ' ' . ai_fold($question) . ' ';
    $tokens = array_values(array_unique(array_filter(explode(' ', trim($q)), fn($t) => strlen($t) >= 2)));
    $scores = [];
    foreach ($chunks['tables'] as $name => $chunk) {
        $score = 0;
        foreach ($chunk['keywords'] as $keyword) {
            if (str_contains($q, ' ' . $keyword . ' ')) $score += 3;
        }
        foreach (explode('_', $name) as $part) {
            if (strlen($part) >= 3 && in_array($part, $tokens, true)) $score += 2;
        }
        $descriptionTokens = array_unique(explode(' ', ai_fold(preg_replace('/^- `[^`]*`/', '', $chunk['text']))));
        foreach ($descriptionTokens as $token) {
            if (strlen($token) >= 4 && !in_array($token, AI_STOPWORDS, true) && in_array($token, $tokens, true)) $score += 1;
        }
        $scores[$name] = $score;
    }
    arsort($scores);
    $selected = array_slice(array_keys(array_filter($scores, fn($s) => $s > 0)), 0, $limit);
    if (!$selected) return array_keys($chunks['tables']);          // không khớp gì: đưa toàn bộ schema
    if (!in_array('products', $selected, true) && isset($chunks['tables']['products'])) $selected[] = 'products';
    // Kho nối với số dư/biến động qua vị trí kho, nên luôn kèm warehouse_locations.
    if (in_array('warehouses', $selected, true) && !in_array('warehouse_locations', $selected, true)) $selected[] = 'warehouse_locations';
    return $selected;
}
function ai_build_messages(string $question, array $chunks, array $selected): array {
    $tableText = [];
    foreach ($selected as $name) $tableText[] = $chunks['tables'][$name]['text'];
    $examples = <<<'TXT'
Examples (question -> sql):
1. Tổng tồn theo sản phẩm ->
SELECT p.sku, p.name, SUM(b.quantity) AS total_qty FROM stock_balances b JOIN products p ON p.id = b.product_id GROUP BY p.id, p.sku, p.name ORDER BY total_qty DESC LIMIT 50
2. Tồn theo kho ->
SELECT w.name AS warehouse, SUM(b.quantity) AS total_qty FROM stock_balances b JOIN warehouse_locations l ON l.id = b.location_id JOIN warehouses w ON w.id = l.warehouse_id GROUP BY w.id, w.name ORDER BY total_qty DESC LIMIT 50
3. Sản phẩm dưới mức tồn tối thiểu ->
SELECT p.sku, p.name, p.min_stock, COALESCE(SUM(b.quantity), 0) AS total_qty FROM products p LEFT JOIN stock_balances b ON b.product_id = p.id GROUP BY p.id, p.sku, p.name, p.min_stock HAVING COALESCE(SUM(b.quantity), 0) < p.min_stock ORDER BY total_qty ASC LIMIT 50
4. Tổng nhập và xuất theo tháng ->
SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS month, SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END) AS qty_in, SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END) AS qty_out FROM stock_movements m GROUP BY DATE_FORMAT(m.created_at, '%Y-%m') ORDER BY month DESC LIMIT 24
5. Tồn hiện tại theo kho và đơn vị ->
SELECT w.name AS warehouse, u.name AS unit, SUM(b.quantity) AS total_qty FROM stock_balances b JOIN warehouse_locations l ON l.id = b.location_id JOIN warehouses w ON w.id = l.warehouse_id JOIN products p ON p.id = b.product_id JOIN units u ON u.id = p.unit_id GROUP BY w.id, w.name, u.id, u.name ORDER BY total_qty DESC LIMIT 50
TXT;
    $system = "You convert a Vietnamese or English question about an inventory database into ONE MySQL 8 SELECT statement.\n"
        . "Answer with JSON only, in the form {\"sql\": \"SELECT ...\"}.\n\n"
        . $chunks['rules'] . "\n\nRelevant tables:\n" . implode("\n", $tableText) . "\n\n" . $examples;
    return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $question]];
}

// ---------------------------------------------------------------- Ollama (LLM chạy local)
function ai_ollama_sql(array $messages): string {
    $url = rtrim(ai_env('OLLAMA_URL', 'http://ollama:11434'), '/') . '/api/chat';
    $body = json_encode([
        'model' => ai_env('OLLAMA_MODEL', 'qwen2.5:3b'), 'messages' => $messages, 'stream' => false,
        'format' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']], 'required' => ['sql']],
        'options' => ['temperature' => 0, 'num_ctx' => 4096],
    ], JSON_UNESCAPED_UNICODE);
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 240]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Không gọi được Ollama: ' . $error);
    if ($status !== 200) throw new RuntimeException('Ollama trả mã ' . $status . ' (đã tải model chưa?): ' . mb_substr((string)$response, 0, 200));
    $data = json_decode($response, true);
    $answer = json_decode((string)($data['message']['content'] ?? ''), true);
    $sql = is_array($answer) ? ($answer['sql'] ?? '') : '';
    if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Mô hình không trả về câu SQL hợp lệ.');
    return $sql;
}

// ---------------------------------------------------------------- Kiểm tra và chạy SQL (lớp bảo vệ ứng dụng + quyền MySQL chỉ đọc)
function ai_validate_select(string $sql): string {
    $sql = rtrim(trim($sql), "; \t\r\n");
    if ($sql === '') throw new InvalidArgumentException('Câu SQL rỗng.');
    if (strlen($sql) > 4000) throw new InvalidArgumentException('Câu SQL quá dài.');
    if (strpbrk($sql, ';\\') !== false) throw new InvalidArgumentException('Chỉ cho phép một câu lệnh, không dùng dấu chấm phẩy hoặc ký tự escape.');
    if (preg_match('/--|#|\/\*|\*\//', $sql)) throw new InvalidArgumentException('Không cho phép chú thích trong SQL.');
    $bare = preg_replace("/'[^']*'/", "''", $sql);                       // bỏ nội dung chuỗi trước khi xét từ khóa
    if (str_contains($bare, '"') || str_contains($bare, '@')) throw new InvalidArgumentException('Không cho phép dấu nháy kép hoặc biến phiên.');
    if (!preg_match('/^select\b/i', $bare)) throw new InvalidArgumentException('Chỉ cho phép câu lệnh SELECT.');
    if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|call|handler|load|outfile|dumpfile|into|lock|unlock|sleep|benchmark|get_lock|release_lock|set|show|use|explain|describe|prepare|execute|information_schema|performance_schema|mysql|sys)\b/i', $bare, $m)) {
        throw new InvalidArgumentException('Từ khóa không được phép: ' . strtolower($m[1]));
    }
    $check = preg_replace('/\bextract\s*\(\s*\w+\s+from\b/i', 'extract(', $bare);
    if (preg_match('/\bfrom\s+[\w`.]+(?:\s+(?:as\s+)?\w+)?\s*,/i', $check)) throw new InvalidArgumentException('Không dùng nối bảng bằng dấu phẩy; hãy dùng JOIN ... ON.');
    preg_match_all('/\b(?:from|join)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?(\s*\.)?/i', $check, $tables, PREG_SET_ORDER);
    if (!$tables) throw new InvalidArgumentException('Câu SELECT phải có FROM.');
    foreach ($tables as $table) {
        if (!empty($table[2]) || !in_array(strtolower($table[1]), AI_TABLES, true)) {
            throw new InvalidArgumentException('Bảng không nằm trong danh sách cho phép: ' . $table[1]);
        }
    }
    if (preg_match('/\blimit\s+(\d+)(?:\s*,\s*(\d+)|\s+offset\s+\d+)?\s*$/i', $sql, $limit)) {
        $rows = isset($limit[2]) ? (int)$limit[2] : (int)$limit[1];
        if ($rows > AI_MAX_ROWS) throw new InvalidArgumentException('LIMIT tối đa là ' . AI_MAX_ROWS . '.');
        return $sql;
    }
    return $sql . ' LIMIT ' . AI_MAX_ROWS;
}
// Chạy bằng tài khoản AI_DB_* (chỉ SELECT), transaction READ ONLY, giới hạn 5 giây, tối đa 200 dòng.
function ai_execute_read_query(string $sql): array {
    $pdo = ai_connect(ai_env('AI_DB_HOST', 'mysql'), ai_env('AI_DB_PORT', '3306'), ai_env('AI_DB_DATABASE', 'admin_product_db'),
        ai_env('AI_DB_USERNAME', 'ai_reader'), ai_env('AI_DB_PASSWORD'));
    $pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $stmt = $pdo->query($sql);
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) $columns[] = $stmt->getColumnMeta($i)['name'] ?? ('col' . $i);
        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $rows[] = $row;
            if (count($rows) > AI_MAX_ROWS) break;
        }
    } finally {
        $pdo->exec('ROLLBACK');
    }
    $truncated = count($rows) > AI_MAX_ROWS;
    if ($truncated) array_pop($rows);
    return ['columns' => $columns, 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated];
}

// ---------------------------------------------------------------- Xử lý một công việc (worker gọi)
function ai_process_job(string $jobId): void {
    $db = ai_app_db();
    $claim = $db->prepare("UPDATE ai_jobs SET status='running', started_at=NOW() WHERE id=? AND status='queued'");
    $claim->execute([$jobId]);
    if ($claim->rowCount() !== 1) return;                    // đã có worker khác xử lý hoặc nhận trùng thông điệp
    $job = ai_job_find($jobId);
    $partial = [];
    try {
        $payload = json_decode((string)$job['payload'], true);
        $question = trim((string)($payload['question'] ?? ''));
        if ($question === '') throw new InvalidArgumentException('Câu hỏi rỗng.');
        $started = microtime(true);
        $chunks = ai_schema_chunks();
        $selected = ai_retrieve($question, $chunks);
        $partial = ['retrieved' => $selected];
        $rawSql = ai_ollama_sql(ai_build_messages($question, $chunks, $selected));
        $partial['model_sql'] = $rawSql;
        $sql = ai_validate_select($rawSql);
        $result = ai_execute_read_query($sql);
        $result += ['sql' => $sql, 'retrieved' => $selected, 'model' => ai_env('OLLAMA_MODEL', 'qwen2.5:3b'),
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000)];
        $save = $db->prepare("UPDATE ai_jobs SET status='succeeded', result_json=?, error_message=NULL, finished_at=NOW() WHERE id=? AND status='running'");
        $save->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $jobId]);
    } catch (Throwable $e) {
        ai_job_fail($jobId, $e->getMessage(), $partial ?: null);
    }
}
// Chỉ dùng khi chạy đúng MỘT worker: việc đang "running" lúc khởi động là do worker trước bị dừng giữa chừng.
function ai_recover_stuck_jobs(): int {
    return ai_app_db()->exec("UPDATE ai_jobs SET status='failed', error_message='Worker bị dừng khi đang xử lý; hãy gửi lại câu hỏi.', finished_at=NOW() WHERE status='running'");
}
