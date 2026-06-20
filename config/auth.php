<?php
// Đảm bảo session được khởi động
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/constants.php';

/**
 * Kiểm tra xem người dùng đã đăng nhập chưa
 * @return bool
 */
function isLoggedIn()
{
    return isset($_SESSION['user']);
}

/**
 * Lấy thông tin user hiện tại đang đăng nhập
 * @return array|null
 */
function getCurrentUser()
{
    return $_SESSION['user'] ?? null;
}

/**
 * Yêu cầu người dùng phải có vai trò phù hợp, nếu không sẽ bị chuyển hướng
 * @param string|array $role Vai trò cần thiết
 */
function requireRole($role)
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit();
    }

    $user = getCurrentUser();
    $roles = is_array($role) ? $role : [$role];

    if (!in_array($user['role'], $roles)) {
        // Chuyển hướng người dùng về trang dashboard phù hợp với vai trò của họ
        switch ($user['role']) {
            case ROLES['MANGAKA']:
                header('Location: ' . BASE_URL . 'mangaka/dashboard.php');
                break;
            case ROLES['ASSISTANT']:
                header('Location: ' . BASE_URL . 'assistant/dashboard.php');
                break;
            case ROLES['EDITOR']:
                header('Location: ' . BASE_URL . 'editor/dashboard.php');
                break;
            case ROLES['BOARD']:
                header('Location: ' . BASE_URL . 'board/dashboard.php');
                break;
            default:
                header('Location: ' . BASE_URL . 'auth/login.php');
                break;
        }
        exit();
    }
}

/**
 * Kiểm tra xem người dùng hiện tại có quyền thực hiện hành động cụ thể không
 * @param string $action Hành động cần kiểm tra quyền
 * @return bool
 */
function hasPermission($action)
{
    if (!isLoggedIn()) {
        return false;
    }

    $user = getCurrentUser();
    $role = $user['role'] ?? '';

    // Bản đồ phân quyền tĩnh theo vai trò người dùng
    $permissions = [
        'mangaka' => [
            'create_series',
            'edit_series',
            'add_chapter',
            'assign_task',
            'submit_manuscript',
            'view_tasks'
        ],
        'assistant' => [
            'submit_task',
            'view_tasks',
            'view_earnings'
        ],
        'editor' => [
            'review_manuscript',
            'annotate_manuscript',
            'view_tasks'
        ],
        'board' => [
            'approve_submission',
            'publish_series',
            'vote_series'
        ]
    ];

    if (isset($permissions[$role])) {
        return in_array($action, $permissions[$role]);
    }

    return false;
}

/**
 * Chuyển hướng người dùng đến dashboard tương ứng với vai trò của họ.
 * @param string $role Vai trò của người dùng
 */
function redirectDashboard(string $role): void
{
    switch ($role) {
        case ROLES['MANGAKA']:
            header('Location: ' . BASE_URL . 'mangaka/dashboard.php');
            break;
        case ROLES['ASSISTANT']:
            header('Location: ' . BASE_URL . 'assistant/dashboard.php');
            break;
        case ROLES['EDITOR']:
            header('Location: ' . BASE_URL . 'editor/dashboard.php');
            break;
        case ROLES['BOARD']:
            header('Location: ' . BASE_URL . 'board/dashboard.php');
            break;
        default:
            header('Location: ' . BASE_URL . 'auth/login.php');
            break;
    }
    exit();
}
