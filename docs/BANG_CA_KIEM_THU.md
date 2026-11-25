# BẢNG CA KIỂM THỬ — Hệ thống kho PHP

Tài liệu gồm: Bài A, B, C, D, bài mở rộng (đóng/mở lịch giá nguyên tử), thử cạnh tranh và giới hạn còn lại.
Cột **Kết quả thực tế** và **Ảnh** do người làm bài điền sau khi chạy. Ca nào chưa có kết quả thực tế thì **không coi là đã đạt**.

---

## Cách dùng tài liệu này

**Link.** Mọi link trong file là `http://localhost:8090/...` (hệ thống chạy bằng Docker trên máy bạn nên không có `https`). Dán vào thanh địa chỉ trình duyệt để chụp ảnh. phpMyAdmin là `http://localhost:8091` (server `mysql`, tài khoản lấy trong `.env`).

**Cách chụp ảnh.** Chụp cả **thanh địa chỉ** ở trên và dòng kết quả/phân trang ở dưới (Ctrl + lăn chuột để thu nhỏ trình duyệt còn khoảng 67%).

**Đặt tên ảnh** đúng mã ca, lưu vào thư mục `images/` cạnh file này, ví dụ `images/A1.png`, `images/B4_sql.png`. Ca có nhiều ảnh thì thêm đuôi `_a`, `_b`.

**Tài khoản thử** (dữ liệu minh họa):

| Tài khoản | Mật khẩu | Nhóm | Ghi chú |
|---|---|---|---|
| `admin` | `admin123` | ADMIN | Có mọi quyền |
| `clerk` | `clerk123` | STOCK_CLERK (Người lập phiếu) | Lập/hủy nháp, in; **không** ghi sổ |
| `manager` | `manager123` | STOCK_MANAGER (Thủ kho) | Ghi sổ, in, quản lý danh mục kho; **không** lập phiếu |
| `viewer` | `viewer123` | VIEWER (Người xem) | Chỉ xem |
| `tester` | `tester123` | (không nhóm) | Dành riêng cho Bài B |

**Việc cần làm trước khi nộp** (đánh dấu khi xong):

- [ ] Sửa lỗi hiển thị tiếng Việt của dữ liệu mẫu ("Phụ kiện", "Điện thoại", 3 sản phẩm mẫu) rồi chụp lại ảnh Bài A.
- [ ] Điền cột "Kết quả thực tế" và đặt ảnh cho mọi ca ở các bài B, C, D, E, CT.
- [ ] Chạy `bai_b_fixture.sql`, `bai_c_fixture.sql`, `bai_d_fixture.sql` (một lần) để có dữ liệu thử.
- [ ] Xuất 1 bản PDF phiếu mẫu (mục D.3) và ảnh đối soát (mục D.2).
- [ ] Dọn source: xóa `backup-before-001.sql`, các file `.DS_Store`; đổi `.env` thành `.env.example`.

---

## 1. BÀI A — Phân trang thật và Sổ biến động

**Dữ liệu thử:** 45 sản phẩm, 3 danh mục (Laptop, Điện thoại, Phụ kiện), 20 dòng/trang → 3 trang. Có sản phẩm trùng tên (ví dụ "Laptop Dell Inspiron 15", SKU `LAP-DELL-001` và `LAP-DELL-002`).

### 1.1. Màn hình Sản phẩm

| # | Ca | Link / bước làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| A1 | Trang 1 | [/products](http://localhost:8090/products) | 20 dòng, "45 bản ghi · Trang 1/3" | Đạt | `A1.png` |
| A2 | Trang giữa | [/products?page=2](http://localhost:8090/products?page=2) | Trang 2/3 | Đạt | `A2.png` |
| A3 | Trang cuối | [/products?page=3](http://localhost:8090/products?page=3) | 5 dòng, Trang 3/3 | Đạt | `A3.png` |
| A4 | Trang 0 | [/products?page=0](http://localhost:8090/products?page=0) | Về trang 1 | Đạt (hiện Trang 1/3) | `A4.png` |
| A5 | Trang rất lớn | [/products?page=99999](http://localhost:8090/products?page=99999) | Kẹp về trang cuối | Đạt (hiện Trang 3/3) | `A5.png` |
| A6 | Từ khóa không có kết quả | [/products?keyword=zzzzzz](http://localhost:8090/products?keyword=zzzzzz) | 0 bản ghi, thông báo rỗng, Trang 1/1 | Đạt | `A6.png` |
| A7 | Từ khóa + danh mục | [/products?keyword=LOGITECH&category_id=3&per_page=10](http://localhost:8090/products?keyword=LOGITECH&category_id=3&per_page=10) | Chỉ sản phẩm Logitech thuộc danh mục Phụ kiện | Đạt (4 bản ghi) | `A7.png` |
| A8 | Kích thước trang quá lớn | [/products?per_page=1000](http://localhost:8090/products?per_page=1000) | Server ép về 20 dòng/trang | Đạt (vẫn Trang 1/3) | `A8.png` |

### 1.2. Màn hình Sổ biến động

| # | Ca | Link / bước làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| A9 | Lọc kết hợp sản phẩm + kho + khoảng ngày | [/movements?product_id=1&warehouse_id=1&date_from=2026-11-01&date_to=2026-11-23](http://localhost:8090/movements?product_id=1&warehouse_id=1&date_from=2026-11-01&date_to=2026-11-23) | Chỉ dòng khớp cả ba điều kiện; tổng nhập/xuất đúng; bộ lọc giữ trong form | Đạt: 1 bản ghi `OPENING-MIGRATION-001`, +10 Cái, tổng Nhập 10 / Xuất 0 | `A9.png` |
| A10 | Mặc định, không lọc | [/movements](http://localhost:8090/movements) | Toàn bộ biến động, có phân trang | ☐ | `A10.png` |
| A11 | Nhiều trang | [/movements?per_page=10&page=2](http://localhost:8090/movements?per_page=10&page=2) (cần có đủ dữ liệu, xem mục D) | Trang 2, tổng nhập/xuất là của **toàn bộ** kết quả lọc | ☐ | `A11.png` |
| A12 | Chỉ lọc theo sản phẩm | [/movements?product_id=1](http://localhost:8090/movements?product_id=1) | Chỉ biến động của sản phẩm đó | ☐ | `A12.png` |
| A13 | Chỉ lọc theo kho | [/movements?warehouse_id=1](http://localhost:8090/movements?warehouse_id=1) | Chỉ biến động thuộc kho đó | ☐ | `A13.png` |
| A14 | Ngày sai định dạng | [/movements?date_from=2026-13-45](http://localhost:8090/movements?date_from=2026-13-45) | Thông báo "Từ ngày không hợp lệ", không lỗi hệ thống | ☐ | `A14.png` |
| A15 | "Từ ngày" lớn hơn "Đến ngày" | [/movements?date_from=2026-11-20&date_to=2026-11-10](http://localhost:8090/movements?date_from=2026-11-20&date_to=2026-11-10) | Thông báo lỗi, bỏ điều kiện ngày | ☐ | `A15.png` |

> `product_id=1`, `warehouse_id=1` chỉ là ví dụ. Dùng hai ô chọn trong form rồi bấm "Lọc sổ biến động", URL sẽ tự có đúng id.

### 1.3. Giải thích theo yêu cầu của đề

**Tổng bản ghi được đếm bằng truy vấn nào? Có cùng điều kiện với danh sách không?**
Bằng `SELECT COUNT(*)` trên cùng bảng và **cùng điều kiện lọc** (`$condition`) với truy vấn lấy dữ liệu. Sổ biến động dùng chung cả phần `JOIN` cho hai truy vấn. Vì cùng điều kiện nên dòng "N bản ghi · Trang x/y" luôn khớp dữ liệu thật (ca A7: 4 bản ghi).

**Vì sao đổi bộ lọc cần trở lại trang đầu?**
Đổi bộ lọc làm thay đổi tổng số bản ghi và số trang. Giữ trang cũ có thể rơi vào trang không còn tồn tại hoặc thấy rỗng dù vẫn có kết quả. Form lọc dùng `GET` và không gửi tham số `page` nên mỗi lần bấm lọc, server tính lại từ trang 1.

**Vì sao cần thứ tự ổn định thay vì chỉ `ORDER BY name`?**
Các dòng cùng tên không có thứ tự xác định giữa hai lần truy vấn. Khi chuyển trang bằng `LIMIT/OFFSET`, một dòng có thể lặp ở hai trang hoặc biến mất. Cần thêm khóa duy nhất: code dùng `ORDER BY p.id DESC` (sản phẩm) và `ORDER BY m.id DESC` (sổ biến động). Minh họa: hai sản phẩm cùng tên "Laptop Dell Inspiron 15" luôn giữ vị trí cố định.

**Kích thước trang được giới hạn ở server như thế nào?**
Router chỉ chấp nhận `per_page` thuộc {10, 20, 50}, giá trị khác về 20. `pagination_meta()` còn chặn trần 100 dòng/trang và kẹp số trang vào khoảng hợp lệ (ca A8).

**Sổ biến động không thay đổi tồn:** route chỉ `GET`, chỉ chạy các truy vấn `SELECT`, không `array_slice()`, lọc ngày theo `[date_from 00:00, date_to + 1 ngày)` trên ngày phiếu.

---

## 2. BÀI B — Phân quyền nhóm và tài khoản

**Chuẩn bị:** chạy `bai_b_fixture.sql` (tạo `clerk`, `manager`, `viewer`, `tester`; `tester` không nhóm, không ngoại lệ). Đăng nhập `admin` để chỉnh quyền tại [/access](http://localhost:8090/access).

**Cách kiểm chứng mỗi ca:** sau khi chỉnh ở `/access`, mở `bai_b_quyen_hieu_luc.sql`, đặt `@username = 'tester'`, `@perm = 'receipts.create'`, chạy trong phpMyAdmin và chụp cột `ket_qua`. Có thể chụp thêm màn hình `/access` của `tester`.

### 2.1. Tám tình huống bắt buộc (quyền thử: `receipts.create`)

| # | Tình huống | Cách làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| B1 | Không nhóm, không ngoại lệ | `tester` ở trạng thái ban đầu | TỪ CHỐI (mặc định) | ☐ | `B1.png`, `B1_sql.png` |
| B2 | Nhóm cấp quyền, tài khoản kế thừa | Gán `tester` vào STOCK_CLERK, các quyền riêng "Kế thừa nhóm" | CHO PHÉP (từ nhóm) | ☐ | `B2.png`, `B2_sql.png` |
| B3 | Hai nhóm, chỉ một nhóm cấp quyền | Gán `tester` vào VIEWER **và** STOCK_CLERK | CHO PHÉP (VIEWER không có `receipts.create`, STOCK_CLERK có) | ☐ | `B3.png`, `B3_sql.png` |
| B4 | Nhóm cấp quyền, tài khoản `deny` | Giữ STOCK_CLERK, đặt `receipts.create` = Từ chối | TỪ CHỐI (deny riêng) | ☐ | `B4.png`, `B4_sql.png` |
| B5 | Không nhóm cấp quyền, tài khoản `allow` | Bỏ hết nhóm, đặt `receipts.create` = Cho phép | CHO PHÉP (allow riêng) | ☐ | `B5.png`, `B5_sql.png` |
| B6 | Xóa ngoại lệ `allow`, không nhóm cấp quyền | Đổi `receipts.create` về "Kế thừa nhóm", vẫn không nhóm | TỪ CHỐI (mặc định) | ☐ | `B6.png`, `B6_sql.png` |
| B7 | Bỏ nút bằng CSS nhưng tự gửi POST trái quyền | Tạo 1 phiếu nháp. Đăng nhập `clerk` (không có `receipts.post`), mở DevTools, tự gửi `POST /documents/post` với CSRF hợp lệ cho phiếu nháp đó | HTTP 403; phiếu vẫn `draft`; `SELECT COUNT(*) FROM stock_movements` không đổi | ☐ | `B7_a.png` (403), `B7_b.png` (trước/sau) |
| B8 | Thu hồi quyền khi còn đăng nhập | Hai trình duyệt: `tester` đã đăng nhập và đang mở một trang cần quyền; `admin` thu hồi quyền; `tester` tải lại / bấm sang trang khác | Request kế tiếp bị 403 theo dữ liệu mới, không cần đăng nhập lại | ☐ | `B8_a.png`, `B8_b.png` |

### 2.2. Kiểm tra cả GET lẫn POST (đường dẫn xem, ghi sổ, in)

Có thể chạy `python3 tests/http_smoke.py` và chụp các dòng `PASS` (script đã phủ nhiều ca `viewer` và `clerk`), các ca còn lại thử tay.

| # | Phương thức + đường dẫn | Tài khoản | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| G1 | GET `/access` | viewer | 403 | ☐ | `G1.png` |
| G2 | GET `/prices` | viewer | 403 | ☐ | `G2.png` |
| G3 | GET `/warehouse-catalog` | viewer | 403 | ☐ | `G3.png` |
| G4 | GET `/documents/create?type=receipt` | viewer | 403 | ☐ | `G4.png` |
| G5 | GET `/documents` và `/movements` | tester (không nhóm) | 403 | ☐ | `G5.png` |
| G6 | GET `/documents/print?id=<phiếu đã ghi sổ>` | viewer (không có `documents.print`) | 403 | ☐ | `G6.png` |
| G7 | GET `/documents/print?id=<phiếu nháp>` | manager | Từ chối: "Chỉ được in phiếu đã ghi sổ" | ☐ | `G7.png` |
| G8 | GET `/documents/print` với `documents.print` = allow nhưng `documents.view` = deny | tester | 403 (README: in cần **cả** `documents.view` và `documents.print`) | ☐ (chỉ đạt sau khi route `/documents/print` gọi thêm `require_permission('documents.view')`) | `G8.png` |
| G9 | POST `/documents/post` | clerk | 403 | ☐ | `G9.png` |
| G10 | POST `/documents/cancel` | viewer | 403 | ☐ | `G10.png` |
| G11 | POST `/documents/create` | viewer | 403 | ☐ | `G11.png` |
| G12 | POST `/prices` | clerk (có xem giá, không có `prices.manage`) | 403 | ☐ | `G12.png` |
| G13 | POST `/warehouse-catalog?tab=warehouses` | clerk | 403 | ☐ | `G13.png` |
| G14 | POST `/products/delete` | viewer | 403 | ☐ | `G14.png` |
| G15 | POST `/access` | clerk | 403 | ☐ | `G15.png` |
| G16 | GET `/products/delete` | admin | 405 (chức năng yêu cầu POST) | ☐ | `G16.png` |
| G17 | POST `/products/delete` không có CSRF | admin | 419 | ☐ | `G17.png` |

### 2.3. Giải thích thứ tự `deny riêng > allow riêng > nhóm > từ chối mặc định`

- Ngoại lệ riêng của tài khoản là lớp cụ thể nhất nên thắng quyền từ nhóm. Mỗi cặp (tài khoản, quyền) chỉ có một dòng `allow` hoặc `deny` nên không bao giờ xung đột bên trong lớp ngoại lệ.
- Nếu không có ngoại lệ thì hợp quyền của **mọi** nhóm của tài khoản: chỉ cần một nhóm cấp là được (ca B3).
- Nếu không có gì cấp thì từ chối mặc định (ca B1, B6).
- Server tính lại từ database ở **mỗi request** (`can()`), không lưu quyền trong session, nên thu hồi có hiệu lực ngay (ca B8). Việc ẩn nút chỉ là giao diện; route ghi đều gọi `require_permission()` ở server (ca B7, G9–G15).

---

## 3. BÀI C — Giá theo thời gian

**Chuẩn bị:** chạy `bai_c_fixture.sql` (tạo các sản phẩm SKU `GIA-...`). Xem giá tại [/prices](http://localhost:8090/prices) (chọn sản phẩm bằng ô lọc). Lập phiếu nháp tại [/documents/create?type=issue](http://localhost:8090/documents/create?type=issue) (giá xuất) hoặc [type=receipt](http://localhost:8090/documents/create?type=receipt) (giá nhập). Lập phiếu nháp **không cần có tồn**; xem giá chụp trên trang chi tiết phiếu. Nhớ nhập "Đối tác / người giao nhận".

Khoảng giá `[valid_from, valid_to)`: gồm đầu, **không** gồm cuối. Dữ liệu fixture dùng trong ca:

| SKU | Loại | Giá | Khoảng hiệu lực |
|---|---|---|---|
| `GIA-TANG` | xuất | 100.000 / 110.000 / 125.000 / 140.000 | [10/11, 14/11) / [14/11, 18/11) / [18/11, 22/11) / từ 22/11, mở |
| `GIA-TANG` | nhập | 70.000 / 85.000 | [10/11, 16/11) / [16/11, 22/11) |
| `GIA-KHOANGTRONG` | xuất | 50.000 / 60.000 | [10/11, 13/11) / [16/11, 20/11) — **trống 13/11–16/11** |
| `GIA-KHONG` | xuất | 0 / 30.000 | [10/11, 15/11) / [15/11, 20/11) |
| `GIA-MO` | xuất / nhập | 75.000 / 60.000 | từ 10/11, **mở vô hạn** |
| `GIA-TRONG` | — | không có giá nào | — |

| # | Tình huống | Cách làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| C1 | Tại đúng `valid_from` | Phiếu **xuất** `GIA-TANG`, ngày chứng từ `14/11/2026 00:00` | Đơn giá 110.000 (mức bắt đầu đúng lúc đó) | ☐ | `C1.png` |
| C2 | Tại đúng `valid_to` | Phiếu xuất `GIA-TANG`, ngày `13/11/2026 23:59` rồi so với ca C1 | 23:59 ngày 13/11: 100.000. Mức [10/11, 14/11) không được chọn tại đúng 14/11 00:00 | ☐ | `C2.png` |
| C3 | Hai khoảng tiếp giáp | Xem giá `GIA-TANG` ở `/prices` (hoặc thêm khoảng mới bắt đầu đúng lúc khoảng trước kết thúc) | Được chấp nhận | ☐ | `C3.png` |
| C4 | Hai khoảng giao nhau cùng sản phẩm/loại | `/prices`: `GIA-TANG`, giá xuất, từ `12/11/2026 00:00` đến `16/11/2026 00:00` | Bị từ chối: "Khoảng giá bị chồng lấn..." | ☐ | `C4.png` |
| C5 | Hai loại giá khác nhau cùng thời gian | Xem `GIA-TANG` có đồng thời giá xuất và giá nhập trong 10/11–16/11 | Được chấp nhận | ☐ | `C5.png` |
| C6 | `valid_to <= valid_from` | `/prices`: một sản phẩm bất kỳ, từ `01/12/2026 10:00`, đến `01/12/2026 10:00` (rồi thử cả đến sớm hơn) | Bị từ chối: "Thời điểm kết thúc phải sau thời điểm bắt đầu." | ☐ | `C6.png` |
| C7 | Không có giá tại ngày phiếu | Phiếu xuất `GIA-KHOANGTRONG`, ngày `14/11/2026 10:00`; và phiếu xuất `GIA-TRONG` | Không lập được phiếu: "Sản phẩm phải có đúng một giá hiệu lực...", không dùng giá 0 ngầm định | ☐ | `C7.png` |
| C8 | Có dòng giá bằng 0 được cấu hình rõ | Phiếu xuất `GIA-KHONG`, ngày `12/11/2026 10:00` | Lập được phiếu với đơn giá 0 | ☐ | `C8.png` |
| C9 | Sửa HTML/request gửi đơn giá khác | Phiếu xuất `GIA-TANG`; dùng DevTools thêm tham số `lines[0][unit_price]=1` (hoặc `lines[0][price]=1`) vào request | Server bỏ qua, đơn giá trên phiếu vẫn là giá lịch sử hợp lệ | ☐ | `C9.png` |
| C10 | Tạo phiếu rồi thêm giá cho thời gian khác | Lập phiếu nháp **nhập** `GIA-TANG` ngày `12/11/2026` (chụp 70.000). Sau đó `/prices` thêm giá nhập `GIA-TANG` từ `01/12/2026` đến `10/12/2026`. Mở lại phiếu nháp; ghi sổ rồi in | Phiếu cũ và bản in vẫn 70.000 (giá chụp) | ☐ | `C10_a.png`, `C10_b.png` |
| C11 | Hai request cùng thêm giá chồng khoảng | Xem mục 6 (CT2) | Chỉ một request thành công | ☐ | xem CT2 |

**Giải thích ngắn:** giá không lấy từ bảng sản phẩm mà từ `product_prices` theo loại phiếu và ngày chứng từ; giá được chụp vào dòng phiếu lúc lập nên thêm/đổi giá sau không ảnh hưởng. `price_create()` khóa dòng sản phẩm (`FOR UPDATE`) trước khi kiểm tra chồng lấn nên hai request không cùng vượt qua.

---

## 4. BÀI D — Nhập, xuất, in và đối soát

**Chuẩn bị:** chạy `bai_d_fixture.sql`. Dữ liệu: kho `MAIN` (vị trí `DEFAULT`, `A2`) và `KHO2` (`B1`, `B2`); sản phẩm `DS-A` (lô `LOT-A1` còn hạn; `LOT-A2` hết hạn 15/11/2026) và `DS-B` (lô `LOT-B1`); giá nhập 10.000, giá xuất 15.000 (từ 01/01/2026); **không có tồn đầu**.

**Phân vai khi thử:** `clerk` lập/hủy phiếu nháp, `manager` ghi sổ (hoặc dùng `admin` làm cả hai). Xem tồn tại [/inventory](http://localhost:8090/inventory), phiếu tại [/documents](http://localhost:8090/documents), sổ biến động tại [/movements](http://localhost:8090/movements).

Bộ khóa chính dùng chung: **K1 = `DS-A` · `MAIN/A2` · `LOT-A1`**.

### 4.1. Mười một tiêu chí

| # | Tiêu chí | Cách làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| D0 | Chuẩn bị tồn cho ca D4 | Phiếu nhập `DS-B`, `KHO2/B1`, `LOT-B1`, 5 → ghi sổ | Tồn `DS-B` = 5 | ☐ | `D0.png` |
| D1 | Nháp nhập không đổi tồn; ghi sổ mới cộng 10 | Phiếu nhập K1 số lượng 10. Xem `/inventory` ngay sau khi lưu nháp, rồi ghi sổ và xem lại | Sau nháp: tồn K1 = 0. Sau ghi sổ: 10 | ☐ | `D1_a.png`, `D1_b.png` |
| D2 | Xuất 6 còn 4; xuất 5 từ số dư 4 thất bại | Phiếu xuất K1 = 6 → ghi sổ. Phiếu xuất K1 = 5 → ghi sổ | Tồn còn 4. Phiếu xuất 5 thất bại: "Tồn kho không đủ...", phiếu vẫn `draft`, tồn vẫn 4 | ☐ | `D2.png` |
| D3 | Hai dòng cùng bộ khóa, mỗi dòng 3, tồn 4 | Một phiếu xuất có hai dòng cùng K1, mỗi dòng 3 → ghi sổ | Thất bại (tổng 6 > 4), tồn vẫn 4, không có biến động mới | ☐ | `D3.png` |
| D4 | Nhiều dòng, một dòng thiếu tồn: không ghi một phần | Một phiếu xuất: dòng 1 `DS-B`/`KHO2-B1`/`LOT-B1` số lượng 2 (đủ); dòng 2 K1 số lượng 5 (thiếu) → ghi sổ | Thất bại toàn phiếu; tồn `DS-B` vẫn 5, K1 vẫn 4; không có dòng biến động của phiếu này | ☐ | `D4.png` |
| D5 | Ghi sổ lặp cùng phiếu | Với một phiếu đã ghi sổ, gửi lại `POST /documents/post` (DevTools → Network → Copy as fetch, chạy lại trong Console) | Bị từ chối: "Chỉ phiếu nháp mới được ghi sổ."; số biến động không tăng | ☐ | `D5.png` |
| D6 | Hủy nháp không đổi tồn; phiếu đã hủy không ghi sổ lại | Hủy một phiếu nháp rồi bấm/gửi ghi sổ phiếu đó | Tồn không đổi; ghi sổ phiếu đã hủy bị từ chối | ☐ | `D6.png` |
| D7 | Hủy/sửa phiếu đã ghi sổ bị từ chối | Gửi `POST /documents/cancel` cho phiếu đã ghi sổ | Bị từ chối: "Chỉ được hủy phiếu nháp. Phiếu ghi sổ không được sửa hay hủy." | ☐ | `D7.png` |
| D8 | Lô của sản phẩm A không dùng cho sản phẩm B | Lập phiếu bằng DevTools: đổi giá trị `lot_id` của dòng `DS-B` thành id của `LOT-A1` | Bị từ chối: "Lô hàng phải thuộc đúng sản phẩm." | ☐ | `D8.png` |
| D9 | Lô hết hạn; lùi ngày phiếu | Nhập một ít vào `LOT-A2` (nhập không bị chặn). Lập phiếu xuất `LOT-A2` ngày hiện tại; rồi lập lại với ngày chứng từ `10/11/2026` (trước hạn 15/11) | Cả hai bị từ chối: "Không được xuất lô đã hết hạn theo ngày chứng từ hoặc ngày hiện tại." (lùi ngày không qua được vì còn so với ngày hiện tại) | ☐ | `D9.png` |
| D10 | Chỉ in phiếu đã ghi sổ; thiếu quyền in hoặc còn nháp không tạo bản in | GET `/documents/print?id=<phiếu nháp>`; GET `/documents/print?id=<phiếu ghi sổ>` bằng `viewer` | Phiếu nháp: "Chỉ được in phiếu đã ghi sổ." `viewer`: 403 | ☐ | `D10.png` |
| D11 | Tổng tiền trên bản in = Σ số lượng × đơn giá chụp | Mở bản in phiếu nhập K1 = 10 và phiếu xuất K1 = 6 (nếu chưa đổi giá `DS-A`) | Nhập: 10 × 10.000 = 100.000 đ. Xuất: 6 × 15.000 = 90.000 đ | ☐ | `D11.png` |

### 4.2. Đối soát từng bộ khóa

**Công thức:** `stock_balances.quantity = SUM(stock_movements.quantity) = tổng nhập đã ghi sổ − tổng xuất đã ghi sổ`. Số dư đầu kỳ đã chuyển đổi từ dữ liệu cũ (phiếu `OPENING-MIGRATION-001`) nằm sẵn trong `stock_movements` nên được tính vào phép đối soát. **Không sửa trực tiếp bảng số dư.**

**Cách làm:**
1. Mở phpMyAdmin `http://localhost:8091`, chọn database `admin_product_db`.
2. Dán nội dung `bai_d_doi_soat.sql` và chạy.
3. Chụp kết quả truy vấn 1 (mọi dòng cột `lech` phải bằng 0) và truy vấn 2 (phải ra **0 dòng**).

| # | Ca | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|
| DS1 | Truy vấn 1: số dư so với tổng biến động từng bộ khóa | Mọi dòng `lech = 0`, bao gồm dòng số dư đầu kỳ (ví dụ `LAP-DELL-001`, +10) | ☐ | `DS1.png` |
| DS2 | Truy vấn 2: biến động nhưng thiếu dòng số dư | 0 dòng | ☐ | `DS2.png` |
| DS3 | Tổng nhập − tổng xuất của K1 | = tồn K1 (sau D1, D2: 10 − 6 = 4) | ☐ | `DS3.png` |

Truy vấn cho DS3 (chỉ đọc):
```sql
SELECT SUM(CASE WHEN m.quantity>0 THEN m.quantity ELSE 0 END) AS tong_nhap,
       SUM(CASE WHEN m.quantity<0 THEN -m.quantity ELSE 0 END) AS tong_xuat,
       SUM(m.quantity) AS chenh_lech
FROM stock_movements m
JOIN products p ON p.id=m.product_id
JOIN product_lots l ON l.id=m.lot_id
JOIN warehouse_locations loc ON loc.id=m.location_id
WHERE p.sku='DS-A' AND l.code='LOT-A1' AND loc.code='A2';
```

### 4.3. Bản PDF phiếu mẫu

1. Chọn một phiếu **đã ghi sổ** (ví dụ phiếu xuất K1 = 6) trong [/documents](http://localhost:8090/documents), bấm "Bản in" (cần quyền `documents.print`).
2. Bấm "In / lưu PDF" (hoặc Ctrl + P), chọn máy in **Lưu dưới dạng PDF**, khổ A4 ngang (trang in tự đặt khổ A4 landscape).
3. Lưu thành `phieu_mau.pdf`.

| # | Ca | Kết quả mong đợi | Kết quả thực tế | Ảnh / file |
|---|---|---|---|---|
| PDF1 | Bản in phiếu đã ghi sổ | Có số phiếu, ngày chứng từ, đối tác, thời điểm ghi sổ, bảng dòng hàng, tổng tiền, khu ký tên; không hiện thanh bên | ☐ | `phieu_mau.pdf` |

---

## 5. BÀI MỞ RỘNG — Đóng/mở lịch giá nguyên tử

**Chức năng:** form "Lập giá kế tiếp" tại [/prices](http://localhost:8090/prices) → `price_set_next()`: khóa sản phẩm, xác định khoảng đang mở, **đóng khoảng tại thời điểm T**, thêm khoảng mới bắt đầu đúng T, tất cả trong **một transaction**.

**Quy tắc nghiệp vụ (cần nộp trước khi code, theo đề mục 4):**
1. **Quyền:** cần `prices.manage` (POST), có CSRF.
2. **Thời điểm:** T phải ở **tương lai** (sau thời điểm hiện tại) và **sau** thời điểm bắt đầu của khoảng đang mở.
3. **Phiếu nháp đã có giá chụp:** không bị ảnh hưởng vì chứng từ không được lập ở tương lai (`Ngày chứng từ không được ở tương lai`), nên không phiếu nào (kể cả nháp) rơi vào khoảng `[T, ...)`; đơn giá đã chụp trên dòng phiếu không đổi.
4. **Điều kiện:** phải có đúng **một** khoảng đang mở của loại giá đó; không có hoặc nhiều hơn một thì từ chối.
5. **Thay đổi schema:** không thêm bảng/cột; dùng bảng `product_prices` có sẵn (ràng buộc `CHECK(valid_to > valid_from)`).

| # | Ca nghiệm thu | Cách làm | Kết quả mong đợi | Kết quả thực tế | Ảnh |
|---|---|---|---|---|---|
| E1 | Lập giá kế tiếp thành công | `/prices` → "Lập giá kế tiếp": `GIA-MO`, giá xuất 80.000, T = `01/12/2026 10:00` | Khoảng 75.000 đóng tại T; khoảng 80.000 bắt đầu đúng T, mở vô hạn | ☐ | `E1.png` |
| E2 | T ở quá khứ | Cùng form, T = `20/11/2026 10:00` | Từ chối: "Thời điểm áp dụng giá mới phải ở tương lai." | ☐ | `E2.png` |
| E3 | T không sau thời điểm bắt đầu của khoảng mở | Sau E1, T = `30/11/2026 10:00` (hoặc đúng `01/12/2026 10:00`) | Từ chối: "Thời điểm áp dụng phải sau thời điểm bắt đầu của khoảng đang mở..." | ☐ | `E3.png` |
| E4 | Không có khoảng đang mở | `GIA-KHONG`, giá xuất, T tương lai | Từ chối: "Không có khoảng giá đang mở của loại giá này..." | ☐ | `E4.png` |
| E5 | **Trái quyền** | Đăng nhập `clerk` (có xem giá, không có `prices.manage`), gửi `POST /prices` với `action=next` | HTTP 403, không có dòng giá nào mới | ☐ | `E5.png` |
| E6 | Phiếu cũ không đổi đơn giá chụp | Lập phiếu nháp xuất `GIA-MO` (chụp 75.000) **trước** E1; sau E1 mở lại phiếu | Vẫn 75.000 | ☐ | `E6.png` |
| E7 | **Cạnh tranh:** hai request cùng giá kế tiếp | Xem mục 6 (CT4) | Chỉ một request thành công; không có hai khoảng mở | ☐ | xem CT4 |

**Kiểm tra toàn vẹn sau E1–E7** (cả hai truy vấn phải ra **0 dòng**):
```sql
-- Không có sản phẩm/loại nào có hơn một khoảng đang mở
SELECT product_id, price_type, COUNT(*) AS so_khoang_mo
FROM product_prices WHERE valid_to IS NULL
GROUP BY product_id, price_type HAVING COUNT(*) > 1;

-- Không có hai khoảng giao nhau cùng sản phẩm/loại
SELECT a.id, b.id
FROM product_prices a JOIN product_prices b
  ON a.product_id=b.product_id AND a.price_type=b.price_type AND a.id<b.id
 AND a.valid_from < COALESCE(b.valid_to,'9999-12-31 00:00:00')
 AND b.valid_from < COALESCE(a.valid_to,'9999-12-31 00:00:00');
```

> Chưa có cách thử thủ công đơn giản cho yêu cầu "không mất khoảng giá nếu bước thêm mới thất bại" (rollback). Cần test tích hợp gọi `price_set_next()` và ép lỗi ở bước `INSERT`; nếu chưa làm thì ghi vào mục Giới hạn.

---

## 6. THỬ CẠNH TRANH VÀ TOÀN VẸN (mục 5 của đề)

**Nguyên tắc:** không thử cạnh tranh bằng hai lần bấm tuần tự. Hai tab cùng một session PHP có thể bị khóa session tuần tự hóa nên **không chạm** được cạnh tranh ở database. Dùng **hai session/tài khoản độc lập**: viết script gửi hai request gần như đồng thời (hai lần đăng nhập riêng, mỗi bên có session và CSRF riêng, dùng hai luồng chờ nhau rồi cùng bắn), hoặc test tích hợp gọi lớp nghiệp vụ trên hai kết nối riêng. Kết quả ghi lại bằng log của script + ảnh truy vấn kiểm tra.

> Script đề xuất: `tests/canh_tranh.py` (chưa có trong source tại thời điểm viết tài liệu này).

| # | Phép thử | Chuẩn bị | Thao tác | Kết quả mong đợi | Kết quả thực tế | Ảnh / log |
|---|---|---|---|---|---|---|
| CT1 | Hai phiếu xuất 4 từ số dư đúng 4 | Số dư K1 = 4 (sau D1, D2). Tạo hai phiếu nháp xuất K1 = 4 bằng hai tài khoản hợp lệ | Hai tài khoản (ví dụ `manager` và `admin`) gửi `POST /documents/post` gần như đồng thời | Chỉ **một** phiếu `posted`; số dư K1 = 0; phiếu còn lại `draft`; không có biến động mồ côi | ☐ | `CT1_log.txt`, `CT1.png` |
| CT1b | Gửi lại request của phiếu đã ghi sổ | Sau CT1 | Gửi lại `POST /documents/post` của phiếu đã `posted` | Số dư vẫn 0; số dòng `stock_movements` không tăng | ☐ | `CT1b.png` |
| CT2 | Hai giá giao nhau | Một sản phẩm không có giá trong khoảng thử | Hai tài khoản có `prices.manage` cùng `POST /prices` với hai khoảng **giao nhau** cho cùng sản phẩm/loại | Chỉ một khoảng được lưu; truy vấn giao nhau (mục 5) ra 0 dòng | ☐ | `CT2_log.txt`, `CT2.png` |
| CT3 | Hủy và ghi sổ cùng phiếu | Một phiếu nháp | `clerk` gửi `POST /documents/cancel`, `manager` gửi `POST /documents/post` cùng lúc | Chỉ một thao tác thắng: hoặc `cancelled` và không có biến động, hoặc `posted` và có đủ biến động; không trạng thái lai | ☐ | `CT3_log.txt`, `CT3.png` |
| CT4 | Hai lần lập giá kế tiếp cùng T | Sản phẩm có khoảng mở (ví dụ `GIA-TANG`, giá xuất) | Hai request `action=next` cùng sản phẩm, cùng loại, cùng T | Chỉ một thành công; không có hai khoảng mở (truy vấn mục 5) | ☐ | `CT4_log.txt`, `CT4.png` |

**Truy vấn kiểm tra CT1** (sau khi chạy):
```sql
SELECT d.id, d.number, d.status FROM inventory_documents d
WHERE d.id IN (<id phiếu 1>, <id phiếu 2>);           -- 1 posted, 1 draft
SELECT b.quantity FROM stock_balances b
JOIN products p ON p.id=b.product_id JOIN product_lots l ON l.id=b.lot_id
JOIN warehouse_locations loc ON loc.id=b.location_id
WHERE p.sku='DS-A' AND l.code='LOT-A1' AND loc.code='A2';   -- 0
SELECT COUNT(*) FROM stock_movements WHERE document_id IN (<id phiếu 1>, <id phiếu 2>);  -- 1 dòng (đúng số dòng của phiếu đã ghi sổ)
```

Nếu gặp deadlock: ứng dụng phải báo lỗi rõ hoặc retry có giới hạn ở **toàn transaction**, không retry từng câu SQL rời rạc.

---

## 7. GIỚI HẠN CÒN LẠI

Chức năng chưa làm không được ghi là đã hoàn thành. Các giới hạn hiện có:

**Của khung mẫu (chưa phát triển):**
- Phân quyền theo chức năng toàn hệ thống; chưa giới hạn dữ liệu theo từng kho hoặc từng người lập.
- Chưa có quy tắc người lập khác người duyệt: người có đủ quyền (ví dụ `admin`) có thể vừa lập vừa ghi sổ.
- Phiếu nháp không chỉnh sửa (hủy và lập lại); phiếu đã ghi sổ không sửa/xóa.
- Đơn vị cơ sở và số lượng nguyên; chưa quy đổi thùng/hộp/cái, chưa nhập số lượng phân số.
- Chưa có chuyển kho, trả hàng, đảo phiếu, kiểm kê, khóa kỳ, giữ hàng, xuất tự chọn FEFO.
- Chưa tính giá vốn FIFO/bình quân, thuế, chiết khấu, công nợ; không có chỉ số giá trị tồn kế toán.
- Chưa có tạo/khóa tài khoản qua giao diện, chưa thêm nhóm từ giao diện, chưa có danh mục đối tác đầy đủ, chưa có nhật ký nghiệp vụ đầy đủ. Tài khoản thử tạo bằng `bai_b_fixture.sql`.

**Của phần đã phát triển:**
- Ghi sổ luôn xét số dư **hiện tại**; lùi ngày phiếu chỉ dùng để chọn giá, không tính lại tồn lịch sử.
- Lịch giá: chỉ thêm khoảng mới và "lập giá kế tiếp"; chưa có sửa/xóa khoảng đã lưu. "Lập giá kế tiếp" chỉ nhận T ở **tương lai**, không dùng để đặt giá hiệu lực ngay hoặc trong quá khứ.
- "Lập giá kế tiếp" không gửi kèm id khoảng giá mà người dùng đã thấy: hai request cùng T thì request sau bị chặn, nhưng hai request khác T vẫn có thể nối tiếp hai mức giá liên tiếp.
- Sổ biến động lọc theo **ngày phiếu** (không theo thời điểm ghi sổ); chưa xuất CSV/PDF, chưa lọc theo lô/vị trí/loại phiếu; tổng nhập/xuất tính theo đơn vị cơ sở của từng sản phẩm.
- Ai có `inventory.view` đều xem được biến động của mọi kho.
- Chưa có test tự động cho `price_set_next()` và `movement_paginate()` trong `tests/integration.php`.
- Các ca có dấu ☐ trong tài liệu này chưa có kết quả thực tế tại thời điểm viết; không tính là đã nghiệm thu.
