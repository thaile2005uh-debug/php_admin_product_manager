<div class="panel">
    <p>Đặt câu hỏi bằng tiếng Việt về tồn kho, phiếu, giá hoặc biến động. Câu hỏi được đưa vào <strong>hàng đợi RabbitMQ</strong>; worker truy hồi phần schema liên quan (RAG), nhờ <strong>Ollama chạy local</strong> viết câu <code>SELECT</code>, kiểm tra rồi chạy bằng tài khoản MySQL chỉ đọc. Trang tự cập nhật trạng thái, không cần tải lại.</p>
    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/ai/ask">
        <?= csrf_field() ?>
        <textarea id="ai-question" name="question" rows="3" maxlength="500" required style="width:100%" placeholder="Ví dụ: Tồn hiện tại theo kho và đơn vị"><?= e($old) ?></textarea>
        <p>
            <?php foreach (['Tồn hiện tại theo kho và đơn vị','Top 10 sản phẩm có tổng tồn nhiều nhất','Sản phẩm nào có tồn thấp hơn mức tồn tối thiểu','Tổng nhập và xuất đã ghi sổ theo tháng','Giá bán hiện tại của từng sản phẩm'] as $example): ?>
                <button type="button" class="btn small secondary ai-example" data-q="<?= e($example) ?>"><?= e($example) ?></button>
            <?php endforeach; ?>
        </p>
        <button class="btn primary" type="submit">Gửi câu hỏi</button>
    </form>
</div>

<div class="panel" id="ai-result-panel" <?= $jobId === '' ? 'hidden' : '' ?>>
    <h2>Kết quả</h2>
    <div id="ai-result" aria-live="polite">Đang tải...</div>
</div>

<div class="panel table-scroll">
    <h2>Câu hỏi gần đây của bạn</h2>
    <table class="table"><thead><tr><th>Lúc</th><th>Câu hỏi</th><th>Trạng thái</th><th></th></tr></thead><tbody>
    <?php foreach ($jobs as $job): ?>
        <tr>
            <td><?= e(date('d/m/Y H:i:s', strtotime($job['created_at']))) ?></td>
            <td><?= e($job['question']) ?></td>
            <td><span class="badge <?= $job['status']==='succeeded' ? 'success' : 'muted' ?>"><?= e($job['status']) ?></span></td>
            <td><a href="/ai?job=<?= e($job['id']) ?>">Xem</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="4" class="empty">Chưa có câu hỏi nào.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<script>
(function () {
    document.querySelectorAll('.ai-example').forEach(function (button) {
        button.addEventListener('click', function () { document.getElementById('ai-question').value = button.getAttribute('data-q'); });
    });
    var jobId = <?= json_encode($jobId, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    if (!jobId) return;
    var box = document.getElementById('ai-result');
    var labels = {queued: 'Đang chờ trong hàng đợi RabbitMQ...', running: 'Worker đang xử lý (Ollama viết SQL, có thể mất vài chục giây)...', succeeded: 'Hoàn tất', failed: 'Thất bại'};

    function add(tag, text, parent) {
        var element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        (parent || box).appendChild(element);
        return element;
    }
    function render(data) {
        box.textContent = '';
        add('p', 'Câu hỏi: ' + data.question);
        var status = add('p');
        add('strong', 'Trạng thái: ', status);
        status.appendChild(document.createTextNode((labels[data.status] || data.status) + ' (' + data.status + ')'));
        var result = data.result || {};
        if (result.retrieved) add('p', 'Bảng được truy hồi (RAG): ' + result.retrieved.join(', '));
        if (data.error) add('div', data.error, box).className = 'alert error';
        var sql = result.sql || result.model_sql;
        if (sql) { add('p', result.sql ? 'Câu SQL đã chạy:' : 'SQL do mô hình sinh (bị từ chối):'); add('pre', sql); }
        if (data.status === 'succeeded') {
            add('p', result.row_count + ' dòng' + (result.truncated ? ' (đã cắt ở 200 dòng)' : '') + ' · ' + (result.elapsed_ms / 1000).toFixed(1) + ' giây · mô hình ' + result.model);
            var wrap = add('div'); wrap.className = 'table-scroll';
            var table = add('table', undefined, wrap); table.className = 'table';
            var head = add('tr', undefined, add('thead', undefined, table));
            result.columns.forEach(function (name) { add('th', name, head); });
            var body = add('tbody', undefined, table);
            result.rows.forEach(function (row) {
                var tr = add('tr', undefined, body);
                row.forEach(function (value) { add('td', value === null ? 'NULL' : String(value), tr); });
            });
        }
    }
    function poll() {
        fetch('/ai/status?id=' + encodeURIComponent(jobId), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error && !data.status) { box.textContent = data.error; return; }
                render(data);
                if (data.status === 'queued' || data.status === 'running') setTimeout(poll, 2500);
            })
            .catch(function () { setTimeout(poll, 5000); });
    }
    poll();
})();
</script>
