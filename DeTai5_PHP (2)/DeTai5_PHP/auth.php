<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/config.php';

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function label_vi(string $value): string {
    static $labels = [
        'Low' => 'Thấp',
        'Medium' => 'Trung bình',
        'High' => 'Cao',
        'Critical' => 'Nghiêm trọng',
        'Urgent' => 'Khẩn cấp',
        'Blocking' => 'Chặn phát hành',
        'Open' => 'Mới',
        'In Progress' => 'Đang xử lý',
        'Resolved' => 'Đã xử lý',
        'Closed' => 'Đã đóng',
        'Passed' => 'Đạt',
        'Failed' => 'Không đạt',
        'Blocked' => 'Bị chặn',
        'Draft' => 'Bản nháp',
        'Delayed' => 'Bị trễ',
        'Cancelled' => 'Đã hủy',
        'Released' => 'Đã phát hành',
        'Manager' => 'Quản lý',
        'Developer' => 'Nhà phát triển',
        'Tester' => 'Kiểm thử',
        'Release Manager' => 'Phụ trách phát hành',
    ];
    return $labels[$value] ?? $value;
}
function csrf_token(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';
}
function require_valid_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('Phiên biểu mẫu không hợp lệ hoặc đã hết hạn. Hãy tải lại trang và thử lại.');
    }
}
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $stmt = $GLOBALS['conn']->prepare('SELECT is_active FROM users WHERE id=?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || (int)$user['is_active'] !== 1) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?disabled=1');
        exit;
    }
}
function require_system_admin(): void {
    require_login();
    $userId = (int)$_SESSION['user_id'];
    $stmt = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE id=?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        exit('Chỉ quản trị viên hệ thống mới được phép thực hiện thao tác này.');
    }
}
function page_header(string $title): void {
    $name = e($_SESSION['user_name'] ?? $_SESSION['username'] ?? '');
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.e($title).'</title><link rel="stylesheet" href="style.css"><link rel="stylesheet" href="workflow.css"></head><body>';
    echo '<header class="topbar"><a class="brand" href="index.php">BugTrack</a><nav><a href="index.php">Tổng quan</a><a href="projects.php">Dự án</a><a href="issues.php">Công việc</a><a href="releases.php">Phát hành</a><a href="reports.php">Báo cáo</a>';
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        echo '<a href="user_admin.php">Quản trị</a>';
    }
    echo '</nav>';
    echo '<div class="user">Xin chào, '.$name.' · <form class="logout-form" method="post" action="logout.php">'.csrf_input().'<button type="submit">Đăng xuất</button></form></div></header><main class="container">';
    echo '<h1>'.e($title).'</h1>';
}
function page_footer(): void {
    echo '</main><footer>Đề tài 5 · Phát triển phần mềm mã nguồn mở</footer></body></html>';
}
