<?php
/**
 * api/ranking.php
 * AJAX endpoint cho Ban biên tập:
 *   - submit_votes   : Nhập kết quả bình chọn kỳ mới, tính rank, gửi notification rank_drop
 *   - cancel_series  : Huỷ series đang xuất bản
 *   - change_schedule: Thay đổi lịch xuất bản weekly ↔ monthly
 *   - log_decision   : Ghi log quyết định vào bảng votes (reuse vote_period format)
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonOut(false, 'Phương thức không hợp lệ.');
}

if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['BOARD']) {
    http_response_code(403);
    jsonOut(false, 'Từ chối truy cập. Chỉ dành cho Ban biên tập.');
}

$currentUser = getCurrentUser();
$db          = getDB();
$action      = trim($_POST['action'] ?? '');

/* ══════════════════════════════════════════════════════════
   ACTION: submit_votes
   Nhập dữ liệu bình chọn cho một kỳ, tính rank_position,
   gửi notification rank_drop cho mangaka tụt hạng nguy hiểm
   ══════════════════════════════════════════════════════════ */
if ($action === 'submit_votes') {
    $periodType = trim($_POST['period_type'] ?? ''); // 'week' | 'month'
    $periodNum  = (int)($_POST['period_num']  ?? 0); // số tuần hoặc tháng
    $periodYear = (int)($_POST['period_year'] ?? date('Y'));
    $votesJson  = trim($_POST['votes_data']   ?? ''); // JSON: [{series_id, reader_votes}, ...]

    if (!in_array($periodType, ['week', 'month'])) {
        jsonOut(false, 'Loại kỳ không hợp lệ (week/month).');
    }
    if ($periodNum < 1 || $periodNum > 53) {
        jsonOut(false, 'Số kỳ không hợp lệ.');
    }
    if ($periodYear < 2020 || $periodYear > 2100) {
        jsonOut(false, 'Năm không hợp lệ.');
    }

    $votesData = json_decode($votesJson, true);
    if (!is_array($votesData) || empty($votesData)) {
        jsonOut(false, 'Dữ liệu bình chọn trống hoặc không đúng định dạng.');
    }

    // Tạo chuỗi vote_period chuẩn
    $votePeriod = $periodType === 'week'
        ? sprintf('%d-W%02d', $periodYear, $periodNum)
        : sprintf('%d-M%02d', $periodYear, $periodNum);

    // Kiểm tra trùng kỳ
    $dupStmt = $db->prepare(
        "SELECT COUNT(*) FROM votes WHERE vote_period = ? AND series_id IN (" .
        implode(',', array_fill(0, count($votesData), '?')) . ")"
    );
    $dupParams = [$votePeriod];
    foreach ($votesData as $v) $dupParams[] = (int)($v['series_id'] ?? 0);
    $dupStmt->execute($dupParams);
    if ((int)$dupStmt->fetchColumn() > 0) {
        jsonOut(false, "Kỳ \"{$votePeriod}\" đã có dữ liệu bình chọn. Vui lòng chọn kỳ khác.");
    }

    // Lấy kỳ liền trước để so sánh rank (phát hiện tụt hạng)
    $prevPeriodStmt = $db->prepare(
        "SELECT series_id, rank_position FROM votes
         WHERE vote_period != ?
         ORDER BY created_at DESC
         LIMIT 100"
    );
    $prevPeriodStmt->execute([$votePeriod]);
    $prevRanks = [];
    foreach ($prevPeriodStmt->fetchAll() as $pr) {
        if (!isset($prevRanks[$pr['series_id']])) {
            $prevRanks[$pr['series_id']] = (int)$pr['rank_position'];
        }
    }

    // Sắp xếp theo votes giảm dần → tính rank
    usort($votesData, fn($a, $b) => (int)($b['reader_votes'] ?? 0) <=> (int)($a['reader_votes'] ?? 0));

    $db->beginTransaction();
    try {
        $insertStmt = $db->prepare(
            "INSERT INTO votes (series_id, vote_period, reader_votes, rank_position, entered_by)
             VALUES (?, ?, ?, ?, ?)"
        );

        // Lấy tổng số series đang xuất bản để xác định "ngưỡng nguy hiểm"
        $totalPublishing = count($votesData);
        $dangerThreshold = (int)ceil($totalPublishing * 0.75); // Top 75% → hạng dưới 25% là nguy hiểm

        $notifications = []; // [mangaka_id => message]

        foreach ($votesData as $rank => $v) {
            $seriesId   = (int)($v['series_id']   ?? 0);
            $readerVotes = (int)($v['reader_votes'] ?? 0);
            $rankPos    = $rank + 1;

            if ($seriesId <= 0) continue;

            $insertStmt->execute([$seriesId, $votePeriod, $readerVotes, $rankPos, $currentUser['id']]);

            // Kiểm tra tụt hạng nguy hiểm
            $prevRank = $prevRanks[$seriesId] ?? null;
            $isDanger = ($rankPos >= $dangerThreshold) && ($totalPublishing >= 3);
            $rankDropped = ($prevRank !== null) && ($rankPos > $prevRank);
            $bigDrop = ($prevRank !== null) && (($rankPos - $prevRank) >= 2);

            if ($isDanger || $bigDrop) {
                // Lấy mangaka_id của series
                $sStmt = $db->prepare(
                    "SELECT s.mangaka_id, s.title, u.username AS mangaka_name
                     FROM series s JOIN users u ON u.id = s.mangaka_id
                     WHERE s.id = ? LIMIT 1"
                );
                $sStmt->execute([$seriesId]);
                $sInfo = $sStmt->fetch();
                if ($sInfo) {
                    $dropInfo = $prevRank ? " (từ hạng {$prevRank} xuống hạng {$rankPos})" : "";
                    if ($isDanger && $bigDrop) {
                        $msg = "⚠️ CẢNH BÁO: Bộ truyện \"{$sInfo['title']}\" đang ở hạng {$rankPos}/{$totalPublishing}{$dropInfo} trong kỳ {$votePeriod}. Lượt bình chọn giảm mạnh, nguy cơ bị cắt xuất bản.";
                    } elseif ($bigDrop) {
                        $msg = "📉 Bộ truyện \"{$sInfo['title']}\" tụt hạng đáng kể{$dropInfo} trong kỳ {$votePeriod}. Cần cải thiện chất lượng nội dung.";
                    } else {
                        $msg = "⚠️ Bộ truyện \"{$sInfo['title']}\" đang ở vị trí nguy hiểm (hạng {$rankPos}/{$totalPublishing}) trong kỳ {$votePeriod}. Cần tăng cường nội dung hấp dẫn.";
                    }
                    $notifications[$sInfo['mangaka_id']] = $msg;
                }
            }
        }

        // Gửi notifications
        $notifStmt = $db->prepare(
            "INSERT INTO notifications (user_id, type, message, link)
             VALUES (?, 'rank_drop', ?, 'mangaka/dashboard.php')"
        );
        foreach ($notifications as $mangakaId => $msg) {
            $notifStmt->execute([$mangakaId, $msg]);
        }

        $db->commit();
        jsonOut(true, "Đã lưu kết quả bình chọn kỳ {$votePeriod} thành công!", [
            'period'    => $votePeriod,
            'count'     => count($votesData),
            'notif_sent' => count($notifications),
        ]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonOut(false, 'Lỗi hệ thống: ' . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════
   ACTION: cancel_series
   Huỷ series đang xuất bản, gửi notification cho mangaka
   ══════════════════════════════════════════════════════════ */
if ($action === 'cancel_series') {
    $seriesId = (int)($_POST['series_id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');

    if ($seriesId <= 0) jsonOut(false, 'ID series không hợp lệ.');

    $sStmt = $db->prepare(
        "SELECT s.id, s.title, s.status, s.mangaka_id, u.username AS mangaka_name
         FROM series s JOIN users u ON u.id = s.mangaka_id
         WHERE s.id = ? LIMIT 1"
    );
    $sStmt->execute([$seriesId]);
    $series = $sStmt->fetch();

    if (!$series) jsonOut(false, 'Không tìm thấy series.');
    if (!in_array($series['status'], ['publishing', 'approved', 'submitted'])) {
        jsonOut(false, 'Series không ở trạng thái có thể huỷ.');
    }

    $db->beginTransaction();
    try {
        // Cập nhật status
        $db->prepare("UPDATE series SET status = 'cancelled' WHERE id = ?")
           ->execute([$seriesId]);

        // Ghi log quyết định vào votes với period đặc biệt
        $logPeriod = date('Y') . '-CANCEL-' . date('md-His');
        $db->prepare(
            "INSERT INTO votes (series_id, vote_period, reader_votes, rank_position, entered_by)
             VALUES (?, ?, 0, NULL, ?)"
        )->execute([$seriesId, $logPeriod, $currentUser['id']]);

        // Notification cho mangaka
        $reasonStr = $reason ? " Lý do: {$reason}" : '';
        $msg = "🚫 Ban biên tập đã HUỶ XUẤT BẢN bộ truyện \"{$series['title']}\".{$reasonStr} Liên hệ biên tập viên để biết thêm thông tin.";
        $db->prepare(
            "INSERT INTO notifications (user_id, type, message, link)
             VALUES (?, 'series_cancelled', ?, 'mangaka/series.php')"
        )->execute([$series['mangaka_id'], $msg]);

        $db->commit();
        jsonOut(true, "Đã huỷ series \"{$series['title']}\" thành công.");
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonOut(false, 'Lỗi hệ thống: ' . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════
   ACTION: change_schedule
   Đổi lịch xuất bản weekly ↔ monthly
   ══════════════════════════════════════════════════════════ */
if ($action === 'change_schedule') {
    $seriesId    = (int)($_POST['series_id']    ?? 0);
    $newSchedule = trim($_POST['new_schedule']  ?? '');

    if ($seriesId <= 0) jsonOut(false, 'ID series không hợp lệ.');
    if (!in_array($newSchedule, ['weekly', 'monthly'])) {
        jsonOut(false, 'Lịch xuất bản không hợp lệ.');
    }

    $sStmt = $db->prepare(
        "SELECT s.id, s.title, s.status, s.publish_schedule, s.mangaka_id
         FROM series s WHERE s.id = ? LIMIT 1"
    );
    $sStmt->execute([$seriesId]);
    $series = $sStmt->fetch();

    if (!$series) jsonOut(false, 'Không tìm thấy series.');
    if ($series['status'] !== 'publishing') {
        jsonOut(false, 'Chỉ có thể đổi lịch series đang xuất bản.');
    }
    if ($series['publish_schedule'] === $newSchedule) {
        jsonOut(false, 'Lịch xuất bản đã là ' . $newSchedule . '.');
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE series SET publish_schedule = ? WHERE id = ?")
           ->execute([$newSchedule, $seriesId]);

        $oldLabel = $series['publish_schedule'] === 'weekly' ? 'hàng tuần' : 'hàng tháng';
        $newLabel = $newSchedule === 'weekly' ? 'hàng tuần' : 'hàng tháng';
        $msg = "📅 Ban biên tập đã ĐỔI LỊCH xuất bản bộ truyện \"{$series['title']}\" từ {$oldLabel} sang {$newLabel}.";

        $db->prepare(
            "INSERT INTO notifications (user_id, type, message, link)
             VALUES (?, 'schedule_changed', ?, 'mangaka/series.php')"
        )->execute([$series['mangaka_id'], $msg]);

        $db->commit();
        jsonOut(true, "Đã đổi lịch \"{$series['title']}\" sang {$newLabel}.", [
            'new_schedule' => $newSchedule,
            'series_id'    => $seriesId,
        ]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonOut(false, 'Lỗi hệ thống: ' . $e->getMessage());
    }
}

jsonOut(false, 'Hành động không hợp lệ.');
