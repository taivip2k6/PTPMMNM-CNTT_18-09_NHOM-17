<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_system_admin();

$message = (string)($_SESSION['admin_message'] ?? '');
unset($_SESSION['admin_message']);
$users = $conn->query(
    "SELECT u.id,u.username,u.name,u.role,u.is_active,
            (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id=u.id) AS project_count
     FROM users u ORDER BY u.id"
)->fetch_all(MYSQLI_ASSOC);
page_header('Quản trị tài khoản');
?>
<?php if ($message): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
<section class="panel">
<h2>Tạo tài khoản</h2>
<p>Tài khoản mới không được tự động thêm vào dự án. Quản lý dự án cần phân công thành viên trong trang Dự án.</p>
<form method="post" action="admin_action.php" class="form-grid">
<?= csrf_input() ?><input type="hidden" name="action" value="create">
<label>Tên đăng nhập<input name="username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+" autocomplete="off"></label>
<label>Họ tên<input name="name" required maxlength="120"></label>
<label>Mật khẩu tạm thời<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
<label>Quyền hệ thống<select name="role"><option value="user">Người dùng</option><option value="admin">Quản trị viên</option></select></label>
<div class="wide"><button type="submit">Tạo tài khoản</button></div>
</form>
</section>
<section class="panel">
<h2>Tài khoản hiện có</h2>
<div class="table-wrap"><table><thead><tr><th>Tài khoản</th><th>Quyền hệ thống</th><th>Dự án</th><th>Trạng thái</th><th>Cập nhật quyền</th><th>Đặt lại mật khẩu</th></tr></thead><tbody>
<?php foreach ($users as $user): ?>
<tr>
<td><strong><?= e($user['name']) ?></strong><small class="sub"><?= e($user['username']) ?> · #<?= (int)$user['id'] ?></small></td>
<td><?= $user['role'] === 'admin' ? 'Quản trị viên hệ thống' : 'Người dùng' ?></td>
<td><?= (int)$user['project_count'] ?></td>
<td><span class="badge"><?= (int)$user['is_active'] === 1 ? 'Đang hoạt động' : 'Đã vô hiệu hóa' ?></span></td>
<td>
<form method="post" action="admin_action.php" class="admin-inline-form">
<?= csrf_input() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
<select name="role" aria-label="Quyền hệ thống của <?= e($user['username']) ?>"><option value="user" <?= $user['role'] !== 'admin' ? 'selected' : '' ?>>Người dùng</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Quản trị viên</option></select>
<select name="is_active" aria-label="Trạng thái <?= e($user['username']) ?>"><option value="1" <?= (int)$user['is_active'] === 1 ? 'selected' : '' ?>>Hoạt động</option><option value="0" <?= (int)$user['is_active'] !== 1 ? 'selected' : '' ?>>Vô hiệu hóa</option></select>
<button type="submit">Lưu</button>
</form>
</td>
<td>
<form method="post" action="admin_action.php" class="admin-inline-form">
<?= csrf_input() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
<input type="password" name="password" required minlength="10" placeholder="Mật khẩu mới" aria-label="Mật khẩu mới cho <?= e($user['username']) ?>" autocomplete="new-password">
<button type="submit">Đặt lại</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<?php page_footer(); ?>
