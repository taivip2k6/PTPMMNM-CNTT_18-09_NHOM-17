<?php
require_once __DIR__ . '/workflow.php';
require_login();
$userId = (string)$_SESSION['user_id'];
$statsStmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            SUM(i.status IN ('Open','In Progress')) AS open_count,
            SUM(i.status IN ('Resolved','Closed')) AS fixed_count
     FROM issues i JOIN project_members pm ON pm.project_id=i.project_id WHERE pm.user_id=?"
);
$statsStmt->bind_param('s', $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$total = (int)$stats['total'];
$open = (int)$stats['open_count'];
$fixed = (int)$stats['fixed_count'];
$blockedStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT i.id) AS total FROM issues i
     JOIN project_members pm ON pm.project_id=i.project_id
     JOIN dependencies d ON d.issue_id=i.id
     JOIN issues blocker ON blocker.id=d.depends_on_issue_id
     WHERE pm.user_id=? AND i.status NOT IN ('Resolved','Closed')
       AND blocker.status NOT IN ('Resolved','Closed')"
);
$blockedStmt->bind_param('s', $userId);
$blockedStmt->execute();
$blocked = (int)$blockedStmt->get_result()->fetch_assoc()['total'];
$criticalStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM issues i
     JOIN project_members pm ON pm.project_id=i.project_id
     WHERE pm.user_id=? AND (i.severity='Critical' OR i.priority='Blocking')
       AND NOT EXISTS (
         SELECT 1 FROM test_results t
         WHERE t.issue_id=i.id AND t.result='Passed' AND t.created_at>=i.updated_at
       )"
);
$criticalStmt->bind_param('s', $userId);
$criticalStmt->execute();
$critical = (int)$criticalStmt->get_result()->fetch_assoc()['total'];
$releaseStmt = $conn->prepare(
    "SELECT COUNT(*) AS n FROM releases r
     JOIN project_members pm ON pm.project_id=r.project_id
     WHERE pm.user_id=? AND r.finalized_at IS NULL AND r.status<>'Cancelled'"
);
$releaseStmt->bind_param('s', $userId);
$releaseStmt->execute();
$releases = (int)$releaseStmt->get_result()->fetch_assoc()['n'];
$capabilityStmt = $conn->prepare('SELECT role FROM project_members WHERE user_id=?');
$capabilityStmt->bind_param('s', $userId);
$capabilityStmt->execute();
$canCreateIssue = false;
$canManageRelease = false;
foreach ($capabilityStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $membership) {
    $canCreateIssue = $canCreateIssue || project_role_allows((string)$membership['role'], 'issue.create');
    $canManageRelease = $canManageRelease || project_role_allows((string)$membership['role'], 'release.manage');
}
$projectStmt = $conn->prepare(
    "SELECT p.id,p.name,
            COUNT(i.id) AS total,
            SUM(i.status IN ('Open','In Progress')) AS active,
            SUM(i.status IN ('Resolved','Closed')) AS completed,
            SUM(i.severity='Critical' OR i.priority='Blocking') AS blocking
     FROM projects p
     JOIN project_members pm ON pm.project_id=p.id
     LEFT JOIN issues i ON i.project_id=p.id
     WHERE pm.user_id=?
     GROUP BY p.id,p.name
     ORDER BY p.name"
);
$projectStmt->bind_param('s', $userId);
$projectStmt->execute();
$projectRows = $projectStmt->get_result();
$upcomingStmt = $conn->prepare(
    "SELECT r.id,r.version_name,r.name,r.planned_date,r.status,p.name AS project_name,
            COUNT(ri.issue_id) AS issue_count
     FROM releases r
     JOIN projects p ON p.id=r.project_id
     JOIN project_members pm ON pm.project_id=r.project_id
     LEFT JOIN release_items ri ON ri.release_id=r.id
     WHERE pm.user_id=? AND r.finalized_at IS NULL AND r.status<>'Cancelled'
     GROUP BY r.id,r.version_name,r.name,r.planned_date,r.status,p.name
     ORDER BY r.planned_date ASC LIMIT 4"
);
$upcomingStmt->bind_param('s', $userId);
$upcomingStmt->execute();
$upcomingReleases = $upcomingStmt->get_result();
$recentStmt = $conn->prepare(
    "SELECT i.id,i.title,i.severity,i.status,p.name AS project_name,
            (SELECT r.version_name FROM release_items ri JOIN releases r ON r.id=ri.release_id
             WHERE ri.issue_id=i.id ORDER BY r.planned_date DESC LIMIT 1) AS version
     FROM issues i JOIN projects p ON p.id=i.project_id
     JOIN project_members pm ON pm.project_id=i.project_id
     WHERE pm.user_id=? ORDER BY i.updated_at DESC LIMIT 5"
);
$recentStmt->bind_param('s', $userId);
$recentStmt->execute();
$recent = $recentStmt->get_result();
page_header('Tổng quan hệ thống');
?>
<section class="dashboard-welcome">
<div><span class="eyebrow">BUGTRACK · WORKSPACE</span><h2>Xin chào, <?= e($_SESSION['user_name'] ?? $_SESSION['username'] ?? '') ?></h2><p>Theo dõi công việc đang chạy, điểm nghẽn và các phiên bản sắp phát hành.</p></div>
<div class="dashboard-actions"><?php if ($canCreateIssue): ?><a class="button" href="issue_form.php">+ Tạo công việc</a><?php endif; ?><?php if ($canManageRelease): ?><a class="button secondary" href="release_form.php">+ Lập phiên bản</a><?php endif; ?></div>
</section>
<section class="stats">
<a class="stat stat-link" href="issues.php"><span>Tổng công việc</span><strong><?= $total ?></strong><small>Trong các dự án bạn tham gia</small></a>
<a class="stat stat-link" href="issues.php?status=In+Progress"><span>Đang mở / xử lý</span><strong><?= $open ?></strong><small><?= $fixed ?> công việc đã hoàn tất</small></a>
<a class="stat stat-link stat-warning" href="issues.php"><span>Đang bị chặn</span><strong><?= $blocked ?></strong><small>Chờ công việc phụ thuộc hoàn tất</small></a>
<a class="stat stat-link <?= $critical > 0 ? 'stat-danger' : '' ?>" href="issues.php"><span>Lỗi mức chặn chưa kiểm thử</span><strong><?= $critical ?></strong><small><?= $releases ?> phiên bản đang chuẩn bị</small></a>
</section>
<div class="dashboard-grid">
<section class="panel"><div class="panel-head"><div><h2>Tiến độ dự án</h2><p>Khối lượng công việc trong từng workspace</p></div><a href="projects.php">Quản lý dự án →</a></div>
<div class="project-progress-list"><?php while ($project = $projectRows->fetch_assoc()): $projectTotal = (int)$project['total']; $completedCount = (int)$project['completed']; $progress = $projectTotal > 0 ? (int)round($completedCount * 100 / $projectTotal) : 0; ?><article class="project-progress"><div class="project-progress-heading"><div><strong><?= e($project['name']) ?></strong><small><?= $projectTotal ?> công việc · <?= (int)$project['active'] ?> đang mở</small></div><span><?= $progress ?>%</span></div><div class="progress-track"><span style="width:<?= $progress ?>%"></span></div><div class="project-progress-foot"><span><?= $completedCount ?> hoàn tất</span><span><?= (int)$project['blocking'] ?> mức chặn</span><a href="issues.php?project_id=<?= (int)$project['id'] ?>">Xem công việc</a></div></article><?php endwhile; ?></div>
</section>
<section class="panel"><div class="panel-head"><div><h2>Phiên bản sắp tới</h2><p>Kế hoạch chưa được chốt</p></div><a href="releases.php">Tất cả phiên bản →</a></div>
<div class="upcoming-list"><?php $hasUpcoming = false; while ($release = $upcomingReleases->fetch_assoc()): $hasUpcoming = true; ?><a class="upcoming-release" href="release_view.php?id=<?= (int)$release['id'] ?>"><span class="release-icon">↗</span><span class="release-info"><strong><?= e($release['version_name']) ?> · <?= e($release['name']) ?></strong><small><?= e($release['project_name']) ?> · <?= (int)$release['issue_count'] ?> công việc</small></span><span class="release-date"><?= e(date('d/m', strtotime($release['planned_date']))) ?></span></a><?php endwhile; if (!$hasUpcoming): ?><div class="empty-state"><strong>Chưa có phiên bản dự kiến</strong><span>Tạo kế hoạch để bắt đầu chuẩn bị phát hành.</span><a href="release_form.php">Tạo kế hoạch phát hành</a></div><?php endif; ?></div>
</section>
</div>
<section class="panel"><div class="panel-head"><div><h2>Hoạt động công việc gần đây</h2><p>Cập nhật mới nhất trong các dự án của bạn</p></div><a href="issues.php">Mở danh sách →</a></div>
<div class="table-wrap"><table><thead><tr><th>Mã</th><th>Dự án</th><th>Công việc</th><th>Mức độ</th><th>Trạng thái</th><th>Phiên bản</th></tr></thead><tbody>
<?php $hasRecent = false; while ($row = $recent->fetch_assoc()): $hasRecent = true; ?><tr><td>#<?= (int)$row['id'] ?></td><td><?= e($row['project_name']) ?></td><td><a class="issue-title" href="issue_form.php?id=<?= (int)$row['id'] ?>"><?= e($row['title']) ?></a></td><td><?= e(label_vi((string)$row['severity'])) ?></td><td><span class="badge"><?= e(label_vi((string)$row['status'])) ?></span></td><td><?= e($row['version'] ?? 'Chưa gán') ?></td></tr><?php endwhile; if (!$hasRecent): ?><tr><td colspan="6"><div class="empty-state"><strong>Chưa có công việc nào trong dự án của bạn</strong><a href="issue_form.php">Tạo công việc đầu tiên</a></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php page_footer(); ?>
