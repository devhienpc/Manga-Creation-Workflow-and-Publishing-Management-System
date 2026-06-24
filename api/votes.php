<?php
/**
 * api/votes.php
 *
 * GET  → Lấy lịch sử bình chọn của độc giả
 *         ?series_id=<int>    Lịch sử votes của một series
 *         ?period=<string>    Bảng xếp hạng của một kỳ cụ thể (vd: 2026-W24)
 *         ?limit=<int>        Số kỳ gần nhất (mặc định 10)
 *         ?summary=1          Trả về tổng hợp: top series, rank trend
 *
 * POST → Board nhập dữ liệu bình chọn kỳ mới
 *         action=submit_votes  (board only)
 *           period_type: week | month
 *           period_num : số kỳ (1-53 / 1-12)
 *           period_year: năm (YYYY)
 *           votes_data : JSON [{series_id, reader_votes}, ...]
 *
 * Response: { "success": bool, "data": {...}, "message": "..." }
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => null, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$db          = getDB();

// ── Helper ────────────────────────────────────────────
function voteOut(bool $ok, $data = null, string $msg = '', int $code = 0): void {
    if ($code > 0) http_response_code($code);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit();
}

// Lọc bỏ các vote_period nội bộ (board decisions, cancel logs)
function isReaderPeriod(string $period): bool {
    return !str_contains($period, '-board-')
        && !str_contains($period, '-CANCEL-');
}

// ═══════════════════════════════════════════════════════
// GET — Lịch sử & bảng xếp hạng bình chọn
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $seriesId = (int)($_GET['series_id'] ?? 0);
    $period   = trim($_GET['period']     ?? '');
    $limit    = min(52, max(1, (int)($_GET['limit'] ?? 10)));
    $summary  = !empty($_GET['summary']) && $_GET['summary'] === '1';

    try {
        // ── Lịch sử votes của một series ──
        if ($seriesId > 0) {
            $stmt = $db->prepare(
                "SELECT v.id, v.vote_period, v.reader_votes, v.rank_position, v.created_at,
                        s.title AS series_title
                 FROM votes v
                 JOIN series s ON s.id = v.series_id
                 WHERE v.series_id = ?
                   AND v.vote_period NOT LIKE '%-board-%'
                   AND v.vote_period NOT LIKE '%-CANCEL-%'
                 ORDER BY v.created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$seriesId, $limit]);
            $history = $stmt->fetchAll();

            // Tính trend: so sánh mỗi kỳ với kỳ liền trước
            for ($i = 0; $i < count($history); $i++) {
                $prev = $history[$i + 1] ?? null;
                if ($prev && $prev['rank_position'] !== null && $history[$i]['rank_position'] !== null) {
                    $diff = (int)$prev['rank_position'] - (int)$history[$i]['rank_position'];
                    $history[$i]['rank_change'] = $diff; // positive = improved
                } else {
                    $history[$i]['rank_change'] = null;
                }
            }

            voteOut(true, [
                'series_id' => $seriesId,
                'history'   => $history,
                'count'     => count($history),
            ], '');
        }

        // ── Bảng xếp hạng của một kỳ ──
        if ($period !== '') {
            if (!isReaderPeriod($period)) {
                voteOut(false, null, 'Kỳ không hợp lệ.', 422);
            }

            $stmt = $db->prepare(
                "SELECT v.series_id, v.reader_votes, v.rank_position,
                        s.title AS series_title, s.genre, s.publish_schedule, s.status AS series_status,
                        u.username AS mangaka_name
                 FROM votes v
                 JOIN series s ON s.id = v.series_id
                 JOIN users  u ON u.id = s.mangaka_id
                 WHERE v.vote_period = ?
                 ORDER BY v.rank_position ASC"
            );
            $stmt->execute([$period]);
            $ranking = $stmt->fetchAll();

            if (empty($ranking)) {
                voteOut(false, null, "Không có dữ liệu cho kỳ \"{$period}\".", 404);
            }

            $totalVotes = array_sum(array_column($ranking, 'reader_votes'));
            foreach ($ranking as &$r) {
                $r['vote_pct'] = $totalVotes > 0
                    ? round(($r['reader_votes'] / $totalVotes) * 100, 1)
                    : 0;
            }
            unset($r);

            voteOut(true, [
                'period'      => $period,
                'ranking'     => $ranking,
                'total_votes' => $totalVotes,
                'count'       => count($ranking),
            ], '');
        }

        // ── Summary: Top series + recent periods ──
        if ($summary) {
            // Top series (theo votes tổng cộng tất cả kỳ)
            $topStmt = $db->query(
                "SELECT v.series_id, s.title, s.publish_schedule,
                        SUM(v.reader_votes) AS total_votes,
                        COUNT(DISTINCT v.vote_period) AS periods_count,
                        MIN(v.rank_position) AS best_rank
                 FROM votes v
                 JOIN series s ON s.id = v.series_id
                 WHERE v.vote_period NOT LIKE '%-board-%'
                   AND v.vote_period NOT LIKE '%-CANCEL-%'
                   AND v.rank_position IS NOT NULL
                 GROUP BY v.series_id, s.title, s.publish_schedule
                 ORDER BY total_votes DESC
                 LIMIT 10"
            );
            $topSeries = $topStmt->fetchAll();

            // Danh sách các kỳ gần nhất
            $periodsStmt = $db->query(
                "SELECT vote_period, MAX(created_at) AS period_date,
                        COUNT(DISTINCT series_id) AS series_count
                 FROM votes
                 WHERE vote_period NOT LIKE '%-board-%'
                   AND vote_period NOT LIKE '%-CANCEL-%'
                 GROUP BY vote_period
                 ORDER BY period_date DESC
                 LIMIT 12"
            );
            $recentPeriods = $periodsStmt->fetchAll();

            // Tổng thống kê
            $statsStmt = $db->query(
                "SELECT COUNT(DISTINCT vote_period) AS total_periods,
                        COUNT(DISTINCT series_id)   AS total_series,
                        SUM(reader_votes)            AS total_votes,
                        MAX(reader_votes)            AS max_votes_single
                 FROM votes
                 WHERE vote_period NOT LIKE '%-board-%'
                   AND vote_period NOT LIKE '%-CANCEL-%'"
            );
            $stats = $statsStmt->fetch();

            voteOut(true, [
                'top_series'     => $topSeries,
                'recent_periods' => $recentPeriods,
                'stats'          => $stats,
            ], '');
        }

        // ── Không có filter — trả về tất cả kỳ gần nhất ──
        $periodsStmt = $db->prepare(
            "SELECT vote_period, MAX(created_at) AS period_date,
                    COUNT(DISTINCT series_id) AS series_count,
                    SUM(reader_votes) AS total_votes
             FROM votes
             WHERE vote_period NOT LIKE '%-board-%'
               AND vote_period NOT LIKE '%-CANCEL-%'
             GROUP BY vote_period
             ORDER BY period_date DESC
             LIMIT ?"
        );
        $periodsStmt->execute([$limit]);
        $periods = $periodsStmt->fetchAll();

        voteOut(true, ['periods' => $periods, 'count' => count($periods)], '');

    } catch (\Throwable $e) {
        voteOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// POST — Board nhập dữ liệu bình chọn kỳ mới
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Chỉ Board mới được nhập dữ liệu bình chọn
    if ($currentUser['role'] !== ROLES['BOARD']) {
        voteOut(false, null, 'Chỉ Ban biên tập mới được nhập dữ liệu bình chọn.', 403);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $body = $_POST;
    }

    $action = trim($body['action'] ?? '');

    // ── submit_votes ────────────────────────────────────
    if ($action === 'submit_votes') {
        $periodType = trim($body['period_type'] ?? ''); // 'week' | 'month'
        $periodNum  = (int)($body['period_num']  ?? 0);
        $periodYear = (int)($body['period_year'] ?? date('Y'));
        $votesJson  = trim($body['votes_data']   ?? '');

        // Validate inputs
        if (!in_array($periodType, ['week', 'month'])) {
            voteOut(false, null, 'period_type phải là "week" hoặc "month".', 422);
        }
        if ($periodType === 'week' && ($periodNum < 1 || $periodNum > 53)) {
            voteOut(false, null, 'Số tuần phải từ 1 đến 53.', 422);
        }
        if ($periodType === 'month' && ($periodNum < 1 || $periodNum > 12)) {
            voteOut(false, null, 'Số tháng phải từ 1 đến 12.', 422);
        }
        if ($periodYear < 2020 || $periodYear > 2100) {
            voteOut(false, null, 'Năm không hợp lệ (2020–2100).', 422);
        }

        $votesData = is_array($body['votes_data'] ?? null)
            ? $body['votes_data']
            : json_decode($votesJson, true);

        if (!is_array($votesData) || empty($votesData)) {
            voteOut(false, null, 'votes_data trống hoặc không đúng định dạng JSON.', 422);
        }

        // Validate mỗi phần tử
        foreach ($votesData as $idx => $v) {
            if (empty($v['series_id']) || !is_numeric($v['series_id'])) {
                voteOut(false, null, "Mục #{$idx}: series_id không hợp lệ.", 422);
            }
            if (!isset($v['reader_votes']) || !is_numeric($v['reader_votes']) || (int)$v['reader_votes'] < 0) {
                voteOut(false, null, "Mục #{$idx}: reader_votes phải là số nguyên không âm.", 422);
            }
        }

        // Tạo vote_period string
        $votePeriod = $periodType === 'week'
            ? sprintf('%d-W%02d', $periodYear, $periodNum)
            : sprintf('%d-M%02d', $periodYear, $periodNum);

        // Kiểm tra trùng kỳ
        $seriesIds  = array_map(fn($v) => (int)$v['series_id'], $votesData);
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $dupStmt = $db->prepare(
            "SELECT COUNT(*) FROM votes WHERE vote_period = ? AND series_id IN ({$placeholders})"
        );
        $dupStmt->execute(array_merge([$votePeriod], $seriesIds));
        if ((int)$dupStmt->fetchColumn() > 0) {
            voteOut(false, null, "Kỳ \"{$votePeriod}\" đã có dữ liệu bình chọn cho một số series. Không thể nhập trùng.", 409);
        }

        // Lấy rank kỳ liền trước (để phát hiện tụt hạng)
        $prevRanks = [];
        $prevStmt  = $db->prepare(
            "SELECT series_id, rank_position FROM votes
             WHERE vote_period != ?
               AND vote_period NOT LIKE '%-board-%'
               AND vote_period NOT LIKE '%-CANCEL-%'
             ORDER BY created_at DESC
             LIMIT 200"
        );
        $prevStmt->execute([$votePeriod]);
        foreach ($prevStmt->fetchAll() as $pr) {
            if (!isset($prevRanks[$pr['series_id']]) && $pr['rank_position'] !== null) {
                $prevRanks[$pr['series_id']] = (int)$pr['rank_position'];
            }
        }

        // Sắp xếp giảm dần theo votes → tính rank
        usort($votesData, fn($a, $b) => (int)$b['reader_votes'] <=> (int)$a['reader_votes']);

        $db->beginTransaction();
        try {
            $insertStmt = $db->prepare(
                "INSERT INTO votes (series_id, vote_period, reader_votes, rank_position, entered_by)
                 VALUES (?, ?, ?, ?, ?)"
            );

            $total           = count($votesData);
            $dangerThreshold = $total >= 3 ? (int)ceil($total * 0.75) : PHP_INT_MAX;
            $notifications   = []; // [mangaka_id => message]

            foreach ($votesData as $rankIdx => $v) {
                $sid     = (int)$v['series_id'];
                $votes   = (int)$v['reader_votes'];
                $rankPos = $rankIdx + 1;

                if ($sid <= 0) continue;

                $insertStmt->execute([$sid, $votePeriod, $votes, $rankPos, $currentUser['id']]);

                // Phát hiện tụt hạng nguy hiểm
                $prevRank = $prevRanks[$sid] ?? null;
                $isDanger = ($rankPos >= $dangerThreshold);
                $bigDrop  = $prevRank !== null && ($rankPos - $prevRank) >= 2;

                if ($isDanger || $bigDrop) {
                    $sStmt = $db->prepare(
                        "SELECT s.mangaka_id, s.title FROM series s WHERE s.id = ? LIMIT 1"
                    );
                    $sStmt->execute([$sid]);
                    $sInfo = $sStmt->fetch();
                    if ($sInfo && !isset($notifications[$sInfo['mangaka_id']])) {
                        $dropStr = $prevRank ? " (từ hạng {$prevRank} xuống hạng {$rankPos})" : '';
                        if ($isDanger && $bigDrop) {
                            $msg = "⚠️ CẢNH BÁO: \"{$sInfo['title']}\" đang ở hạng {$rankPos}/{$total}{$dropStr} kỳ {$votePeriod}. Lượt bình chọn giảm mạnh — nguy cơ cắt xuất bản.";
                        } elseif ($bigDrop) {
                            $msg = "📉 \"{$sInfo['title']}\" tụt hạng đáng kể{$dropStr} trong kỳ {$votePeriod}. Cần cải thiện nội dung.";
                        } else {
                            $msg = "⚠️ \"{$sInfo['title']}\" đang ở vị trí nguy hiểm (hạng {$rankPos}/{$total}) kỳ {$votePeriod}.";
                        }
                        $notifications[$sInfo['mangaka_id']] = $msg;
                    }
                }
            }

            // Gửi notifications rank_drop
            $notifStmt = $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'rank_drop', ?, 'mangaka/dashboard.php')"
            );
            foreach ($notifications as $mid => $msg) {
                $notifStmt->execute([$mid, $msg]);
            }

            $db->commit();

            voteOut(true, [
                'period'      => $votePeriod,
                'count'       => $total,
                'notif_sent'  => count($notifications),
                'danger_list' => array_keys($notifications),
            ], "Đã lưu kết quả bình chọn kỳ {$votePeriod} thành công!");
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            voteOut(false, null, 'Lỗi hệ thống: ' . $e->getMessage(), 500);
        }
    }

    voteOut(false, null, 'Hành động không hợp lệ.', 400);
}

// ── Unsupported method ──
http_response_code(405);
echo json_encode(['success' => false, 'data' => null, 'message' => 'Phương thức không được hỗ trợ.']);
exit();
