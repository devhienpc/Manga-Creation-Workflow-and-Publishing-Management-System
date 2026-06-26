# Sơ đồ Thực thể Mối quan hệ (ERD) & Quy trình Hoạt động Cơ sở Dữ liệu

Tài liệu này mô tả chi tiết sơ đồ thực thể mối quan hệ (ERD) và giải thích quy trình hoạt động, phối hợp giữa các bảng trong cơ sở dữ liệu hệ thống quản lý xuất bản Manga (`manga_system`).

## 1. Sơ đồ Quan hệ Thực thể (ERD)

Dưới đây là sơ đồ chi tiết biểu diễn mối quan hệ giữa các bảng trong hệ thống:

```mermaid
erDiagram
    users {
        int id PK
        varchar username UK
        varchar email UK
        varchar password
        enum role "mangaka, assistant, editor, board"
        varchar avatar
        text bio
        int is_active
        timestamp created_at
    }
    series {
        int id PK
        int mangaka_id FK
        varchar title
        text description
        varchar genre
        enum status "draft, submitted, approved, publishing, cancelled"
        varchar cover_image
        enum publish_schedule "weekly, monthly"
        timestamp created_at
    }
    chapters {
        int id PK
        int series_id FK
        int chapter_number
        varchar title
        enum status "planning, in_progress, review, approved, published, rejected"
        date deadline
        timestamp created_at
    }
    pages {
        int id PK
        int chapter_id FK
        int page_number
        varchar original_file
        varchar composite_file
        enum status "pending, in_progress, approved, revision"
    }
    manuscripts {
        int id PK
        int series_id FK
        int chapter_id FK
        varchar file_path
        int version
        int submitted_by FK
        enum status "pending, reviewing, approved, rejected"
        timestamp submitted_at
    }
    tasks {
        int id PK
        int page_id FK
        int assigned_to FK "assistant"
        int assigned_by FK "mangaka/editor"
        enum task_type "background, shading, effects, lettering, cleanup"
        text description
        json region_data
        enum status "pending, in_progress, submitted, approved, revision"
        date due_date
        varchar file_result
        timestamp created_at
    }
    submissions {
        int id PK
        int series_id FK
        int manuscript_id FK
        int submitted_by FK "editor"
        enum status "pending, approved, rejected"
        text board_notes
        timestamp submitted_at
    }
    votes {
        int id PK
        int series_id FK
        varchar vote_period
        int reader_votes
        int rank_position
        int entered_by FK "editor"
        timestamp created_at
    }
    annotations {
        int id PK
        int manuscript_id FK
        int page_id FK "nullable"
        int editor_id FK "editor"
        float x_pos
        float y_pos
        float width
        float height
        text comment
        enum status "open, resolved"
        timestamp created_at
    }
    earnings {
        int id PK
        int assistant_id FK
        int month
        int year
        int approved_pages
        decimal rate_per_page
        decimal total
    }
    notifications {
        int id PK
        int user_id FK
        varchar type
        text message
        tinyint is_read
        varchar link
        timestamp created_at
    }
    defenses {
        int id PK
        int mangaka_id FK
        int chapter_id FK
        int manuscript_id FK "nullable"
        text reason
        enum status "pending, approved, rejected"
        timestamp created_at
        timestamp updated_at
    }

    users ||--o{ series : "mangaka tạo bộ truyện"
    users ||--o{ manuscripts : "nộp bản thảo"
    users ||--o{ tasks : "được giao hoặc phân công nhiệm vụ"
    users ||--o{ submissions : "trình duyệt ban biên tập"
    users ||--o{ votes : "nhập kết quả bình chọn"
    users ||--o{ annotations : "viết ghi chú sửa đổi"
    users ||--o{ earnings : "nhận thu nhập"
    users ||--o{ notifications : "nhận thông báo hệ thống"
    users ||--o{ defenses : "viết giải trình"

    series ||--o{ chapters : "chứa các chương truyện"
    series ||--o{ manuscripts : "có các bản thảo"
    series ||--o{ submissions : "được đệ trình duyệt"
    series ||--o{ votes : "được bình chọn"

    chapters ||--o{ pages : "chứa các trang truyện"
    chapters ||--o{ manuscripts : "có các bản thảo chương"
    chapters ||--o{ defenses : "có giải trình chương"

    pages ||--o{ tasks : "có các tác vụ vẽ chi tiết"
    pages ||--o{ annotations : "được đính kèm ghi chú sửa đổi"

    manuscripts ||--o{ submissions : "được đệ trình lên BBT"
    manuscripts ||--o{ annotations : "nhận ghi chú phản hồi"
    manuscripts ||--o{ defenses : "được giải trình đi kèm"
```

---

## 2. Quy trình Hoạt động của Hệ thống (Workflow)

Hệ thống hoạt động theo một quy trình khép kín từ lúc lập kế hoạch truyện cho đến khi xuất bản và thống kê hiệu quả:

### Bước 1: Khởi tạo Bộ truyện & Lập Kế hoạch Chương (`users` ➔ `series` ➔ `chapters`)
1. **Mangaka** (`users` có `role = 'mangaka'`) tạo một bộ truyện mới trong bảng `series` (ban đầu ở trạng thái `status = 'draft'`).
2. Khi bộ truyện được duyệt thông qua, Mangaka lên kế hoạch viết các chương tiếp theo trong bảng `chapters` với trạng thái ban đầu là `'planning'` hoặc `'in_progress'`.

### Bước 2: Vẽ truyện & Giao việc cho Trợ lý (`chapters` ➔ `pages` ➔ `tasks` ➔ `earnings`)
1. Mỗi chương truyện sẽ gồm nhiều trang truyện (`pages`).
2. Mangaka tải lên các trang phác thảo (`original_file`) và tạo các nhiệm vụ vẽ chi tiết (`tasks`) cho từng vùng trên trang phác thảo đó.
3. Nhiệm vụ được giao cho **Trợ lý** (`users` có `role = 'assistant'`). Trợ lý thực hiện công việc (ví dụ: vẽ nền, đổ bóng, làm sạch nét) và tải lên file kết quả (`file_result`).
4. Khi Mangaka phê duyệt nhiệm vụ (`tasks.status = 'approved'`), thông tin này sẽ là cơ sở để tính toán thu nhập hàng tháng cho trợ lý trong bảng `earnings`.
5. Trang truyện sau khi hoàn thành các nhiệm vụ sẽ được tổng hợp thành bản vẽ hoàn chỉnh (`composite_file`) và chuyển sang trạng thái duyệt.

### Bước 3: Nộp Bản thảo & Biên tập viên Phản hồi (`pages` ➔ `manuscripts` ➔ `annotations`)
1. Khi tất cả các trang của chương sẵn sàng, Mangaka xuất ra bản thảo hoàn chỉnh (định dạng PDF/ZIP) và tải lên bảng `manuscripts` với trạng thái `'pending'`.
2. **Biên tập viên** (`users` có `role = 'editor'`) tiến hành xem xét bản thảo này:
   - Nếu có lỗi hoặc điểm cần chỉnh sửa, Biên tập viên sẽ tạo các ghi chú đánh dấu (`annotations`) tọa độ cụ thể (`x_pos`, `y_pos`, `width`, `height`) trực tiếp trên trang truyện lỗi.
   - Trạng thái bản thảo chuyển thành `'rejected'` hoặc yêu cầu sửa đổi (`chapters.status = 'review'`).
3. Mangaka nhận thông báo (`notifications`), tiến hành sửa đổi cùng trợ lý và nộp lên một phiên bản (`version`) bản thảo mới.

### Bước 4: Giải trình & Đệ trình Ban biên tập (`manuscripts` ➔ `defenses` ➔ `submissions`)
1. Trong trường hợp bản thảo bị từ chối hoặc trễ hạn nhưng Mangaka có lý do chính đáng hoặc muốn thuyết minh ý đồ nghệ thuật, Mangaka có thể gửi một bản giải trình trong bảng `defenses`.
2. Nếu Biên tập viên đồng ý thông qua bản thảo, họ sẽ đệ trình bản thảo đó lên **Ban biên tập** (Board) xét duyệt in ấn bằng cách tạo bản ghi trong bảng `submissions`.
3. **Đại diện Ban biên tập** (`users` có `role = 'board'`) xem xét và đưa ra quyết định cuối cùng (`submissions.status` chuyển thành `'approved'` hoặc `'rejected'`).

### Bước 5: Xuất bản & Đánh giá Độc giả (`submissions` ➔ `chapters` / `series` ➔ `votes`)
1. Khi Ban biên tập phê duyệt (`submissions.status = 'approved'`), chương truyện được cập nhật trạng thái xuất bản (`chapters.status = 'published'`).
2. Sau khi truyện ra mắt độc giả, định định kỳ hàng tuần/tháng, Biên tập viên sẽ nhập số liệu bình chọn của độc giả vào bảng `votes` để đánh giá thứ hạng (`rank_position`) và mức độ yêu thích của bộ truyện đó. Điều này giúp đưa ra quyết định tiếp tục phát triển hay hủy bỏ bộ truyện (`series.status = 'cancelled'`).

---

## 3. Các Luồng Liên thông Khác
- **Thông báo (`notifications`)**: Hệ thống tự động tạo thông báo khi có các sự kiện phát sinh như: phân công task mới, yêu cầu chỉnh sửa bản thảo, gửi giải trình, hoặc duyệt xuất bản.
- **Tính toán Thu nhập (`earnings`)**: Dựa trên số trang đã duyệt (`approved_pages`) của trợ lý trong tháng để tổng hợp tiền lương cuối kỳ.
