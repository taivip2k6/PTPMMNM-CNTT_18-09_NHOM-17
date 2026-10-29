<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();
$stmt = $conn->prepare(
    'SELECT r.id,r.project_id,r.version_name AS version,r.name,r.planned_date,r.status,r.finalized_at,pm.role AS member_role,
            p.name AS project_name,u.name AS creator_name,
            (SELECT COUNT(*) FROM release_items ri WHERE ri.release_id=r.id) AS issue_count
     FROM releases r JOIN projects p ON p.id=r.project_id
     JOIN users u ON u.id=r.created_by
     JOIN project_members pm ON pm.project_id=r.project_id
     WHERE pm.user_id=? ORDER BY r.planned_date DESC,r.id DESC'
);
$stmt->bind_param('s', $_SESSION['user_id']);
$stmt->execute();
$rows = $stmt->get_result();
$canCreateStmt = $conn->prepare(
    "SELECT 1 FROM project_members WHERE user_id=? AND role IN ('Manager','Release Manager') LIMIT 1"
);
$canCreateStmt->bind_param('s', $_SESSION['user_id']);
$canCreateStmt->execute();
$canCreate = (bool)$canCreateStmt->get_result()->fetch_row();
page_header('Kế hoạch phát hành');
?>
<div class="panel-head"><p>Theo dõi phiên bản, kiểm tra điều kiện và chốt lịch sử phát hành theo dự án.</p><?php if ($canCreate): ?><a class="button" href="release_form.php">+ Tạo phiên bản</a><?php endif; ?></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Dự án</th><th>Phiên bản</th><th>Tên phát hành</th><th>Ngày dự kiến</th><th>Trạng thái</th><th>Công việc</th><th>Thao tác</th></tr></thead><tbody>
<?php while ($release = $rows->fetch_assoc()): $canManage = project_role_allows((string)$release['member_role'], 'release.manage'); ?><tr><td><?= e($release['project_name']) ?></td><td><strong><?= e($release['version']) ?></strong></td><td><?= e($release['name']) ?></td><td><?= e($release['planned_date']) ?></td><td><span class="badge"><?= e(label_vi((string)$release['status'])) ?><?= $release['finalized_at'] ? ' · Đã chốt' : '' ?></span></td><td><?= (int)$release['issue_count'] ?></td><td class="actions"><a href="release_view.php?id=<?= (int)$release['id'] ?>">Mở chi tiết</a><?php if (!$release['finalized_at'] && $canManage): ?><a href="release_form.php?id=<?= (int)$release['id'] ?>">Sửa</a><form method="post" action="release_delete.php" onsubmit="return confirm('Xóa kế hoạch phát hành này?')"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int)$release['id'] ?>"><button class="link-danger" type="submit">Xóa</button></form><?php elseif ($release['finalized_at']): ?><a href="release_notes.php?id=<?= (int)$release['id'] ?>">Ghi chú</a><?php endif; ?></td></tr><?php endwhile; ?>
</tbody></table></div></section>
<?php page_footer(); ?>
