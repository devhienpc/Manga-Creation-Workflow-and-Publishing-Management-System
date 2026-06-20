<?php
/**
 * api/annotations.php
 * AJAX endpoint phục vụ các tác vụ của Biên tập viên:
 * - create: Tạo ghi chú chỉnh sửa (Annotation)
 * - resolve: Giải quyết ghi chú
 * - submit_to_board: Chuyển tiếp bản thảo lên Ban biên tập
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

// Phải đăng nhập và là Biên tập viên mới được dùng API này
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['EDITOR']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Từ chối truy cập. Chỉ dành cho Biên tập viên.']);
    exit();
}

$currentUser = getCurrentUser();
$db = getDB();
$action = trim($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
    exit();
}

// 1. ACTION: create - Tạo ghi chú chỉnh sửa
if ($action === 'create') {
    $manuscriptId = (int)($_POST['manuscript_id'] ?? 0);
    $pageId       = (int)($_POST['page_id'] ?? 0);
    $xPos         = (float)($_POST['x_pos'] ?? 0);
    $yPos         = (float)($_POST['y_pos'] ?? 0);
    $width        = (float)($_POST['width'] ?? 0);
    $height       = (float)($_POST['height'] ?? 0);
    $comment      = trim($_POST['comment'] ?? '');

    if ($manuscriptId <= 0 || $pageId <= 0 || empty($comment)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin hoặc bình luận rỗng.']);
        exit();
    }

    try {
        // Chèn vào bảng annotations
        $stmt = $db->prepare(
            "INSERT INTO annotations (manuscript_id, page_id, editor_id, x_pos, y_pos, width, height, comment, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')"
        );
        $stmt->execute([
            $manuscriptId,
            $pageId,
            $currentUser['id'],
            $xPos,
            $yPos,
            $width,
            $height,
            $comment
        ]);
        
        $newId = (int)$db->lastInsertId();

        // Cập nhật trạng thái bản thảo thành đang duyệt (reviewing) nếu hiện tại là pending
        $db->prepare("UPDATE manuscripts SET status = 'reviewing' WHERE id = ? AND status = 'pending'")
           ->execute([$manuscriptId]);

        echo json_encode([
            'success' => true,
            'message' => 'Tạo ghi chú thành công!',
            'annotation' => [
                'id' => $newId,
                'x_pos' => $xPos,
                'y_pos' => $yPos,
                'width' => $width,
                'height' => $height,
                'comment' => $comment,
                'status' => 'open'
            ]
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
    }
    exit();
}

// 2. ACTION: resolve - Giải quyết ghi chú chỉnh sửa
if ($action === 'resolve') {
    $annotationId = (int)($_POST['annotation_id'] ?? 0);

    if ($annotationId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID ghi chú không hợp lệ.']);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE annotations SET status = 'resolved' WHERE id = ?");
        $stmt->execute([$annotationId]);

        echo json_encode(['success' => true, 'message' => 'Đã đánh dấu giải quyết ghi chú.']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
    }
    exit();
}

// 3. ACTION: submit_to_board - Chuyển tiếp bản thảo lên Ban biên tập
if ($action === 'submit_to_board') {
    $manuscriptId = (int)($_POST['manuscript_id'] ?? 0);
    $boardNotes   = trim($_POST['board_notes'] ?? '');

    if ($manuscriptId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID bản thảo không hợp lệ.']);
        exit();
    }

    try {
        // Truy vấn thông tin bản thảo
        $stmt = $db->prepare("SELECT series_id, chapter_id, submitted_by FROM manuscripts WHERE id = ?");
        $stmt->execute([$manuscriptId]);
        $manuscript = $stmt->fetch();

        if (!$manuscript) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy bản thảo.']);
            exit();
        }

        $db->beginTransaction();

        // 3.1. Cập nhật trạng thái bản thảo thành đã duyệt (approved)
        $upM = $db->prepare("UPDATE manuscripts SET status = 'approved' WHERE id = ?");
        $upM->execute([$manuscriptId]);

        // 3.2. Cập nhật trạng thái chương tương ứng sang 'review' (đang chờ BBT phê duyệt xuất bản)
        if ($manuscript['chapter_id']) {
            $upC = $db->prepare("UPDATE chapters SET status = 'review' WHERE id = ?");
            $upC->execute([$manuscript['chapter_id']]);
        }

        // 3.3. Tạo mới hoặc cập nhật thông tin trong bảng đệ trình (submissions)
        // Kiểm tra xem đã có đệ trình cho bản thảo này chưa
        $stmtSub = $db->prepare("SELECT id FROM submissions WHERE manuscript_id = ?");
        $stmtSub->execute([$manuscriptId]);
        $subId = $stmtSub->fetchColumn();

        if ($subId) {
            $upSub = $db->prepare(
                "UPDATE submissions 
                 SET status = 'pending', board_notes = ?, submitted_at = NOW() 
                 WHERE id = ?"
            );
            $upSub->execute([$boardNotes, $subId]);
        } else {
            $insSub = $db->prepare(
                "INSERT INTO submissions (series_id, manuscript_id, submitted_by, status, board_notes, submitted_at)
                 VALUES (?, ?, ?, 'pending', ?, NOW())"
            );
            $insSub->execute([
                $manuscript['series_id'],
                $manuscriptId,
                $currentUser['id'], // Người gửi đề xuất: Biên tập viên
                $boardNotes
            ]);
        }

        // Lấy tên series và số chương cho thông báo
        $sStmt = $db->prepare("SELECT title FROM series WHERE id = ?");
        $sStmt->execute([$manuscript['series_id']]);
        $seriesTitle = $sStmt->fetchColumn();

        $cNumber = '';
        if ($manuscript['chapter_id']) {
            $cStmt = $db->prepare("SELECT chapter_number FROM chapters WHERE id = ?");
            $cStmt->execute([$manuscript['chapter_id']]);
            $cNumber = $cStmt->fetchColumn();
        }
        $chapterStr = $cNumber ? "Chương $cNumber" : 'Toàn bộ series';

        // 3.4. Gửi thông báo cho họa sĩ (Mangaka)
        $notifMsgMangaka = "Tin vui! Biên tập viên đã duyệt bản thảo ($chapterStr) của bộ truyện \"$seriesTitle\" và chuyển tiếp lên Ban biên tập phê duyệt xuất bản.";
        $insNotif = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'manuscript_decision', ?, 'mangaka/dashboard.php')");
        $insNotif->execute([$manuscript['submitted_by'], $notifMsgMangaka]);

        // 3.5. Gửi thông báo cho toàn bộ Ban biên tập (Board members)
        $notifMsgBoard = "Yêu cầu phê duyệt mới: Biên tập viên đã đề xuất xuất bản bộ truyện \"$seriesTitle\" - $chapterStr.";
        
        $stmtBoard = $db->prepare("SELECT id FROM users WHERE role = ?");
        $stmtBoard->execute([ROLES['BOARD']]);
        $boardUsers = $stmtBoard->fetchAll(PDO::FETCH_COLUMN);
        
        $insNotifBoard = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'manuscript_review', ?, 'board/dashboard.php')");
        foreach ($boardUsers as $boardUid) {
            $insNotifBoard->execute([$boardUid, $notifMsgBoard]);
        }

        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Đã phê duyệt và đề xuất xuất bản lên Ban biên tập thành công!']);
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
    exit();
}

// Hành động không hợp lệ
echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
exit();
