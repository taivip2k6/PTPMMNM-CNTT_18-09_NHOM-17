<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
$userId = (string)$_SESSION['user_id'];
$projectStmt = $conn->prepare(
    'SELECT p.id,p.name,p.manager_id,m.name AS manager_name,
            (SELECT COUNT(*) FROM issues i WHERE i.project_id=p.id) AS issue_count,
            EXISTS(SELECT 1 FROM project_members pm
                   WHERE pm.project_id=p.id AND pm.user_id=?) AS is_member,
            EXISTS(SELECT 1 FROM releases r
                   WHERE r.project_id=p.id AND r.finalized_at IS NOT NULL) AS has_finalized_release
     FROM projects p LEFT JOIN users m ON m.id=p.manager_id
     ORDER BY p.name'
);
$projectStmt->bind_param('s', $userId);
$projectStmt->execute();
$projects = $projectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$users = $conn->query('SELECT id,name,role FROM users ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$message = (string)($_SESSION['project_message'] ?? '');
unset($_SESSION['project_message']);
page_header('Dự án và thành viên');
?>
<?php if ($message): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
<section class="panel form-panel"><h2>Tạo dự án</h2><form method="post" action="project_action.php" class="inline-form"><?= csrf_input() ?><input type="hidden" name="action" value="create"><label>Tên dự án<input name="name" required maxlength="160"></label><button type="submit">Tạo dự án</button></form></section>
<?php if (!$projects): ?><section class="panel"><p>Chưa có dự án nào trong hệ thống.</p></section><?php endif; ?>
<?php foreach ($projects as $project): ?>
<?php $isManager = (string)$project['manager_id'] === $userId; $isMember = (bool)$project['is_member']; ?>
<section class="panel"><div class="panel-head"><div><h2><?= e($project['name']) ?> <small>(#<?= (int)$project['id'] ?>)</small></h2><p>Quản lý: <?= e($project['manager_name'] ?: 'Chưa phân công') ?> · <?= (int)$project['issue_count'] ?> công việc · <?= $isMember ? 'Bạn là thành viên' : 'Bạn chưa tham gia' ?></p></div>
<?php if ($isMember): ?><a class="button secondary" href="issues.php?project_id=<?= (int)$project['id'] ?>">Mở công việc</a><?php endif; ?></div>
<?php if ($isMember): ?>
<h3>Thành viên dự án</h3>
<?php $membersStmt = $conn->prepare('SELECT u.id,u.name,pm.role FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name'); $membersStmt->bind_param('i',$project['id']); $membersStmt->execute(); $members = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC); ?>
<ul class="member-list"><?php foreach ($members as $member): ?><li><span><?= e($member['name']) ?> <small><?= e(label_vi((string)$member['role'])) ?> · <?= e($member['id']) ?></small></span><?php if ($isManager && (string)$member['id'] !== $userId): ?><form method="post" action="project_action.php" onsubmit="return confirm('Gỡ thành viên này khỏi dự án?')"><?= csrf_input() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><input type="hidden" name="user_id" value="<?= e($member['id']) ?>"><button class="link-danger" type="submit">Gỡ</button></form><?php endif; ?></li><?php endforeach; ?></ul>
<?php if ($isManager): ?><form method="post" action="project_action.php" class="inline-form"><?= csrf_input() ?><input type="hidden" name="action" value="add_member"><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><label>Thêm thành viên<select name="user_id" required><option value="">Chọn người dùng</option><?php foreach ($users as $user): ?><option value="<?= e($user['id']) ?>"><?= e($user['name']) ?> (<?= e($user['id']) ?>)</option><?php endforeach; ?></select></label><label>Vai trò<select name="member_role"><?php foreach (['Developer','Tester','Release Manager'] as $role): ?><option value="<?= e($role) ?>"><?= e(label_vi($role)) ?></option><?php endforeach; ?></select></label><button type="submit">Thêm thành viên</button></form><?php endif; ?>
<?php else: ?><p>Đây là dự án hiện có. Bạn chưa tham gia nên không thể xem hoặc chỉnh sửa công việc.</p><?php endif; ?>
<?php if ($isManager): ?><div class="actions project-actions"><?php if ((bool)$project['has_finalized_release']): ?><span class="text-danger">Không thể xóa dự án vì đã có phiên bản phát hành được chốt.</span><?php else: ?><form method="post" action="project_action.php" onsubmit="return confirm('Xóa dự án này cùng toàn bộ công việc, bình luận, kiểm thử và phiên bản chưa chốt? Thao tác này không thể hoàn tác.')"><?= csrf_input() ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><button class="link-danger" type="submit">Xóa dự án</button></form><?php endif; ?></div><?php endif; ?>
</section>
<?php endforeach; ?>
<?php page_footer(); ?>
