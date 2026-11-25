<?php
// Worker: nhận UUID công việc từ RabbitMQ, gọi RAG + Ollama, ghi kết quả vào MySQL.
// Chạy trong container ai-worker: php /var/www/html/bin/ai_worker.php
date_default_timezone_set('Asia/Ho_Chi_Minh');
require __DIR__ . '/../src/ai_service.php';
require_once AI_VENDOR;

function worker_log(string $message): void { fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL); }

$recovered = false;
while (true) {
    try {
        if (!$recovered) {
            $count = ai_recover_stuck_jobs();
            if ($count > 0) worker_log("Đã đánh dấu $count công việc dang dở là failed.");
            $recovered = true;
        }
        $connection = ai_queue_connection(130.0);
        $channel = $connection->channel();
        $channel->queue_declare(ai_queue_name(), false, true, false, false);
        $channel->basic_qos(null, 1, null);                    // mỗi lần chỉ nhận 1 việc
        $channel->basic_consume(ai_queue_name(), '', false, false, false, false, function ($message) {
            $jobId = trim($message->getBody());
            worker_log('Nhận công việc ' . $jobId);
            try {
                if (ai_is_uuid($jobId)) ai_process_job($jobId);
                $message->ack();                                // trạng thái đã được ghi vào MySQL
                worker_log('Xong ' . $jobId);
            } catch (Throwable $e) {                           // lỗi hạ tầng (vd MySQL tạm thời không kết nối được)
                worker_log('Lỗi hạ tầng, đưa lại vào hàng đợi: ' . $e->getMessage());
                sleep(5);
                $message->nack(true);
            }
        });
        worker_log('Worker sẵn sàng, đang chờ công việc trên hàng đợi ' . ai_queue_name());
        while ($channel->is_consuming()) {
            try { $channel->wait(null, false, 60); }
            catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) { /* hàng đợi đang rảnh */ }
        }
    } catch (Throwable $e) {
        worker_log('Mất kết nối, thử lại sau 5 giây: ' . $e->getMessage());
        sleep(5);
    }
}
