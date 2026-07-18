<?php
/**
 * api/cron/check_overdue.php
 * Cron job để tự động kiểm tra và đánh dấu các nhiệm vụ quá hạn (overdue).
 * Gửi thông báo đến cả Họa sĩ (Mangaka) và Trợ lý (Assistant).
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

try {
    // 1. Tìm tất cả tasks đã quá hạn mà chưa được đánh dấu is_overdue và chưa được duyệt
    $stmt = $db->prepare("
        SELECT t.id, t.task_type, t.due_date, t.assigned_to, t.assigned_by,
               p.page_number, s.title as series_title, s.mangaka_id,
               u_ast.username as assistant_name
        FROM tasks t
        JOIN pages p ON t.page_id = p.id
        JOIN chapters c ON p.chapter_id = c.id
        JOIN series s ON c.series_id = s.id
        JOIN users u_ast ON t.assigned_to = u_ast.id
        WHERE t.due_date IS NOT NULL 
          AND t.due_date < CURDATE()
          AND t.status != 'approved'
          AND t.is_overdue = 0
    ");
    $stmt->execute();
    $overdueTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifiedCount = 0;

    if (count($overdueTasks) > 0) {
        $db->beginTransaction();
        
        $updateStmt = $db->prepare("UPDATE tasks SET is_overdue = 1 WHERE id = ?");
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
            VALUES (?, 'task_overdue', ?, ?, 0, NOW())
        ");

        foreach ($overdueTasks as $task) {
            $taskId = $task['id'];
            $taskType = $task['task_type'];
            $pageNo = $task['page_number'];
            $seriesTitle = $task['series_title'];
            
            // Đánh dấu task quá hạn
            $updateStmt->execute([$taskId]);

            // Gửi thông báo cho Assistant (assigned_to)
            $astMsg = "❌ Nhiệm vụ [{$taskType}] trên Trang {$pageNo} - {$seriesTitle} của bạn đã QUÁ HẠN chót!";
            $notifStmt->execute([$task['assigned_to'], $astMsg, '/assistant/tasks.php']);

            // Gửi thông báo cho Mangaka (mangaka_id hoặc assigned_by)
            $mgaMsg = "❌ Nhiệm vụ [{$taskType}] trên Trang {$pageNo} - {$seriesTitle} giao cho trợ lý {$task['assistant_name']} đã QUÁ HẠN chót!";
            $notifStmt->execute([$task['mangaka_id'], $mgaMsg, "/mangaka/tasks.php?page_id=" . $task['id']]);

            $notifiedCount++;
        }
        
        $db->commit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Kiểm tra hoàn thành.',
        'overdue_found' => count($overdueTasks),
        'notifications_sent' => $notifiedCount * 2
    ]);

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi hệ thống khi chạy cron: ' . $e->getMessage()
    ]);
}
