<?php
/**
 * api/annotations.php
 *
 * GET  → Lấy annotations của một manuscript (hoặc một trang cụ thể)
 *         ?manuscript_id=<int>   Bắt buộc
 *         ?page_id=<int>         Tùy chọn, lọc theo trang
 *         ?status=open|resolved  Tùy chọn
 *
 * POST → Tạo / hành động
 *         action=create          (editor only)
 *         action=resolve         (editor only)
 *         action=submit_to_board (editor only) — Chuyển bản thảo lên BBT
 *
 * PUT  → Cập nhật nội dung annotation
 *         { annotation_id, comment?, status? }  (editor only)
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
function annOut(bool $ok, $data = null, string $msg = '', int $code = 0): void {
    if ($code > 0) http_response_code($code);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit();
}

function requireEditor(): void {
    global $currentUser;
    if ($currentUser['role'] !== ROLES['EDITOR']) {
        annOut(false, null, 'Chỉ Biên tập viên mới được thực hiện hành động này.', 403);
    }
}

// ═══════════════════════════════════════════════════════
// GET — Lấy annotations của manuscript/trang
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Cả editor và mangaka đều có thể xem (mangaka xem chú thích của mình)
    $manuscriptId = (int)($_GET['manuscript_id'] ?? 0);
    $pageId       = (int)($_GET['page_id']       ?? 0);
    $statusFilter = trim($_GET['status']          ?? ''); // 'open' | 'resolved'

    if ($manuscriptId <= 0) annOut(false, null, 'manuscript_id là bắt buộc.', 422);

    // Verify quyền đọc: editor hoặc mangaka sở hữu series
    if ($currentUser['role'] === ROLES['MANGAKA']) {
        $chkStmt = $db->prepare(
            "SELECT m.id FROM manuscripts m
             JOIN series s ON s.id = m.series_id
             WHERE m.id = ? AND s.mangaka_id = ? LIMIT 1"
        );
        $chkStmt->execute([$manuscriptId, $currentUser['id']]);
        if (!$chkStmt->fetch()) {
            annOut(false, null, 'Không có quyền xem annotations của bản thảo này.', 403);
        }
    }

    try {
        $where  = ['a.manuscript_id = ?'];
        $params = [$manuscriptId];

        if ($pageId > 0) {
            $where[]  = 'a.page_id = ?';
            $params[] = $pageId;
        }

        if (in_array($statusFilter, ['open', 'resolved'], true)) {
            $where[]  = 'a.status = ?';
            $params[] = $statusFilter;
        }

        $whereSQL = implode(' AND ', $where);

        $stmt = $db->prepare(
            "SELECT a.id, a.manuscript_id, a.page_id, a.x_pos, a.y_pos,
                    a.width, a.height, a.comment, a.status, a.created_at,
                    u.id AS editor_id, u.username AS editor_name,
                    p.page_number
             FROM annotations a
             JOIN users u ON u.id = a.editor_id
             LEFT JOIN pages p ON p.id = a.page_id
             WHERE {$whereSQL}
             ORDER BY a.created_at ASC"
        );
        $stmt->execute($params);
        $annotations = $stmt->fetchAll();

        // Stats
        $open     = count(array_filter($annotations, fn($a) => $a['status'] === 'open'));
        $resolved = count($annotations) - $open;

        annOut(true, [
            'annotations' => $annotations,
            'stats'       => ['total' => count($annotations), 'open' => $open, 'resolved' => $resolved],
        ], '');
    } catch (\Throwable $e) {
        annOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// PUT — Cập nhật annotation (comment hoặc status)
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireEditor();

    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $annotationId = (int)($body['annotation_id'] ?? 0);
    $comment      = trim($body['comment']         ?? '');
    $newStatus    = trim($body['status']           ?? '');

    if ($annotationId <= 0) annOut(false, null, 'annotation_id không hợp lệ.', 422);

    // Verify ownership (editor mới được sửa chú thích của mình)
    $stmt = $db->prepare("SELECT id, editor_id, status FROM annotations WHERE id = ? LIMIT 1");
    $stmt->execute([$annotationId]);
    $ann = $stmt->fetch();

    if (!$ann) annOut(false, null, 'Annotation không tồn tại.', 404);
    if ((int)$ann['editor_id'] !== $currentUser['id']) {
        annOut(false, null, 'Chỉ người tạo ghi chú mới được chỉnh sửa.', 403);
    }

    $setClauses = [];
    $setParams  = [];

    if ($comment !== '') {
        $setClauses[] = 'comment = ?';
        $setParams[]  = $comment;
    }
    if ($newStatus !== '' && in_array($newStatus, ['open', 'resolved'], true)) {
        $setClauses[] = 'status = ?';
        $setParams[]  = $newStatus;
    }

    if (empty($setClauses)) annOut(false, null, 'Không có trường nào để cập nhật.', 422);

    try {
        $setParams[] = $annotationId;
        $db->prepare("UPDATE annotations SET " . implode(', ', $setClauses) . " WHERE id = ?")
           ->execute($setParams);

        annOut(true, ['annotation_id' => $annotationId], 'Đã cập nhật ghi chú.');
    } catch (\Throwable $e) {
        annOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// POST — Tạo / hành động
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $body = $_POST;
    }

    $action = trim($body['action'] ?? '');

    // ── create ─────────────────────────────────────────
    if ($action === 'create') {
        requireEditor();

        $manuscriptId = (int)($body['manuscript_id'] ?? 0);
        $pageId       = (int)($body['page_id']       ?? 0);
        $xPos         = (float)($body['x_pos']       ?? 0);
        $yPos         = (float)($body['y_pos']       ?? 0);
        $width        = (float)($body['width']        ?? 0);
        $height       = (float)($body['height']       ?? 0);
        $comment      = trim($body['comment']         ?? '');

        if ($manuscriptId <= 0) annOut(false, null, 'manuscript_id không hợp lệ.', 422);
        if ($pageId <= 0)       annOut(false, null, 'page_id không hợp lệ.', 422);
        if (empty($comment))    annOut(false, null, 'Nội dung ghi chú không được để trống.', 422);
        if ($width < 0.5 || $height < 0.5) annOut(false, null, 'Vùng chọn quá nhỏ.', 422);

        // Clamp tọa độ [0, 100]
        $clamp  = fn($v, $max = 100.0) => max(0.0, min($max, (float)$v));
        $xPos   = $clamp($xPos);
        $yPos   = $clamp($yPos);
        $width  = $clamp($width);
        $height = $clamp($height);

        try {
            $stmt = $db->prepare(
                "INSERT INTO annotations (manuscript_id, page_id, editor_id, x_pos, y_pos, width, height, comment, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')"
            );
            $stmt->execute([
                $manuscriptId, $pageId, $currentUser['id'],
                $xPos, $yPos, $width, $height, $comment,
            ]);
            $newId = (int)$db->lastInsertId();

            // Cập nhật bản thảo thành 'reviewing' nếu đang pending
            $db->prepare("UPDATE manuscripts SET status = 'reviewing' WHERE id = ? AND status = 'pending'")
               ->execute([$manuscriptId]);

            annOut(true, [
                'annotation' => [
                    'id'      => $newId,
                    'x_pos'   => $xPos,   'y_pos'   => $yPos,
                    'width'   => $width,  'height'  => $height,
                    'comment' => $comment, 'status'  => 'open',
                    'editor_name' => $currentUser['username'],
                ],
            ], 'Đã tạo ghi chú thành công!');
        } catch (\Throwable $e) {
            annOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── resolve ────────────────────────────────────────
    if ($action === 'resolve') {
        requireEditor();

        $annotationId = (int)($body['annotation_id'] ?? 0);
        if ($annotationId <= 0) annOut(false, null, 'annotation_id không hợp lệ.', 422);

        try {
            // Chỉ editor sở hữu hoặc bất kỳ editor nào (theo nghiệp vụ — cho phép mở rộng)
            $stmt = $db->prepare("UPDATE annotations SET status = 'resolved' WHERE id = ?");
            $stmt->execute([$annotationId]);

            if ($stmt->rowCount() === 0) annOut(false, null, 'Không tìm thấy annotation.', 404);

            annOut(true, ['annotation_id' => $annotationId, 'status' => 'resolved'],
                'Đã đánh dấu giải quyết ghi chú.');
        } catch (\Throwable $e) {
            annOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── submit_to_board ────────────────────────────────
    if ($action === 'submit_to_board') {
        requireEditor();

        $manuscriptId = (int)($body['manuscript_id'] ?? 0);
        $boardNotes   = trim($body['board_notes']    ?? '');

        if ($manuscriptId <= 0) annOut(false, null, 'manuscript_id không hợp lệ.', 422);

        try {
            $stmt = $db->prepare(
                "SELECT m.id, m.series_id, m.chapter_id, m.submitted_by,
                        s.title AS series_title, s.mangaka_id
                 FROM manuscripts m
                 JOIN series s ON s.id = m.series_id
                 WHERE m.id = ? LIMIT 1"
            );
            $stmt->execute([$manuscriptId]);
            $manuscript = $stmt->fetch();

            if (!$manuscript) annOut(false, null, 'Không tìm thấy bản thảo.', 404);

            $db->beginTransaction();

            // 1. Cập nhật bản thảo → approved
            $db->prepare("UPDATE manuscripts SET status = 'approved' WHERE id = ?")
               ->execute([$manuscriptId]);

            // 2. Cập nhật chương → review
            if ($manuscript['chapter_id']) {
                $db->prepare("UPDATE chapters SET status = 'review' WHERE id = ?")
                   ->execute([$manuscript['chapter_id']]);
            }

            // 3. Tạo hoặc cập nhật submission
            $existStmt = $db->prepare("SELECT id FROM submissions WHERE manuscript_id = ?");
            $existStmt->execute([$manuscriptId]);
            $existSubId = $existStmt->fetchColumn();

            if ($existSubId) {
                $db->prepare(
                    "UPDATE submissions SET status='pending', board_notes=?, submitted_at=NOW() WHERE id=?"
                )->execute([$boardNotes, $existSubId]);
            } else {
                $db->prepare(
                    "INSERT INTO submissions (series_id, manuscript_id, submitted_by, status, board_notes, submitted_at)
                     VALUES (?, ?, ?, 'pending', ?, NOW())"
                )->execute([$manuscript['series_id'], $manuscriptId, $currentUser['id'], $boardNotes]);
            }

            // 4. Thông tin cho notification
            $chapterStr = '';
            if ($manuscript['chapter_id']) {
                $cStmt = $db->prepare("SELECT chapter_number FROM chapters WHERE id = ?");
                $cStmt->execute([$manuscript['chapter_id']]);
                $cn = $cStmt->fetchColumn();
                $chapterStr = $cn ? "Chương {$cn}" : '';
            }
            $title = $manuscript['series_title'];
            $cStr  = $chapterStr ?: 'Toàn bộ series';

            // 5. Notify mangaka
            $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'manuscript_decision', ?, 'mangaka/dashboard.php')"
            )->execute([
                $manuscript['submitted_by'],
                "Tin vui! Biên tập viên đã duyệt bản thảo ({$cStr}) của bộ truyện \"{$title}\" và chuyển tiếp lên Ban biên tập phê duyệt xuất bản.",
            ]);

            // 6. Notify all board members
            $boardStmt = $db->prepare("SELECT id FROM users WHERE role = ?");
            $boardUsers = [];
            if ($boardStmt->execute([ROLES['BOARD']])) {
                $boardUsers = $boardStmt->fetchAll(\PDO::FETCH_COLUMN);
            }
            $boardInsert = $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'manuscript_review', ?, 'board/voting.php')"
            );
            foreach ($boardUsers as $bid) {
                $boardInsert->execute([
                    $bid,
                    "Yêu cầu phê duyệt mới: \"{$title}\" - {$cStr} đang chờ bỏ phiếu.",
                ]);
            }

            $db->commit();
            annOut(true, ['submission_created' => true], 'Đã đệ trình lên Ban biên tập thành công!');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            annOut(false, null, 'Lỗi hệ thống: ' . $e->getMessage(), 500);
        }
    }

    // ── reject_manuscript ────────────────────────────────
    if ($action === 'reject_manuscript') {
        requireEditor();

        $manuscriptId = (int)($body['manuscript_id'] ?? 0);
        $rejectNotes  = trim($body['reject_notes']   ?? '');

        if ($manuscriptId <= 0) annOut(false, null, 'manuscript_id không hợp lệ.', 422);

        try {
            $stmt = $db->prepare(
                "SELECT m.id, m.series_id, m.chapter_id, m.submitted_by,
                        s.title AS series_title
                 FROM manuscripts m
                 JOIN series s ON s.id = m.series_id
                 WHERE m.id = ? LIMIT 1"
            );
            $stmt->execute([$manuscriptId]);
            $manuscript = $stmt->fetch();

            if (!$manuscript) annOut(false, null, 'Không tìm thấy bản thảo.', 404);

            $db->beginTransaction();

            // 1. Cập nhật bản thảo → rejected
            $db->prepare("UPDATE manuscripts SET status = 'rejected' WHERE id = ?")
               ->execute([$manuscriptId]);

            // 2. Cập nhật chương → rejected
            if ($manuscript['chapter_id']) {
                $db->prepare("UPDATE chapters SET status = 'rejected' WHERE id = ?")
                   ->execute([$manuscript['chapter_id']]);
            }

            // 4. Thông tin cho notification
            $chapterStr = '';
            if ($manuscript['chapter_id']) {
                $cStmt = $db->prepare("SELECT chapter_number FROM chapters WHERE id = ?");
                $cStmt->execute([$manuscript['chapter_id']]);
                $cn = $cStmt->fetchColumn();
                $chapterStr = $cn ? "Chương {$cn}" : '';
            }
            $title = $manuscript['series_title'];
            $cStr  = $chapterStr ?: 'Toàn bộ series';

            // 5. Notify mangaka
            $link = "mangaka/manuscripts.php?manuscript_id={$manuscriptId}";
            $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'manuscript_rejected', ?, ?)"
            )->execute([
                $manuscript['submitted_by'],
                "Biên tập viên đã TỪ CHỐI bản thảo ({$cStr}) của bộ truyện \"{$title}\". Lý do: {$rejectNotes}",
                $link
            ]);

            $db->commit();
            annOut(true, ['manuscript_rejected' => true], 'Đã từ chối bản thảo và gửi thông báo cho Họa sĩ!');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            annOut(false, null, 'Lỗi hệ thống: ' . $e->getMessage(), 500);
        }
    }

    annOut(false, null, 'Hành động không hợp lệ.', 400);
}

// ── Unsupported method ──
http_response_code(405);
echo json_encode(['success' => false, 'data' => null, 'message' => 'Phương thức không được hỗ trợ.']);
exit();
