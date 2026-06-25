CREATE DATABASE IF NOT EXISTS manga_system;
USE manga_system;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS defenses;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS earnings;
DROP TABLE IF EXISTS annotations;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS submissions;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS manuscripts;
DROP TABLE IF EXISTS pages;
DROP TABLE IF EXISTS chapters;
DROP TABLE IF EXISTS series;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. BẢNG NGƯỜI DÙNG (users)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('mangaka', 'assistant', 'editor', 'board') NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    is_active INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. BẢNG BỘ TRUYỆN (series)
CREATE TABLE series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mangaka_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    genre VARCHAR(100),
    status ENUM('draft', 'submitted', 'approved', 'publishing', 'cancelled') DEFAULT 'draft',
    cover_image VARCHAR(255) DEFAULT NULL,
    publish_schedule ENUM('weekly', 'monthly') DEFAULT 'weekly',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_series_mangaka FOREIGN KEY (mangaka_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. BẢNG CHƯƠNG TRUYỆN (chapters)
CREATE TABLE chapters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    chapter_number INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    status ENUM('planning', 'in_progress', 'review', 'approved', 'published', 'rejected') DEFAULT 'planning',
    deadline DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chapters_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    UNIQUE KEY uq_series_chapter (series_id, chapter_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. BẢNG TRANG TRUYỆN (pages)
CREATE TABLE pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chapter_id INT NOT NULL,
    page_number INT NOT NULL,
    original_file VARCHAR(255) DEFAULT NULL,
    composite_file VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'approved', 'revision') DEFAULT 'pending',
    CONSTRAINT fk_pages_chapters FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
    UNIQUE KEY uq_chapter_page (chapter_id, page_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. BẢNG BẢN THẢO (manuscripts)
CREATE TABLE manuscripts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    chapter_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    version INT DEFAULT 1,
    submitted_by INT NOT NULL,
    status ENUM('pending', 'reviewing', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_manuscripts_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    CONSTRAINT fk_manuscripts_chapters FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
    CONSTRAINT fk_manuscripts_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. BẢNG NHIỆM VỤ CỦA TRỢ LÝ (tasks)
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    task_type ENUM('background', 'shading', 'effects', 'lettering', 'cleanup') NOT NULL,
    description TEXT,
    region_data JSON DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'submitted', 'approved', 'revision') DEFAULT 'pending',
    due_date DATE DEFAULT NULL,
    file_result VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_pages FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. BẢNG ĐỆ TRÌNH BAN BIÊN TẬP (submissions)
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    manuscript_id INT NOT NULL,
    submitted_by INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    board_notes TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_submissions_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_manuscript FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. BẢNG ĐÁNH GIÁ/BÌNH CHỌN CỦA ĐỘC GIẢ (votes)
CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT NOT NULL,
    vote_period VARCHAR(50) NOT NULL,
    reader_votes INT DEFAULT 0,
    rank_position INT DEFAULT NULL,
    entered_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_votes_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. BẢNG GHI CHÚ/CHỈ DẪN CỦA BIÊN TẬP VIÊN (annotations)
CREATE TABLE annotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manuscript_id INT NOT NULL,
    page_id INT DEFAULT NULL,
    editor_id INT NOT NULL,
    x_pos FLOAT NOT NULL,
    y_pos FLOAT NOT NULL,
    width FLOAT NOT NULL,
    height FLOAT NOT NULL,
    comment TEXT NOT NULL,
    status ENUM('open', 'resolved') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_annotations_manuscript FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE CASCADE,
    CONSTRAINT fk_annotations_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_annotations_editor FOREIGN KEY (editor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. BẢNG THU NHẬP TRỢ LÝ (earnings)
CREATE TABLE earnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assistant_id INT NOT NULL,
    month INT NOT NULL,
    year INT NOT NULL,
    approved_pages INT DEFAULT 0,
    rate_per_page DECIMAL(12, 2) NOT NULL,
    total DECIMAL(12, 2) NOT NULL,
    CONSTRAINT fk_earnings_assistant FOREIGN KEY (assistant_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_assistant_period (assistant_id, month, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. BẢNG THÔNG BÁO (notifications)
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    link VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. BẢNG BIỆN HỘ / GIẢI TRÌNH BẢN THẢO (defenses)
CREATE TABLE defenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mangaka_id INT NOT NULL,
    chapter_id INT NOT NULL,
    manuscript_id INT DEFAULT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_defenses_mangaka FOREIGN KEY (mangaka_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_defenses_chapter FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
    CONSTRAINT fk_defenses_manuscript FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- INDEXES BỔ SUNG CHO TỐI ƯU TRUY VẤN
-- ==========================================
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_series_status ON series(status);
CREATE INDEX idx_chapters_status ON chapters(status);
CREATE INDEX idx_pages_status ON pages(status);
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_task_type ON tasks(task_type);
CREATE INDEX idx_manuscripts_status ON manuscripts(status);
CREATE INDEX idx_submissions_status ON submissions(status);
CREATE INDEX idx_votes_period ON votes(vote_period);
CREATE INDEX idx_notifications_unread ON notifications(user_id, is_read);
CREATE INDEX idx_defenses_status ON defenses(status);

-- ==========================================
-- DỮ LIỆU MẪU (SEED DATA)
-- ==========================================

-- Mật khẩu mẫu tương ứng với: username + "123" (được mã hóa BCrypt chuẩn PHP)
-- mangaka123: $2y$10$VYZfOtio0GFofEevAi1wousFpn4XjW2lcnYQAJ/ToaKkrpzS0DvdG
-- assistant123: $2y$10$wGJItcX3IsthWXpTcfjk/uWW6Uf4vqIGDmih8V86uO3yRFY7EpgGS
-- editor123: $2y$10$7X7yCaEeGGfiYkeVGuT3NehBHvSmAKxfy3YoTba02obry.S7R.g/2
-- board123: $2y$10$X33JXtu4FffbnYQocdIAOeN6PMj2xFYk7UA3zHaEqiyUrzdL25BYW

INSERT INTO users (id, username, email, password, role, avatar, bio) VALUES
(1, 'mangaka', 'mangaka@mangasystem.com', '$2y$10$VYZfOtio0GFofEevAi1wousFpn4XjW2lcnYQAJ/ToaKkrpzS0DvdG', 'mangaka', 'assets/images/avatars/mangaka.png', 'Họa sĩ Manga chuyên nghiệp với 10 năm kinh nghiệm trong dòng Shonen.'),
(2, 'assistant', 'assistant@mangasystem.com', '$2y$10$wGJItcX3IsthWXpTcfjk/uWW6Uf4vqIGDmih8V86uO3yRFY7EpgGS', 'assistant', 'assets/images/avatars/assistant1.png', 'Chuyên vẽ background và hiệu ứng line-art, tốc độ vẽ nhanh.'),
(3, 'assistant_2', 'assistant2@mangasystem.com', '$2y$10$wGJItcX3IsthWXpTcfjk/uWW6Uf4vqIGDmih8V86uO3yRFY7EpgGS', 'assistant', 'assets/images/avatars/assistant2.png', 'Hỗ trợ đi nét sạch, tô bóng và dàn hội thoại chữ manga.'),
(4, 'editor', 'editor@mangasystem.com', '$2y$10$7X7yCaEeGGfiYkeVGuT3NehBHvSmAKxfy3YoTba02obry.S7R.g/2', 'editor', 'assets/images/avatars/editor.png', 'Biên tập viên trưởng ban Shonen Jump Magazine.'),
(5, 'board', 'board@mangasystem.com', '$2y$10$X33JXtu4FffbnYQocdIAOeN6PMj2xFYk7UA3zHaEqiyUrzdL25BYW', 'board', 'assets/images/avatars/board.png', 'Đại diện Ban biên tập và chịu trách nhiệm quyết định in ấn xuất bản.');

-- Seeding cho bảng series
INSERT INTO series (id, mangaka_id, title, description, genre, status, cover_image, publish_schedule) VALUES
(1, 1, 'Hành trình thế giới ảo', 'Một cậu bé tình cờ lạc vào thế giới trò chơi thực tế ảo đầy rẫy hiểm nguy nhưng cũng vô vàn phần thưởng hấp dẫn.', 'Isekai, Phiêu lưu, Action', 'publishing', 'assets/images/covers/isekai_journey.png', 'weekly'),
(2, 1, 'Huyền thoại kiếm sĩ', 'Kiếm sĩ cuối cùng của vương quốc đi tìm kiếm thanh thần kiếm bị thất lạc để cứu lấy bờ cõi khỏi bóng tối.', 'Hành động, Fantasy', 'submitted', 'assets/images/covers/sword_legend.png', 'weekly'),
(3, 1, 'Tình yêu học đường', 'Câu chuyện tình yêu nhẹ nhàng, hài hước giữa cô bạn lớp trưởng năng động và cậu học sinh cá biệt.', 'Romance, Slice of Life', 'draft', 'assets/images/covers/school_love.png', 'monthly');

-- Seeding cho bảng chapters
INSERT INTO chapters (id, series_id, chapter_number, title, status, deadline) VALUES
(1, 1, 41, 'Chiến thắng cuối cùng', 'published', '2026-06-10'),
(2, 1, 42, 'Khởi đầu mới', 'published', '2026-06-17'),
(3, 1, 43, 'Kẻ thù trong bóng tối', 'review', '2026-06-24'),
(4, 2, 1, 'Kiếm sĩ thức tỉnh', 'planning', '2026-07-05');

-- Seeding cho bảng pages
INSERT INTO pages (id, chapter_id, page_number, original_file, composite_file, status) VALUES
(1, 3, 1, 'uploads/chapters/43/p1_draft.png', 'uploads/chapters/43/p1_composite.png', 'approved'),
(2, 3, 2, 'uploads/chapters/43/p2_draft.png', 'uploads/chapters/43/p2_composite.png', 'in_progress'),
(3, 3, 3, 'uploads/chapters/43/p3_draft.png', NULL, 'pending');

-- Seeding cho bảng manuscripts
INSERT INTO manuscripts (id, series_id, chapter_id, file_path, version, submitted_by, status, submitted_at) VALUES
(1, 1, 2, 'uploads/manuscripts/journey_c42_v1.pdf', 1, 1, 'approved', '2026-06-16 14:00:00'),
(2, 1, 3, 'uploads/manuscripts/journey_c43_v1.pdf', 1, 1, 'pending', '2026-06-20 08:30:00');

-- Seeding cho bảng tasks (phân công trợ lý vẽ)
INSERT INTO tasks (id, page_id, assigned_to, assigned_by, task_type, description, region_data, status, due_date, file_result) VALUES
(1, 2, 2, 1, 'background', 'Vẽ phông nền các tòa nhà chọc trời đổ nát theo phong cách cyberpunk.', '{"x": 10, "y": 20, "w": 80, "h": 50}', 'in_progress', '2026-06-22', NULL),
(2, 1, 3, 1, 'shading', 'Tạo bóng cho khuôn mặt nhân vật chính ở góc dưới bên phải.', '{"x": 50, "y": 60, "w": 40, "h": 30}', 'approved', '2026-06-19', 'uploads/tasks/result_p1_shading.png');

-- Seeding cho bảng submissions (gửi BBT)
INSERT INTO submissions (id, series_id, manuscript_id, submitted_by, status, board_notes) VALUES
(1, 1, 1, 4, 'approved', 'Nội dung chương 42 rất tốt, hình ảnh sắc nét, bố cục hợp lý. Duyệt xuất bản.');

-- Seeding cho bảng votes (Bình chọn truyện)
INSERT INTO votes (id, series_id, vote_period, reader_votes, rank_position, entered_by) VALUES
(1, 1, '2026-W24', 12450, 1, 4),
(2, 2, '2026-W24', 6800, 4, 4);

-- Seeding cho bảng annotations (Ghi chú chỉnh sửa bản thảo từ biên tập)
INSERT INTO annotations (id, manuscript_id, page_id, editor_id, x_pos, y_pos, width, height, comment, status) VALUES
(1, 2, 2, 4, 150.5, 300.2, 80.0, 50.0, 'Nét vẽ nền chỗ này chưa khớp với phối cảnh của nhân vật, cần sửa lại.', 'open');

-- Seeding cho bảng earnings (Thu nhập trợ lý)
INSERT INTO earnings (id, assistant_id, month, year, approved_pages, rate_per_page, total) VALUES
(1, 2, 6, 2026, 18, 300000.00, 5400000.00),
(2, 3, 6, 2026, 10, 250000.00, 2500000.00);

-- Seeding cho bảng notifications
INSERT INTO notifications (id, user_id, type, message, is_read, link) VALUES
(1, 2, 'task_assigned', 'Họa sĩ Manga đã giao cho bạn một nhiệm vụ vẽ background mới.', 0, 'assistant/tasks.php'),
(2, 1, 'manuscript_review', 'Biên tập viên đã thêm ghi chú yêu cầu sửa đổi chương 43.', 0, 'mangaka/dashboard.php');

-- Seeding cho bảng defenses (Giải trình bản thảo)
INSERT INTO defenses (id, mangaka_id, chapter_id, manuscript_id, reason, status, created_at, updated_at) VALUES
(1, 1, 3, 2, 'Kính gửi Ban Biên Tập, tôi xin giải trình về việc chỉnh sửa lại toàn bộ các khung hình chiến đấu ở trang 12 và 13 theo đúng góp ý của Biên tập viên ở phiên bản trước. Tôi cũng đã nâng cấp chi tiết background cảnh đổ nát và cải thiện phần đi nét của nhân vật chính để tăng tính kịch tính cho phân cảnh cao trào. Rất mong Biên tập viên xem xét lại và thông qua bản thảo này để kịp tiến độ xuất bản tuần tới. Xin chân thành cảm ơn!', 'pending', '2026-06-23 10:00:00', '2026-06-23 10:00:00'),
(2, 1, 2, 1, 'Bản thảo chương 42 bị hệ thống đánh dấu từ chối ban đầu là do lỗi trùng lặp tệp tin khi upload hai lần liên tiếp. Tôi xin đính kèm bản giải trình này cùng tệp tin chính xác nhất đã được tinh chỉnh phần hiệu ứng tô bóng. Kính mong Biên tập viên phê duyệt để chúng tôi thực hiện các chương tiếp theo.', 'approved', '2026-06-15 09:00:00', '2026-06-16 14:00:00');