<?php
/**
 * api/voting.php
 * AJAX endpoint dành cho Ban biên tập (Board):
 * - submit_vote: Bỏ phiếu duyệt/từ chối submission, cập nhật series, gửi notification
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

// Chỉ Board mới được dùng API này
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['BOARD']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Từ chối truy cập. Chỉ dành cho Ban biên tập.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
    exit();
}

$currentUser = getCurrentUser();
$db          = getDB();
$action      = trim($_POST['action'] ?? '');

/* ──────────────────────────────────────────────────────
   ACTION: submit_vote — Bỏ phiếu quyết định xuất bản
   ────────────────────────────────────────────────────── */
if ($action === 'submit_vote') {
    $submissionId   = (int)($_POST['submission_id'] ?? 0);
    $vote           = trim($_POST['vote'] ?? '');               // 'approve' | 'reject'
    $publishSchedule = trim($_POST['publish_schedule'] ?? ''); // 'weekly' | 'monthly'
    $boardNotes     = trim($_POST['board_notes'] ?? '');
    $publishDate    = trim($_POST['publish_date'] ?? '');

    // Validate
    if ($submissionId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID đệ trình không hợp lệ.']);
        exit();
    }
    if (!in_array($vote, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'message' => 'Lựa chọn bỏ phiếu không hợp lệ.']);
        exit();
    }
    if ($vote === 'approve' && !in_array($publishSchedule, ['weekly', 'monthly'])) {
        echo json_encode(['success' => false, 'message' => 'Lịch xuất bản không hợp lệ khi duyệt.']);
        exit();
    }

    try {
        // Lấy thông tin submission
        $stmt = $db->prepare(
            "SELECT s.id, s.series_id, s.manuscript_id, s.submitted_by, s.status,
                    sr.title AS series_title, sr.mangaka_id,
                    u.username AS submitter_name
             FROM submissions s
             JOIN series sr ON sr.id = s.series_id
             JOIN users u ON u.id = s.submitted_by
             WHERE s.id = ?"
        );
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch();

        if (!$submission) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy đệ trình này.']);
            exit();
        }
        if ($submission['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Đệ trình này đã được xử lý trước đó.']);
            exit();
        }

        $db->beginTransaction();

        $newSubmissionStatus = ($vote === 'approve') ? 'approved' : 'rejected';
        $newSeriesStatus     = ($vote === 'approve') ? 'publishing' : 'cancelled';

        // 1. Cập nhật submissions
        $upSub = $db->prepare(
            "UPDATE submissions
             SET status = ?, board_notes = ?, submitted_at = submitted_at
             WHERE id = ?"
        );
        $upSub->execute([$newSubmissionStatus, $boardNotes ?: null, $submissionId]);

        // 2. Cập nhật series.status và publish_schedule (nếu approve)
        if ($vote === 'approve') {
            $upSeries = $db->prepare(
                "UPDATE series
                 SET status = 'publishing', publish_schedule = ?
                 WHERE id = ?"
            );
            $upSeries->execute([$publishSchedule, $submission['series_id']]);
        } else {
            $upSeries = $db->prepare("UPDATE series SET status = 'cancelled' WHERE id = ?");
            $upSeries->execute([$submission['series_id']]);
        }

        // 3. Ghi lịch sử bỏ phiếu vào bảng votes
        //    Dùng vote_period dạng YYYY-MM-DD hoặc chuỗi kỳ hiện tại
        $period = date('Y-W'); // Tuần hiện tại
        $insVote = $db->prepare(
            "INSERT INTO votes (series_id, vote_period, reader_votes, rank_position, entered_by)
             VALUES (?, ?, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE entered_by = VALUES(entered_by)"
        );
        // Dùng chuỗi period kết hợp action để phân biệt
        $votePeriodLabel = $period . '-board-' . $vote;
        $insVote->execute([$submission['series_id'], $votePeriodLabel, $currentUser['id']]);

        // 4. Gửi notification cho Mangaka
        $mangakaId    = $submission['mangaka_id'];
        $seriesTitle  = $submission['series_title'];
        $boarderName  = $currentUser['username'];

        if ($vote === 'approve') {
            $scheduleLabel = ($publishSchedule === 'weekly') ? 'hàng tuần' : 'hàng tháng';
            $publishInfo   = $publishDate ? " bắt đầu từ ngày " . date('d/m/Y', strtotime($publishDate)) : '';
            $notifMsg = "🎉 Chúc mừng! Ban biên tập đã PHÊ DUYỆT xuất bản bộ truyện \"{$seriesTitle}\". "
                      . "Lịch xuất bản: {$scheduleLabel}{$publishInfo}. "
                      . ($boardNotes ? "Ghi chú: {$boardNotes}" : "");
            $notifType = 'submission_approved';
            $notifLink = 'mangaka/series.php';
        } else {
            $notifMsg = "❌ Ban biên tập đã TỪ CHỐI xuất bản bộ truyện \"{$seriesTitle}\". "
                      . ($boardNotes ? "Lý do: {$boardNotes}" : "Vui lòng liên hệ biên tập viên để biết thêm chi tiết.");
            $notifType = 'submission_rejected';
            $notifLink = 'mangaka/dashboard.php';
        }

        $insNotif = $db->prepare(
            "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)"
        );
        $insNotif->execute([$mangakaId, $notifType, $notifMsg, $notifLink]);

        // 5. Gửi notification cho Biên tập viên (người đã submit lên BBT)
        $editorId = $submission['submitted_by'];
        if ($editorId !== $mangakaId) {
            $editorMsg = ($vote === 'approve')
                ? "Ban biên tập đã DUYỆT bộ truyện \"{$seriesTitle}\" mà bạn đã đề xuất."
                : "Ban biên tập đã TỪ CHỐI bộ truyện \"{$seriesTitle}\" mà bạn đã đề xuất. " . ($boardNotes ? "Lý do: {$boardNotes}" : "");
            $insNotifEditor = $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, 'editor/manuscripts.php')"
            );
            $insNotifEditor->execute([$editorId, $notifType, $editorMsg]);
        }

        $db->commit();

        $resultLabel = ($vote === 'approve') ? 'Phê duyệt' : 'Từ chối';
        echo json_encode([
            'success' => true,
            'message' => "{$resultLabel} thành công! Đã gửi thông báo cho Mangaka.",
            'vote'    => $vote,
            'series_id' => $submission['series_id']
        ]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
    exit();
}

// Hành động không hợp lệ
echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
exit();
