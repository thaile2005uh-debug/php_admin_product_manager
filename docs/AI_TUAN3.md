# Tuần 3: Ollama, RabbitMQ và RAG hỏi dữ liệu kho

## 1. Kiến trúc

```text
Trình duyệt ──POST /ai/ask──► PHP (đăng nhập, quyền ai.ask, CSRF, kiểm tra độ dài)
                                 │ 1. INSERT ai_jobs (status=queued) vào MySQL
                                 │ 2. publish UUID công việc vào hàng đợi durable "ai_jobs" (RabbitMQ)
                                 ▼
Trình duyệt ◄─ polling /ai/status mỗi 2,5 giây ◄── MySQL ◄── ai-worker (PHP, container riêng)
                                                              │ nhận UUID, claim: queued → running
                                                              │ RAG: chọn các đoạn schema liên quan câu hỏi
                                                              │ Ollama /api/chat (qwen2.5:3b, local) → câu SELECT
                                                              │ kiểm tra SELECT + chạy bằng tài khoản MySQL chỉ đọc
                                                              └ ghi kết quả, running → succeeded / failed, rồi ACK
```

- **Ollama**: service `ollama`, model `qwen2.5:3b`, dữ liệu model nằm ở volume `ollama_data`. Không gọi dịch vụ AI trên Internet.
- **RabbitMQ**: service `rabbitmq`. Thông điệp chỉ chứa UUID; nội dung và trạng thái nằm ở bảng `ai_jobs`. Hàng đợi durable, thông điệp persistent, worker `prefetch=1`, ACK sau khi đã ghi trạng thái vào MySQL.
- **RAG**: `app/src/ai/schema_context.md` được tách thành quy tắc chung và từng đoạn bảng. Mỗi đoạn có danh sách từ khóa tiếng Việt không dấu. Câu hỏi được bỏ dấu, chấm điểm từng đoạn (cụm từ khớp nguyên từ +3, trùng tên bảng +2, trùng từ trong mô tả +1), lấy tối đa 4 đoạn cao nhất, luôn kèm `products`, và kèm `warehouse_locations` khi chọn `warehouses`. Nếu không đoạn nào khớp thì đưa toàn bộ schema. Phần được chọn cùng 4 ví dụ SQL mẫu tạo thành prompt gửi cho Ollama. Đây là truy hồi theo từ khóa, chưa dùng embedding/vector database.

## 2. Lớp an toàn khi chạy SQL do mô hình sinh

1. Chỉ một câu `SELECT`; từ chối dấu chấm phẩy, chú thích, biến `@`, dấu nháy kép, các từ khóa ghi/DDL/khóa/đọc file/`information_schema`; chỉ cho phép 11 bảng nghiệp vụ và không cho nối bảng bằng dấu phẩy hay tiền tố database.
2. Thêm `LIMIT 200` nếu thiếu; từ chối `LIMIT` lớn hơn 200.
3. Chạy bằng tài khoản `AI_DB_USERNAME` chỉ có `SELECT` trên 11 bảng đó, trong `START TRANSACTION READ ONLY`, `MAX_EXECUTION_TIME=5000` ms. Lớp này độc lập với lớp kiểm tra chuỗi ở bước 1.
4. Kết quả được hiển thị bằng `textContent` (không chèn HTML).

## 3. Giới hạn còn lại

- Mô hình 3B có thể viết SQL sai hoặc hiểu sai câu hỏi; luôn đối chiếu với `/inventory`. SQL sai về cú pháp hoặc bị từ chối được lưu thành trạng thái `failed` kèm lý do.
- Truy hồi theo từ khóa: câu hỏi dùng từ ngoài danh sách từ khóa có thể được đưa toàn bộ schema hoặc chọn thiếu bảng.
- Chỉ chạy được **một** worker: lúc khởi động worker đánh dấu mọi việc đang `running` là `failed`. Chưa có retry theo từng loại lỗi hay dead-letter queue; lỗi hạ tầng (MySQL tạm ngắt) được đưa lại hàng đợi sau 5 giây.
- Cập nhật trạng thái bằng polling khi còn mở trang; chưa có WebSocket, email hay thông báo nền.
- Chưa làm báo cáo Markdown/PDF (tài liệu của thầy có mô tả nhưng nằm ngoài ba yêu cầu đã chọn).
- Ollama và RabbitMQ Management chỉ dùng ở máy học tập; không mở cổng 11434 và 15672 ra Internet.
