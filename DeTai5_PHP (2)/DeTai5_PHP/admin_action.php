<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_system_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_valid_csrf();

$actorId = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');
$transactionStarted = false;

try {
    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'user');
        if (!preg_match('/\A[A-Za-z0-9._-]{3,100}\z/', $username)) {
            throw new RuntimeException('Tên đăng nhập phải dài 3–100 ký tự và chỉ gồm chữ Latin, số, dấu chấm, gạch dưới hoặc gạch ngang.');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Họ tên không được để trống và tối đa 120 ký tự.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Mật khẩu phải có ít nhất 10 ký tự.');
        }
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new RuntimeException('Quyền hệ thống không hợp lệ.');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'INSERT INTO users(username,name,password_hash,role,is_active) VALUES(?,?,?,?,1)'
        );
        $stmt->bind_param('ssss', $username, $name, $passwordHash, $role);
        $stmt->execute();
        $_SESSION['admin_message'] = 'Đã tạo tài khoản ' . $username . '.';
    } elseif ($action === 'update') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string)($_POST['role'] ?? '');
        $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || !in_array($role, ['user', 'admin'], true) || !in_array($active, [0, 1], true)) {
            throw new RuntimeException('Thông tin quyền hoặc trạng thái tài khoản không hợp lệ.');
        }
        if ($userId === $actorId && ($role !== 'admin' || $active !== 1)) {
            throw new RuntimeException('Bạn không thể tự hạ quyền hoặc vô hiệu hóa tài khoản đang đăng nhập.');
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $lockAdmins = $conn->query(
            "SELECT id FROM users WHERE role='admin' AND is_active=1 FOR UPDATE"
        );
        $activeAdmins = array_map(
            'intval',
            array_column($lockAdmins->fetch_all(MYSQLI_ASSOC), 'id')
        );
        $targetStmt = $conn->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        if (!$targetStmt->get_result()->fetch_row()) {
            throw new RuntimeException('Không tìm thấy tài khoản.');
        }
        if (in_array($userId, $activeAdmins, true)
            && ($role !== 'admin' || $active !== 1)
            && count($activeAdmins) <= 1) {
            throw new RuntimeException('Không thể hạ quyền hoặc vô hiệu hóa quản trị viên hệ thống cuối cùng.');
        }
        $stmt = $conn->prepare('UPDATE users SET role=?,is_active=? WHERE id=?');
        $stmt->bind_param('sii', $role, $active, $userId);
        $stmt->execute();
        $conn->commit();
        $transactionStarted = false;
        if ($userId === $actorId) {
            $_SESSION['user_role'] = $role;
        }
        $_SESSION['admin_message'] = 'Đã cập nhật quyền và trạng thái tài khoản.';
    } elseif ($action === 'reset_password') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string)($_POST['password'] ?? '');
        if (!$userId) {
            throw new RuntimeException('Mã tài khoản không hợp lệ.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Mật khẩu phải có ít nhất 10 ký tự.');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->bind_param('si', $passwordHash, $userId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Không tìm thấy tài khoản hoặc mật khẩu mới giống hệt mật khẩu hiện tại.');
        }
        $_SESSION['admin_message'] = 'Đã đặt lại mật khẩu. Hãy gửi mật khẩu mới cho người dùng bằng kênh an toàn.';
    } else {
        http_response_code(400);
        exit('Thao tác quản trị không hợp lệ.');
    }
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if ($exception->getCode() === 1062) {
        $_SESSION['admin_message'] = 'Tên đăng nhập này đã được sử dụng.';
    } else {
        throw $exception;
    }
} catch (RuntimeException $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    $_SESSION['admin_message'] = $exception->getMessage();
}

header('Location: user_admin.php');
exit;
