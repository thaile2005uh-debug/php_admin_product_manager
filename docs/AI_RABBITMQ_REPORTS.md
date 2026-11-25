# AI hỏi dữ liệu kho, hàng đợi và báo cáo PDF

## 1. Mục tiêu và kiến trúc

Ứng dụng mở rộng bằng Ollama chạy local trong Docker, không gửi câu hỏi ra dịch vụ AI cloud. Pipeline:

```text
Browser → PHP (auth, permission, validation) → ai_jobs/MySQL → RabbitMQ
                                                             ↓
Browser ← polling trạng thái ← PHP ← Worker PHP → Ollama /api/chat
                                           ├─ SQL allowlist → MySQL user chỉ SELECT
                                           └─ mẫu Markdown → điền kết quả → Parsedown → Dompdf/PDF
```

RAG ở bản mẫu dùng truy hồi từ tài liệu schema cục bộ: `app/src/ai/schema_context.md` được tách thành các đoạn bảng/quy tắc, xếp hạng theo từ khóa câu hỏi và đưa các đoạn phù hợp vào system prompt Ollama. Đây là lexical retrieval gọn nhẹ, chưa dùng embedding/vector database. Có thể thay retriever bằng embedding sau này mà không đổi giao diện queue.

Tác vụ hỏi dữ liệu: `/ai` → `ai_jobs` trạng thái `queued` → hàng đợi durable `ai_jobs` → worker sinh `SELECT` → kiểm tra SQL → tài khoản MySQL chỉ đọc thực thi → lưu JSON → trang `/ai/jobs` polling endpoint `/ai/status` mỗi 2,5 giây. Báo cáo đi cùng queue, thay marker trong Markdown, tạo PDF và cung cấp link tải riêng cho người tạo. Client hiển thị trạng thái trực tiếp; người dùng có thể bật Web Notification từ nút trên trang. Polling cần trang còn mở; hiện chưa có WebSocket, email hay push notification nền.

## 2. Cài đặt Docker local

Yêu cầu Docker Desktop/Engine Compose, RAM/dung lượng phù hợp với model Ollama. Model 3B mặc định vẫn có thể chậm trên máy CPU. Ollama lưu model trong named volume, RabbitMQ lưu queue state trong volume riêng.

1. Tạo `.env` từ `.env.example` nếu chưa có. Nếu `.env` đã tồn tại, giữ nguyên cấu hình database hiện tại và bổ sung các biến `AI_DB_*`, `OLLAMA_*`, `RABBITMQ_*`; đặt mỗi mật khẩu dài, ngẫu nhiên và không commit file `.env`.

   ```dotenv
   AI_DB_HOST=mysql
   AI_DB_PORT=3306
   AI_DB_DATABASE=admin_product_db
   AI_DB_USERNAME=ai_reader
   AI_DB_PASSWORD=<chuỗi chỉ gồm chữ, số, _ hoặc ->
   OLLAMA_URL=http://ollama:11434
   OLLAMA_MODEL=qwen2.5:3b
   RABBITMQ_HOST=rabbitmq
   RABBITMQ_PORT=5672
   RABBITMQ_USER=report_worker
   RABBITMQ_PASSWORD=<mật khẩu riêng>
   RABBITMQ_QUEUE=ai_jobs
   REPORT_DIR=/var/www/reports/exports
   ```

2. Build và bật các container:

   ```bash
   docker compose up -d --build
   docker compose ps
   docker compose logs -f report-worker
   ```

Build cài `php-amqplib/php-amqplib`, `erusev/parsedown-extra`, `dompdf/dompdf`, PHP cURL và mbstring. `report-worker` là cùng image PHP, chạy `app/bin/report_worker.php` liên tục.

3. Tải model một lần (có thể mất thời gian và cần dung lượng đĩa):

   ```bash
   docker compose exec ollama ollama pull qwen2.5:3b
   docker compose exec ollama ollama list
   ```

   Muốn đổi model, đặt `OLLAMA_MODEL` trong `.env`, tải tên đó bằng `ollama pull`, rồi khởi động lại `php-app` và `report-worker`. Worker gọi Ollama qua `http://ollama:11434` trên mạng Docker; URL localhost không dùng được từ container.

4. Cấp tài khoản MySQL riêng chỉ đọc cho SQL AI sau khi schema đã có đủ bảng:

   ```bash
   sh database/grant_ai_reader.sh
   ```

   Script yêu cầu `AI_DB_USERNAME` và `AI_DB_PASSWORD` trong `.env`, kiểm tra ký tự an toàn, tạo/cập nhật user và chỉ grant `SELECT` cho các bảng nghiệp vụ được phép. Tài khoản ứng dụng ghi phiếu vẫn dùng `DB_USERNAME` riêng. Không cấp quyền trên `admins`, `roles`, `permissions`, `user_permissions`, hay `ai_jobs` cho AI reader.

5. Truy cập ứng dụng tại `http://localhost:8090`, đăng nhập người dùng có quyền `ai.ask` hoặc `reports.view`. RabbitMQ Management ở `http://localhost:15672` (user/password trong `.env`); Ollama API host ở `http://localhost:11434`. Không mở các cổng quản trị này ra Internet.

## 3. Database mới và database đã có

`database/init.sql` dùng cho database mới, đã bao gồm migration 001 (kho/giá/RBAC) và migration 002 (AI jobs/quyền AI). Docker MySQL chỉ tự chạy init khi data volume còn trống; không xóa volume database có dữ liệu.

Với database đã chạy migration 001, backup trước rồi áp dụng migration 002 đúng một lần:

```bash
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction "$MYSQL_DATABASE"' > backup-before-002.sql
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < database/migrations/002_ai_reports.sql
sh database/grant_ai_reader.sh
```

Nếu database cũ chưa áp dụng migration 001, đọc mục “Nâng cấp database cũ” trong README và chạy 001 trước 002. DDL MySQL tự commit; không giả định migration rollback được.

## 4. An toàn khi chuyển câu hỏi thành SQL

Mô hình không được quyền tự thực thi công cụ hay kết nối DB. Worker gọi `ai_ollama_json()`, sau đó `ai_validate_select()` và `ai_execute_read_query()` trong `app/src/ai_service.php`:

- Chỉ cho phép một câu `SELECT`, chỉ bảng allowlist trong schema context, không cho comment, DML/DDL, file I/O, lock, sleep, biến session hoặc system schema.
- Thêm `LIMIT 200` nếu thiếu, từ chối giới hạn lớn hơn 200, đặt MySQL `MAX_EXECUTION_TIME=5000` ms và transaction `READ ONLY`.
- Dùng kết nối `AI_DB_*` riêng; `database/grant_ai_reader.sh` chỉ cấp `SELECT` cho 11 bảng nghiệp vụ. Đây là lớp bảo vệ độc lập với kiểm tra chuỗi SQL.
- Schema context cấm truy vấn thông tin người dùng/quyền; không gửi password hash hay `.env` vào Ollama. Ollama vẫn nhận câu hỏi và schema nội bộ nên chỉ dùng trên host tin cậy.
- Mọi output hiển thị phải escape. Không tự động dùng SQL sinh ra cho thao tác ghi kho. Thay đổi allowlist hoặc quyền phải được rà soát như thay đổi bảo mật.

Chỉ cho phép câu hỏi từ tài khoản có `ai.ask`. Hạn chế quyền này với dữ liệu nhạy cảm. Kết quả JSON được giới hạn 200 dòng, nhưng vẫn cần cân nhắc che trường dữ liệu nếu bổ sung cột riêng tư vào bảng allowlist.

## 5. Mẫu Markdown và chuyển PDF

Mẫu nằm ở `reports/templates/*.md`, chỉ worker đọc theo slug hợp lệ (không nhận đường dẫn từ client). Thêm marker cho truy vấn:

```markdown
# Báo cáo tồn

Ngày lập: {{generated_at}}

<!-- ai-query: Tổng hợp tồn theo kho và đơn vị, không gộp đơn vị khác nhau. -->
```

Worker lấy mô tả marker làm câu hỏi SQL; nó thay marker bằng SQL và bảng Markdown, thay `{{generated_at}}`, lưu bản `.md`, parse Markdown ở safe mode, rồi Dompdf tạo PDF A4 ngang. Mẫu hiện có: `ton_kho_theo_kho.md` và `xuat_nhap_thang.md`. Tệp lưu tại `reports/exports/<job-uuid>.md` và `.pdf`, được mount riêng; endpoint `/reports/download?id=...` kiểm tra chủ sở hữu job và trạng thái thành công. Không đặt dữ liệu bí mật hoặc HTML không tin cậy trong mẫu.

Để thêm loại biểu đồ hoặc định dạng, tạo renderer riêng sau khi có kết quả dữ liệu; không cho mô hình phát HTML/PHP tùy ý. Nên đặt lịch dọn các file PDF cũ và job cũ theo chính sách lưu trữ của đơn vị.

## 6. Giao tiếp RabbitMQ và bổ sung worker

Publisher `ai_queue_publish()` gửi UUID tác vụ dạng persistent tới queue durable `ai_jobs`; bản ghi payload/status được lưu trong MySQL trước khi publish. Worker prefetch một message, đổi `queued → running → succeeded/failed`, ACK khi đã ghi trạng thái. Kết quả lỗi được lưu và ghi log, không ACK/requeue vô hạn. RabbitMQ không thay MySQL làm nguồn trạng thái.

Để thêm loại tác vụ: kiểm tra quyền tại route, kiểm tra CSRF nếu POST, chèn job bằng `ai_job_create()`, publish UUID; xử lý loại mới trong `app/bin/report_worker.php`; thêm status endpoint chỉ đọc job thuộc user; cập nhật client polling; đặt timeout, retry/backoff và idempotency phù hợp. Với nhiều worker, giữ thao tác ghi kết quả idempotent theo job ID. Nếu cần retry lỗi tạm thời, phân loại lỗi và dùng dead-letter/retry queue thay vì requeue vòng lặp.

UI hiện poll mỗi 2,5 giây và báo hoàn tất trong trang; endpoint status yêu cầu session + permission và lọc theo `user_id`. Nếu cần thông báo khi user rời trang, bổ sung WebSocket/SSE hoặc dịch vụ notification và kiểm tra quyền trước khi gửi; đừng giữ một PHP request dài mở để đợi Ollama.

## 7. Nạp dữ liệu mẫu lớn

Sau khi migration xong và trước khi thử query, import file một lần:

```bash
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < database/sample_data/ai_inventory_demo.sql
```

File tạo 500 sản phẩm, 500 lô, 1.000 khoảng giá, 500 dòng nhập + 100 dòng xuất, số dư và biến động đối soát được; có hai đơn vị bổ sung, hai kho và vị trí. Script từ chối chạy lại khi đã có SKU `DEMO-AI-0001`; backup trước nếu dùng database thật. Dữ liệu dùng cho thử nghiệm, không phải dữ liệu kinh doanh. Để xóa bộ demo, phải xóa theo đúng dependency và chứng từ là bất biến; tốt nhất tạo database phát triển mới thay vì xóa ledger thủ công.

File mặc định có `USE admin_product_db`; nếu `.env` đặt tên database khác, sửa dòng `USE` đầu file cho trùng `DB_DATABASE` trước khi import.

Ví dụ câu hỏi: “Tồn hiện tại theo kho và đơn vị”, “Top sản phẩm còn tồn thấp hơn mức tối thiểu”, “Tổng nhập/xuất đã ghi sổ theo tháng”. Hãy đối chiếu kết quả với `/inventory` và các phiếu đã ghi sổ; mô hình có thể tạo SQL sai hoặc diễn giải không chính xác.

## 8. Khắc phục sự cố

- Job ở `queued`: xem `docker compose logs report-worker rabbitmq`; worker phải kết nối đúng host/port/queue/user/password.
- Job `failed` báo Ollama: kiểm tra `docker compose ps`, `docker compose logs ollama`, model đã pull và worker gọi đúng tên model.
- Lỗi quyền MySQL: chạy lại grant script sau khi xác minh `AI_DB_*`; tài khoản reader phải có SELECT trên bảng cần thiết, không cần quyền ghi.
- Timeout: thử câu hỏi tổng hợp hẹp hơn, kiểm tra index/plan và tải máy; không tăng timeout vô hạn. Ollama generation có timeout 240 giây, còn MySQL query tối đa 5 giây.
- PDF không tạo: kiểm tra log worker và quyền ghi thư mục `reports/exports`; tải PDF qua route có session thay vì phục vụ thư mục trực tiếp.
- RAG sinh tên cột sai: cập nhật `schema_context.md`; đây là hợp đồng prompt và phải khớp migration hiện hành.
