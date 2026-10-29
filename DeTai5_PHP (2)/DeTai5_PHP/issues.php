<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

$status = trim((string)($_GET['status'] ?? ''));
$projectId = trim((string)($_GET['project_id'] ?? ''));
$userId = (string)$_SESSION['user_id'];
$sql = "SELECT i.*,p.name AS project_name,a.name AS assignee_name,pm.role AS member_role,
               (SELECT GROUP_CONCAT(d.title ORDER BY d.id SEPARATOR ', ')
                FROM dependencies dep JOIN issues d ON d.id=dep.depends_on_issue_id
                WHERE dep.issue_id=i.id AND d.status NOT IN ('Resolved','Closed')) AS blocker_titles
        FROM issues i JOIN projects p ON p.id=i.project_id
        LEFT JOIN users a ON a.id=i.assignee_id
        JOIN project_members pm ON pm.project_id=i.project_id
        WHERE pm.user_id=? AND (?='' OR i.status=?) AND (?='' OR i.project_id=?)
        ORDER BY FIELD(i.priority,'Blocking','Urgent','High','Medium','Low'),i.updated_at DESC";
$statusFilter = in_array($status, ['Open','In Progress','Resolved','Closed'], true) ? $status : '';
$stmt = $conn->prepare($sql);
$stmt->bind_param('sssss', $userId, $statusFilter, $statusFilter, $projectId, $projectId);
$stmt->execute();
$rows = $stmt->get_result();
$projectsStmt = $conn->prepare(
    'SELECT p.id,p.name FROM projects p JOIN project_members pm ON pm.project_id=p.id WHERE pm.user_id=? ORDER BY p.name'
);
$projectsStmt->bind_param('s', $userId);
$projectsStmt->execute();
$projects = $projectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$canCreate = false;
foreach ($projects as $project) {
    if (project_role_allows(project_member_role($conn, (string)$project['id'], $userId), 'issue.create')) {
        $canCreate = true;
        break;
    }
}
page_header('Công việc và lỗi');
?>
<div class="panel-head"><form method="get" class="inline-form">
<label>Dự án<select name="project_id"><option value="">Tất cả dự án của tôi</option><?php foreach ($projects as $project): ?><option value="<?= e($project['id']) ?>" <?= $projectId === $project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select></label>
<label>Trạng thái<select name="status"><option value="">Tất cả</option><?php foreach (['Open','In Progress','Resolved','Closed'] as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(label_vi($value)) ?></option><?php endforeach; ?></select></label>
<button type="submit">Lọc</button></form><?php if ($canCreate): ?><a class="button" href="issue_form.php">+ Tạo công việc</a><?php endif; ?></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Mã</th><th>Dự án / công việc</th><th>Mức độ</th><th>Ưu tiên</th><th>Trạng thái</th><th>Người phụ trách</th><th>Tiến độ phụ thuộc</th><th>Thao tác</th></tr></thead><tbody>
<?php while ($r = $rows->fetch_assoc()): $canEditRow = project_role_allows((string)$r['member_role'], 'issue.edit'); $canDeleteRow = project_role_allows((string)$r['member_role'], 'issue.delete'); ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['project_name']) ?><strong class="sub"><?= e($r['title']) ?></strong><small class="sub"><?= e(mb_strimwidth($r['description'],0,100,'…','UTF-8')) ?></small></td><td><?= e(label_vi((string)$r['severity'])) ?></td><td><?= e(label_vi((string)$r['priority'])) ?></td><td><span class="badge"><?= e(label_vi((string)$r['status'])) ?></span></td><td><?= e($r['assignee_name'] ?: 'Chưa giao') ?></td><td><?= $r['blocker_titles'] ? '<span class="text-danger">Chặn bởi: ' . e($r['blocker_titles']) . '</span>' : 'Không bị chặn' ?></td><td class="actions"><a href="issue_form.php?id=<?= (int)$r['id'] ?>"><?= $canEditRow ? 'Chi tiết / sửa' : 'Chi tiết' ?></a><?php if ($canDeleteRow): ?><form method="post" action="issue_delete.php" onsubmit="return confirm('Bạn chắc chắn muốn xóa công việc này?')"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link-danger" type="submit">Xóa</button></form><?php endif; ?></td></tr><?php endwhile; ?>
</tbody></table></div></section>
<?php page_footer(); ?>
