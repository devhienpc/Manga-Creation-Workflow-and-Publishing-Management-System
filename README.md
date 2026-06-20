# Manga Creation Workflow and Publishing Management System
> **Hệ Thống Quản Lý Quy Trình Sáng Tác & Xuất Bản Manga**

Hệ thống quản lý quy trình khép kín từ lúc Họa sĩ (Mangaka) lên ý tưởng, cộng tác cùng Trợ lý (Assistant) để hoàn thiện bản vẽ, cho đến khâu kiểm duyệt chi tiết của Biên tập viên (Editor) và phê duyệt xuất bản bởi Ban biên tập (Board).

---

## 🚀 Tính năng nổi bật theo Vai trò

### 1. 🎨 Họa sĩ (Mangaka)
*   **Quản lý Series**: Danh sách các tác phẩm dạng card/table, lọc trạng thái, sắp xếp theo ngày tạo. Form tạo tác phẩm mới hỗ trợ tải lên ảnh bìa (Cover Image) và thiết lập lịch xuất bản.
*   **Nộp Bản Thảo Sơ Bộ**: Cho phép tải lên tệp bản thảo định dạng PDF/ZIP cho chương truyện mới và gửi ghi chú cho ban biên tập.
*   **Giao Việc Cho Trợ Lý (Visual Task Assignment)**: 
    *   Chọn chương và trang truyện cần hỗ trợ vẽ.
    *   Sử dụng chuột vẽ trực tiếp các vùng chọn (Rectangle selection) trên trang bản thảo bằng JS Canvas/Overlay.
    *   Phân công công việc theo vùng tọa độ (`%` x, y, width, height) cho từng trợ lý cụ thể kèm theo loại công việc (Vẽ nền, tô bóng, hiệu ứng, đi nét, chèn thoại), mô tả và deadline.
*   **Duyệt Kết Quả**: Đánh giá kết quả trợ lý nộp lên, nhấn **Approve** (Duyệt) hoặc **Request Revision** (Yêu cầu sửa đổi kèm nhận xét).

### 2. ✒️ Trợ lý (Assistant)
*   **Bảng điều khiển (Dashboard)**: Thống kê số nhiệm vụ đang làm, số trang vẽ hoàn thành, tiến độ deadline trong 7 ngày tới (đánh dấu đỏ) và thu nhập ước tính trong tháng.
*   **Nhận & Thực hiện Nhiệm vụ**:
    *   Danh sách công việc được giao lọc theo loại và trạng thái.
    *   Xem chi tiết nhiệm vụ trực quan: Hiển thị ảnh trang vẽ gốc kèm **vùng chọn khoanh đỏ** (highlight rectangle) được họa sĩ giao.
    *   Tải xuống tài nguyên đính kèm, vẽ hoàn thiện và tải lên kết quả (PNG/PSD/ZIP) để gửi duyệt.
*   **Theo dõi Thu nhập**: Biểu đồ cột trực quan (sử dụng Chart.js) thống kê thu nhập 6 tháng gần nhất cùng bảng liệt kê chi tiết số trang hoàn thành và đơn giá được chốt.

### 3. 📝 Biên tập viên (Editor)
*   **Kiểm duyệt Bản thảo & Ghi chú Trực quan (Annotation View)**:
    *   Mở xem bản thảo đã nộp của họa sĩ.
    *   Nhấp chuột kéo thả vẽ khung ghi chú màu vàng nhạt đè trực tiếp lên vị trí nét vẽ bị lỗi trên ảnh trang truyện.
    *   Lưu ghi chú kèm mô tả chi tiết và hỗ trợ nhấn **Resolve** để đánh dấu lỗi đã được sửa xong.
    *   Đệ trình đề xuất lên Ban biên tập (**Submit to Board**) kèm đánh giá tổng quan khi bản thảo đạt yêu cầu.
*   **Theo dõi Tiến độ Studio (Studio Progress)**:
    *   Lưới hiển thị thumbnail trực quan kèm Badge trạng thái (`Chờ xử lý`, `Đang vẽ`, `Đã duyệt`, `Cần sửa`) cho từng trang truyện của chương.
    *   Bảng phân rã chi tiết (Breakdown) tiến trình hoàn thành của từng loại nhiệm vụ.
    *   Chế độ làm mới tự động (JS countdown 60s) hoặc làm mới thủ công để cập nhật tiến độ real-time.
    *   Xuất báo cáo chi tiết tiến độ ra tệp **CSV** (hỗ trợ hiển thị tiếng Việt Unicode UTF-8 BOM).
*   **Chốt lương cuối tháng (Payout Finalization)**: Form tính toán tổng kết thu nhập cho trợ lý dựa trên số lượng trang đã duyệt trong tháng và đơn giá thỏa thuận. Tự động cập nhật bảng lương và gửi thông báo hệ thống cho trợ lý.

### 4. 🏢 Ban biên tập (Board)
*   **Quyết định xuất bản**: Duyệt các hồ sơ đệ trình của Biên tập viên, ghi nhận ý kiến và quyết định phê duyệt (`approved`) hoặc từ chối (`rejected`) in ấn phát hành.
*   **Bảng Xếp Hạng & Cảnh Báo (Ranking)**:
    *   Xem bảng xếp hạng các bộ truyện dựa trên số phiếu bình chọn của độc giả (`reader_votes`).
    *   Hiển thị xu hướng tăng/giảm hạng (trend) bằng mũi tên chỉ hướng.
    *   **Cảnh báo đỏ**: Cảnh báo nguy cơ hủy bản thảo/ngưng xuất bản đối với các bộ truyện nằm ngoài nhóm top 70%.

---

## 🛠️ Công nghệ sử dụng
*   **Backend**: PHP thuần (PHP 7.4+, tương thích hoàn toàn PHP 8.x). Sử dụng PDO kết nối cơ sở dữ liệu an toàn, chống SQL Injection.
*   **Database**: MySQL/MariaDB (Có cơ chế tự động dự phòng sang file SQLite cục bộ `config/database.sqlite` nếu MySQL chưa được cấu hình).
*   **Frontend**: HTML5, Vanilla CSS (Dark mode cao cấp, màu nhấn chủ đạo `#E63946`, bo góc mượt mà, tối ưu hiển thị) và JavaScript (Canvas API, Fetch API, Chart.js).

---

## 📂 Cấu trúc Thư mục chính
```text
Manga Project/
├── api/                   # Các endpoint AJAX (annotations, notifications, tasks...)
├── assets/                # Tài nguyên tĩnh
│   ├── css/               # File định dạng CSS (main.css, style.css)
│   ├── images/            # Ảnh mẫu và cover truyện
│   └── uploads/           # Thư mục lưu file tải lên (covers, manuscripts, tasks...)
├── assistant/             # Giao diện & chức năng cho Trợ lý
├── auth/                  # Xử lý đăng nhập, đăng xuất
├── board/                 # Giao diện cho Ban biên tập
├── config/                # Cấu hình hệ thống (CSDL, constants, auth)
├── editor/                # Giao diện & chức năng cho Biên tập viên
├── includes/              # Giao diện dùng chung (layout.php, footer.php)
├── mangaka/               # Giao diện & chức năng cho Họa sĩ
├── database.sql           # File cấu trúc CSDL & Dữ liệu mẫu (Seed data)
└── index.php              # Điểm điều hướng chính dựa theo Session vai trò
```

---

## ⚙️ Hướng dẫn Cài đặt & Khởi chạy

### Bước 1: Chuẩn bị Môi trường
*   Cài đặt phần mềm giả lập server cục bộ như **Laragon** (Khuyên dùng), XAMPP hoặc MAMP.
*   Kích hoạt dịch vụ **Apache** và **MySQL**.

### Bước 2: Cài đặt Cơ sở Dữ liệu
1.  Truy cập công cụ quản lý CSDL (như phpMyAdmin, HeidiSQL).
2.  Tạo một cơ sở dữ liệu mới tên là `manga_system`.
3.  Nhập (Import) file [database.sql](file:///c:/laragon/www/Manga%20Project/database.sql) vào cơ sở dữ liệu vừa tạo.

### Bước 3: Cấu hình Kết nối CSDL
Mở file [config/db.php](file:///c:/laragon/www/Manga%20Project/config/db.php) và cấu hình lại thông tin đăng nhập MySQL của bạn (nếu có thay đổi):
```php
$host = 'localhost';
$db   = 'manga_system';
$user = 'root';
$pass = ''; // Mật khẩu mặc định của Laragon là rỗng
```
*Lưu ý: Nếu không thể kết nối tới MySQL, hệ thống sẽ tự động khởi tạo file cơ sở dữ liệu SQLite cục bộ tại `config/database.sqlite`.*

---

## 🔑 Tài khoản Thử nghiệm (Seed Data)

Bạn có thể sử dụng các tài khoản mẫu dưới đây để đăng nhập trải nghiệm hệ thống:

| Vai trò | Tài khoản (Username) | Mật khẩu (Password) | Mô tả |
| :--- | :--- | :--- | :--- |
| **Mangaka** | `mangaka` | `mangaka123` | Họa sĩ sáng tác chính |
| **Assistant 1** | `assistant` | `assistant123` | Trợ lý chuyên vẽ Background & Shading |
| **Assistant 2** | `assistant_2` | `assistant123` | Trợ lý hỗ trợ Line-art & Lettering |
| **Editor** | `editor` | `editor123` | Biên tập viên phụ trách giám sát tác phẩm |
| **Board Member** | `board` | `board123` | Thành viên Ban biên tập duyệt in ấn |

---

## 🧪 Quy trình Kiểm thử Khuyên dùng (Testing Flow)

1.  **Đăng nhập editor** -> Chọn bản thảo chương truyện để đọc thử và vẽ thêm ghi chú lỗi cần sửa (`annotations`).
2.  **Đăng nhập mangaka** -> Xem danh sách ghi chú lỗi từ editor. Vào mục Giao việc để phân công các vùng cần chỉnh sửa cho trợ lý (`assistant`).
3.  **Đăng nhập assistant** -> Nhấp xem nhiệm vụ được giao, tải file gốc, xem vùng khoanh đỏ hướng dẫn vẽ và upload file kết quả nộp bài.
4.  **Đăng nhập mangaka** -> Approve nhiệm vụ hoàn thành của trợ lý.
5.  **Đăng nhập editor** -> Xem tiến độ studio hoàn thành 100%, xuất báo cáo CSV tiến trình. Tiến hành chốt lương (finalize earnings) cuối tháng cho trợ lý.
6.  **Đăng nhập board** -> Xem đề xuất phát hành của editor và xác nhận Approve xuất bản chính thức.