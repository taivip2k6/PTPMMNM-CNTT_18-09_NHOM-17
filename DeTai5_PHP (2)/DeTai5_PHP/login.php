<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = isset($_GET['disabled'])
    ? 'Tài khoản đã bị vô hiệu hóa. Hãy liên hệ quản trị viên hệ thống.'
    : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $conn->prepare(
        'SELECT id, username, name, `role`, password_hash, is_active FROM users
         WHERE username = ? LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user && (int)$user['is_active'] === 1 && is_string($user['password_hash'])
        && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        header('Location: index.php'); exit;
    }
    $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
}
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Đăng nhập BugTrack</title><link rel="stylesheet" href="style.css"></head>
<body class="login-page"><form class="login-card" method="post"><?= csrf_input() ?><h1>BugTrack</h1><p>Hệ thống theo dõi lỗi và kế hoạch phát hành</p>
<?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<label>Tên đăng nhập<input name="username" required autocomplete="username"></label>
<label>Mật khẩu<input type="password" name="password" required autocomplete="current-password"></label>
<button type="submit">Đăng nhập</button><small>Demo: u1 hoặc admin / Admin@123</small></form></body></html>
