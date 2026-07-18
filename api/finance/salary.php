<?php
/**
 * api/finance/salary.php
 * API tính và thanh toán lương cho trợ lý (Assistant) từ họa sĩ (Mangaka).
 * Chỉ cho phép Editor hoặc Mangaka gọi.
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth Check ──
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$allowedRoles = ['editor', 'mangaka'];
if (!in_array($currentUser['role'], $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Không có quyền truy cập chức năng này.']);
    exit();
}

$action = $_POST['action'] ?? '';
$db = getDB();

/**
 * Hàm lấy cấu hình tài chính từ finance_settings
 */
function getFinanceSetting($db, $key, $default) {
    try {
        if ($key === 'default_rate_per_page') {
            $stmt = $db->prepare("SELECT value_text FROM settings WHERE key_name = 'default_assistant_rate'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) return $val;
        }
        $stmt = $db->prepare("SELECT setting_value FROM finance_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

// ═══════════════════════════════════════════════════════════
// ACTION: calculate (Tính & Trả lương cho 1 Assistant)
// ═══════════════════════════════════════════════════════════
if ($action === 'calculate') {
    $assistant_id = (int)($_POST['assistant_id'] ?? 0);
    $mangaka_id    = (int)($_POST['mangaka_id'] ?? 0);
    $month         = (int)($_POST['month'] ?? 0);
    $year          = (int)($_POST['year'] ?? 0);
    
    // Bảo vệ: Nếu là mangaka, chỉ được tự trả lương cho trợ lý của mình
    if ($currentUser['role'] === 'mangaka' && $mangaka_id !== $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Không thể trả lương thay cho họa sĩ khác.']);
        exit();
    }

    if ($assistant_id <= 0 || $mangaka_id <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        echo json_encode(['success' => false, 'message' => 'Thông tin đầu vào không đầy đủ hoặc không hợp lệ.']);
        exit();
    }

    try {
        $tasksStmt = $db->prepare("
            SELECT t.id, t.price
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.assigned_to = :assistant_id
              AND s.mangaka_id = :mangaka_id
              AND t.status = 'approved'
              AND MONTH(COALESCE(t.approved_at, t.created_at)) = :month
              AND YEAR(COALESCE(t.approved_at, t.created_at)) = :year
              AND (t.salary_record_id IS NULL OR t.salary_record_id IN (
                  SELECT id FROM salary_records WHERE status = 'insufficient_funds' AND assistant_id = :assistant_id_sub AND mangaka_id = :mangaka_id_sub
              ))
        ");
        $tasksStmt->execute([
            ':assistant_id'     => $assistant_id,
            ':mangaka_id'       => $mangaka_id,
            ':month'            => $month,
            ':year'             => $year,
            ':assistant_id_sub' => $assistant_id,
            ':mangaka_id_sub'   => $mangaka_id
        ]);
        $eligibleTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
        $approved_tasks = count($eligibleTasks);
        $gross = 0.0;
        $taskIds = [];
        foreach ($eligibleTasks as $et) {
            $gross += (float)$et['price'];
            $taskIds[] = (int)$et['id'];
        }

        if ($gross <= 0 || empty($taskIds)) {
            echo json_encode(['success' => false, 'message' => 'Không có nhiệm vụ vẽ chưa chốt nào được phê duyệt hoàn thành trong thời gian này.']);
            exit();
        }

        $avg_rate = $approved_tasks > 0 ? $gross / $approved_tasks : 0;

        // Lấy tên các bên phục vụ thông báo
        $nameStmt = $db->prepare("SELECT id, username FROM users WHERE id IN (?, ?)");
        $nameStmt->execute([$assistant_id, $mangaka_id]);
        $users = $nameStmt->fetchAll(PDO::FETCH_UNIQUE);
        $assistantName = $users[$assistant_id]['username'] ?? 'Trợ lý';
        $mangakaName   = $users[$mangaka_id]['username'] ?? 'Họa sĩ';

        // 2. Lấy & kiểm tra ví của mangaka
        $mangakaWallet = get_wallet($mangaka_id);
        if (!$mangakaWallet) {
            // Tự động khởi tạo ví mangaka nếu chưa có
            $db->prepare("INSERT INTO wallets (user_id) VALUES (?)")->execute([$mangaka_id]);
            $mangakaWallet = get_wallet($mangaka_id);
        }

        if ((float)$mangakaWallet['balance'] < $gross) {
            // Xóa các bản ghi insufficient_funds cũ của cặp trợ lý-họa sĩ trong tháng/năm này
            $db->prepare("DELETE FROM salary_records WHERE assistant_id = ? AND mangaka_id = ? AND month = ? AND year = ? AND status = 'insufficient_funds'")
               ->execute([$assistant_id, $mangaka_id, $month, $year]);

            // Lưu trạng thái insufficient_funds vào salary_records mới
            $insS = $db->prepare("
                INSERT INTO salary_records (assistant_id, mangaka_id, month, year, approved_pages, rate_per_page, gross_amount, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'insufficient_funds')
            ");
            $insS->execute([$assistant_id, $mangaka_id, $month, $year, $approved_tasks, $avg_rate, $gross]);
            $recordId = (int)$db->lastInsertId();

            if ($recordId > 0 && !empty($taskIds)) {
                $inClause = implode(',', $taskIds);
                $db->prepare("UPDATE tasks SET salary_record_id = ? WHERE id IN ($inClause)")->execute([$recordId]);
            }

            // Gửi thông báo cho mangaka
            try {
                $insNotif = $db->prepare("
                    INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
                    VALUES (?, 'salary_insufficient', ?, ?, 0, NOW())
                ");
                $notifMsg = "⚠️ Số dư ví không đủ để thanh toán lương tháng $month/$year cho trợ lý $assistantName. Yêu cầu: " . format_money($gross) . " ₫, hiện có: " . format_money($mangakaWallet['balance']) . " ₫. Vui lòng nạp thêm tiền.";
                $insNotif->execute([$mangaka_id, $notifMsg, '/wallet/index.php']);
            } catch (\Throwable $e) {}

            echo json_encode([
                'success'   => false,
                'message'   => 'Ví của họa sĩ không đủ số dư để thanh toán lương.',
                'required'  => $gross,
                'available' => (float)$mangakaWallet['balance']
            ]);
            exit();
        }

        // 3. Thực hiện chuyển tiền (Đủ tiền)
        $db->beginTransaction();

        // Khóa ghi ví (FOR UPDATE) chống race condition
        $db->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE")->execute([$mangaka_id]);
        $db->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE")->execute([$assistant_id]);

        $mWallet = get_wallet($mangaka_id);
        $aWallet = get_wallet($assistant_id);
        if (!$aWallet) {
            $db->prepare("INSERT INTO wallets (user_id) VALUES (?)")->execute([$assistant_id]);
            $aWallet = get_wallet($assistant_id);
        }

        $mBalBefore = (float)$mWallet['balance'];
        $mBalAfter  = $mBalBefore - $gross;
        $aBalBefore = (float)$aWallet['balance'];
        $aBalAfter  = $aBalBefore + $gross;

        // Trừ ví mangaka
        $updM = $db->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?");
        $updM->execute([$gross, $mangaka_id]);

        // Ghi transaction cho mangaka (giá trị âm)
        $insMTx = $db->prepare("
            INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_type, status)
            VALUES (?, 'salary_pay', ?, ?, ?, ?, 'salary', 'completed')
        ");
        $mDesc = "Trả lương tháng $month/$year cho $assistantName ($approved_tasks nhiệm vụ, tổng cộng: " . format_money($gross) . " ₫)";
        $insMTx->execute([$mWallet['id'], -$gross, $mBalBefore, $mBalAfter, $mDesc]);

        // Cộng ví assistant
        $updA = $db->prepare("UPDATE wallets SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?");
        $updA->execute([$gross, $gross, $assistant_id]);

        // Ghi transaction cho assistant
        $insATx = $db->prepare("
            INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_type, status)
            VALUES (?, 'salary_receive', ?, ?, ?, ?, 'salary', 'completed')
        ");
        $aDesc = "Nhận lương tháng $month/$year từ họa sĩ $mangakaName ($approved_tasks nhiệm vụ, tổng cộng: " . format_money($gross) . " ₫)";
        $insATx->execute([$aWallet['id'], $gross, $aBalBefore, $aBalAfter, $aDesc]);

        // Xóa bất kỳ bản ghi insufficient_funds nào trước đó của cặp này trong tháng/năm này
        $db->prepare("DELETE FROM salary_records WHERE assistant_id = ? AND mangaka_id = ? AND month = ? AND year = ? AND status = 'insufficient_funds'")
           ->execute([$assistant_id, $mangaka_id, $month, $year]);

        // Lưu bản ghi salary_records mới thành 'paid'
        $insS = $db->prepare("
            INSERT INTO salary_records (assistant_id, mangaka_id, month, year, approved_pages, rate_per_page, gross_amount, status, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', NOW())
        ");
        $insS->execute([$assistant_id, $mangaka_id, $month, $year, $approved_tasks, $avg_rate, $gross]);
        $recordId = (int)$db->lastInsertId();

        // Cập nhật tasks.salary_record_id để liên kết với bản ghi này
        if ($recordId > 0 && !empty($taskIds)) {
            $inClause = implode(',', $taskIds);
            $db->prepare("UPDATE tasks SET salary_record_id = ? WHERE id IN ($inClause)")->execute([$recordId]);
        }

        $db->commit();

        // 4. Gửi Notifications cho cả 2
        try {
            $insNotif = $db->prepare("
                INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
                VALUES (?, 'salary_paid', ?, ?, 0, NOW())
            ");
            
            // Cho assistant
            $asMsg = "💰 Bạn vừa nhận được " . format_money($gross) . " ₫ tiền lương tháng $month/$year từ họa sĩ $mangakaName.";
            $insNotif->execute([$assistant_id, $asMsg, '/wallet/index.php']);

            // Cho mangaka
            $maMsg = "✅ Đã thanh toán thành công " . format_money($gross) . " ₫ lương tháng $month/$year cho trợ lý $assistantName.";
            $insNotif->execute([$mangaka_id, $maMsg, '/wallet/index.php']);
        } catch (\Throwable $e) {}

        echo json_encode([
            'success'        => true,
            'approved_pages' => $approved_tasks,
            'rate_per_page'  => $avg_rate,
            'gross_amount'   => $gross,
            'message'        => "Đã thanh toán lương cho trợ lý {$assistantName} thành công!"
        ]);

    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống khi thanh toán lương: ' . $e->getMessage()]);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════
// ACTION: calculate_all (Tính & Trả lương cho TẤT CẢ Assistant của Mangaka)
// ═══════════════════════════════════════════════════════════
if ($action === 'calculate_all') {
    $mangaka_id = (int)($_POST['mangaka_id'] ?? 0);
    $month      = (int)($_POST['month'] ?? 0);
    $year       = (int)($_POST['year'] ?? 0);

    // Bảo vệ: Nếu là mangaka, chỉ tự gọi trả lương cho các assistant của chính mình
    if ($currentUser['role'] === 'mangaka' && $mangaka_id !== $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Không thể trả lương thay cho họa sĩ khác.']);
        exit();
    }

    if ($mangaka_id <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        echo json_encode(['success' => false, 'message' => 'Thông tin đầu vào không đầy đủ hoặc không hợp lệ.']);
        exit();
    }

    try {
        // 1. Quét tìm tất cả Assistant có các nhiệm vụ chưa thanh toán vẽ cho mangaka này trong tháng chỉ định
        $asQuery = $db->prepare("
            SELECT DISTINCT t.assigned_to
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE s.mangaka_id = ?
              AND t.status = 'approved'
              AND MONTH(COALESCE(t.approved_at, t.created_at)) = ?
              AND YEAR(COALESCE(t.approved_at, t.created_at)) = ?
              AND (t.salary_record_id IS NULL OR t.salary_record_id IN (
                  SELECT id FROM salary_records WHERE status = 'insufficient_funds' AND mangaka_id = ?
              ))
        ");
        $asQuery->execute([$mangaka_id, $month, $year, $mangaka_id]);
        $assistants = $asQuery->fetchAll(PDO::FETCH_COLUMN);

        if (empty($assistants)) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy trợ lý nào có nhiệm vụ chưa thanh toán hoàn thành trong tháng này.']);
            exit();
        }

        $paidCount   = 0;
        $failedCount = 0;
        $totalPaid   = 0;
        $failedList  = [];

        // 2. Chạy thanh toán lương tuần tự cho từng Trợ lý
        foreach ($assistants as $asId) {
            $tasksStmt = $db->prepare("
                SELECT t.id, t.price
                FROM tasks t
                JOIN pages p ON t.page_id = p.id
                JOIN chapters c ON p.chapter_id = c.id
                JOIN series s ON c.series_id = s.id
                WHERE t.assigned_to = :as_id
                  AND s.mangaka_id = :ma_id
                  AND t.status = 'approved'
                  AND MONTH(COALESCE(t.approved_at, t.created_at)) = :month
                  AND YEAR(COALESCE(t.approved_at, t.created_at)) = :year
                  AND (t.salary_record_id IS NULL OR t.salary_record_id IN (
                      SELECT id FROM salary_records WHERE status = 'insufficient_funds' AND assistant_id = :as_id_sub AND mangaka_id = :ma_id_sub
                  ))
            ");
            $tasksStmt->execute([
                ':as_id'       => $asId,
                ':ma_id'       => $mangaka_id,
                ':month'       => $month,
                ':year'        => $year,
                ':as_id_sub'   => $asId,
                ':ma_id_sub'   => $mangaka_id
            ]);
            $eligibleTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
            $approved_tasks = count($eligibleTasks);
            $gross = 0.0;
            $taskIds = [];
            foreach ($eligibleTasks as $et) {
                $gross += (float)$et['price'];
                $taskIds[] = (int)$et['id'];
            }

            if ($gross <= 0 || empty($taskIds)) continue;

            $avg_rate = $approved_tasks > 0 ? $gross / $approved_tasks : 0;

            $asNameStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $asNameStmt->execute([$asId]);
            $assistantName = $asNameStmt->fetchColumn() ?: 'Trợ lý';

            // Kiểm tra ví mangaka trước khi trả
            $mangakaWallet = get_wallet($mangaka_id);
            if ((float)$mangakaWallet['balance'] < $gross) {
                // Xóa các bản ghi insufficient_funds cũ của cặp trợ lý-họa sĩ trong tháng/năm này
                $db->prepare("DELETE FROM salary_records WHERE assistant_id = ? AND mangaka_id = ? AND month = ? AND year = ? AND status = 'insufficient_funds'")
                   ->execute([$asId, $mangaka_id, $month, $year]);

                // Đánh dấu thiếu tiền
                $insS = $db->prepare("
                    INSERT INTO salary_records (assistant_id, mangaka_id, month, year, approved_pages, rate_per_page, gross_amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'insufficient_funds')
                ");
                $insS->execute([$asId, $mangaka_id, $month, $year, $approved_tasks, $avg_rate, $gross]);
                $recordId = (int)$db->lastInsertId();

                if ($recordId > 0 && !empty($taskIds)) {
                    $inClause = implode(',', $taskIds);
                    $db->prepare("UPDATE tasks SET salary_record_id = ? WHERE id IN ($inClause)")->execute([$recordId]);
                }
                
                $failedCount++;
                $failedList[] = $assistantName . " (Thiếu số dư)";
                continue;
            }

            // Thanh toán
            try {
                $db->beginTransaction();
                $db->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE")->execute([$mangaka_id]);
                $db->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE")->execute([$asId]);

                $mWallet = get_wallet($mangaka_id);
                $aWallet = get_wallet($asId);
                if (!$aWallet) {
                    $db->prepare("INSERT INTO wallets (user_id) VALUES (?)")->execute([$asId]);
                    $aWallet = get_wallet($asId);
                }

                $mBalBefore = (float)$mWallet['balance'];
                $mBalAfter  = $mBalBefore - $gross;
                $aBalBefore = (float)$aWallet['balance'];
                $aBalAfter  = $aBalBefore + $gross;

                // Trừ
                $db->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")->execute([$gross, $mangaka_id]);
                $db->prepare("
                    INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_type, status)
                    VALUES (?, 'salary_pay', ?, ?, ?, ?, 'salary', 'completed')
                ")->execute([
                    $mWallet['id'], -$gross, $mBalBefore, $mBalAfter,
                    "Trả lương tháng $month/$year cho $assistantName ($approved_tasks nhiệm vụ, tổng cộng: " . format_money($gross) . " ₫)"
                ]);

                // Cộng
                $db->prepare("UPDATE wallets SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?")->execute([$gross, $gross, $asId]);
                
                $maNameStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                $maNameStmt->execute([$mangaka_id]);
                $mangakaName = $maNameStmt->fetchColumn() ?: 'Họa sĩ';
                
                $db->prepare("
                    INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_type, status)
                    VALUES (?, 'salary_receive', ?, ?, ?, ?, 'salary', 'completed')
                ")->execute([
                    $aWallet['id'], $gross, $aBalBefore, $aBalAfter,
                    "Nhận lương tháng $month/$year từ họa sĩ $mangakaName ($approved_tasks nhiệm vụ, tổng cộng: " . format_money($gross) . " ₫)"
                ]);

                // Xóa bản ghi insufficient_funds cũ
                $db->prepare("DELETE FROM salary_records WHERE assistant_id = ? AND mangaka_id = ? AND month = ? AND year = ? AND status = 'insufficient_funds'")
                   ->execute([$asId, $mangaka_id, $month, $year]);

                // Bảng lương mới
                $insS = $db->prepare("
                    INSERT INTO salary_records (assistant_id, mangaka_id, month, year, approved_pages, rate_per_page, gross_amount, status, paid_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', NOW())
                ");
                $insS->execute([$asId, $mangaka_id, $month, $year, $approved_tasks, $avg_rate, $gross]);
                $recordId = (int)$db->lastInsertId();

                if ($recordId > 0 && !empty($taskIds)) {
                    $inClause = implode(',', $taskIds);
                    $db->prepare("UPDATE tasks SET salary_record_id = ? WHERE id IN ($inClause)")->execute([$recordId]);
                }

                $db->commit();

                // Gửi thông báo
                try {
                    $insN = $db->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) VALUES (?, 'salary_paid', ?, ?, 0, NOW())");
                    $insN->execute([$asId, "💰 Bạn vừa nhận được " . format_money($gross) . " ₫ lương tháng $month/$year từ họa sĩ $mangakaName.", '/wallet/index.php']);
                    $insN->execute([$mangaka_id, "✅ Đã trả lương " . format_money($gross) . " ₫ tháng $month/$year cho trợ lý $assistantName.", '/wallet/index.php']);
                } catch (\Throwable $e) {}

                $paidCount++;
                $totalPaid += $gross;
            } catch (\Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                $failedCount++;
                $failedList[] = $assistantName . " (Lỗi hệ thống)";
            }
        }

        echo json_encode([
            'success'    => true,
            'paid'       => $paidCount,
            'failed'     => $failedCount,
            'total_paid' => $totalPaid,
            'message'    => "Hoàn tất thanh toán lương: Đã trả {$paidCount} trợ lý, thất bại {$failedCount}." . (!empty($failedList) ? " Chi tiết thất bại: " . implode(', ', $failedList) : "")
        ]);

    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi xử lý: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Hành động không được hỗ trợ.']);
