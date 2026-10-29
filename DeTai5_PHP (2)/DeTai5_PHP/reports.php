<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

function report_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function report_csv_value(mixed $value): string
{
    $text = (string)$value;
    if ($text !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

$userId = (string)$_SESSION['user_id'];
$projectsStmt = $conn->prepare(
    'SELECT p.id,p.name FROM projects p
     JOIN project_members pm ON pm.project_id=p.id
     WHERE pm.user_id=? ORDER BY p.name'
);
$projectsStmt->bind_param('s', $userId);
$projectsStmt->execute();
$projects = $projectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$projectParam = $_GET['project_id'] ?? '';
$fromParam = $_GET['from'] ?? date('Y-m-01');
$toParam = $_GET['to'] ?? date('Y-m-d');
if (!is_string($projectParam) || !is_string($fromParam) || !is_string($toParam)) {
    http_response_code(400);
    exit('Tham số báo cáo không hợp lệ.');
}
$projectId = trim($projectParam);
$from = trim($fromParam);
$to = trim($toParam);
if (!report_date($from) || !report_date($to) || $from > $to) {
    http_response_code(400);
    exit('Khoảng thời gian không hợp lệ.');
}
if ($projectId !== '') {
    $allowedProjectIds = array_map(
        static fn(array $project): string => (string)$project['id'],
        $projects
    );
    if (!ctype_digit($projectId) || !in_array($projectId, $allowedProjectIds, true)) {
        http_response_code(403);
        exit('Bạn không có quyền xem báo cáo của dự án này.');
    }
}
$endExclusive = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
$projectCondition = $projectId === '' ? '' : ' AND i.project_id=?';
$types = $projectId === '' ? 'sss' : 'sssi';
$params = $projectId === ''
    ? [$userId, $from, $endExclusive]
    : [$userId, $from, $endExclusive, (int)$projectId];
$bind = static function (mysqli_stmt $stmt) use (&$params, $types): void {
    $references = [];
    foreach ($params as $key => &$value) {
        $references[$key] = &$value;
    }
    $stmt->bind_param($types, ...$references);
};

$summaryStmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(i.status IN ('Resolved','Closed')),0) AS completed,
            COALESCE(SUM(i.status IN ('Open','In Progress')),0) AS active,
            COALESCE(SUM(i.severity='Critical' OR i.priority='Blocking'),0) AS critical
     FROM issues i JOIN project_members pm ON pm.project_id=i.project_id
     WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition"
);
$bind($summaryStmt);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$total = (int)$summary['total'];
$completionPercent = $total > 0 ? (int)round((int)$summary['completed'] * 100 / $total) : 0;

$completedStmt = $conn->prepare(
    "SELECT COUNT(*) FROM issues i
     JOIN project_members pm ON pm.project_id=i.project_id
     WHERE pm.user_id=? AND i.status IN ('Resolved','Closed')
       AND i.updated_at>=? AND i.updated_at<?$projectCondition"
);
$bind($completedStmt);
$completedStmt->execute();
$completedInPeriod = (int)$completedStmt->get_result()->fetch_row()[0];

$blockedStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT i.id) FROM issues i
     JOIN project_members pm ON pm.project_id=i.project_id
     JOIN dependencies d ON d.issue_id=i.id
     JOIN issues blocker ON blocker.id=d.depends_on_issue_id AND blocker.project_id=i.project_id
     WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
       AND i.status NOT IN ('Resolved','Closed')
       AND blocker.status NOT IN ('Resolved','Closed')"
);
$bind($blockedStmt);
$blockedStmt->execute();
$blocked = (int)$blockedStmt->get_result()->fetch_row()[0];

$groupedQueries = [
    'status' => [
        'title' => 'Theo trạng thái',
        'sql' => "SELECT i.status AS label,COUNT(*) AS total
                  FROM issues i JOIN project_members pm ON pm.project_id=i.project_id
                  WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
                  GROUP BY i.status ORDER BY total DESC",
    ],
    'severity' => [
        'title' => 'Theo mức độ',
        'sql' => "SELECT i.severity AS label,COUNT(*) AS total
                  FROM issues i JOIN project_members pm ON pm.project_id=i.project_id
                  WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
                  GROUP BY i.severity ORDER BY total DESC",
    ],
    'priority' => [
        'title' => 'Theo ưu tiên',
        'sql' => "SELECT i.priority AS label,COUNT(*) AS total
                  FROM issues i JOIN project_members pm ON pm.project_id=i.project_id
                  WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
                  GROUP BY i.priority ORDER BY total DESC",
    ],
];
$grouped = [];
foreach ($groupedQueries as $key => $query) {
    $stmt = $conn->prepare($query['sql']);
    $bind($stmt);
    $stmt->execute();
    $grouped[$key] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$assigneeStmt = $conn->prepare(
    "SELECT COALESCE(u.name,'Chưa giao') AS assignee_name,
            COUNT(*) AS total,
            SUM(i.status IN ('Resolved','Closed')) AS completed,
            SUM(i.status IN ('Open','In Progress')) AS active
     FROM issues i
     JOIN project_members pm ON pm.project_id=i.project_id
     LEFT JOIN users u ON u.id=i.assignee_id
     WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
     GROUP BY i.assignee_id,u.name ORDER BY total DESC,assignee_name"
);
$bind($assigneeStmt);
$assigneeStmt->execute();
$assignees = $assigneeStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$detailsStmt = $conn->prepare(
    "SELECT p.name AS project_name,i.id,i.title,i.severity,i.priority,i.status,
            COALESCE(u.name,'Chưa giao') AS assignee_name,i.created_at,i.updated_at,
            EXISTS(
              SELECT 1 FROM dependencies d JOIN issues b ON b.id=d.depends_on_issue_id
              WHERE d.issue_id=i.id AND b.project_id=i.project_id
                AND i.status NOT IN ('Resolved','Closed')
                AND b.status NOT IN ('Resolved','Closed')
            ) AS is_blocked
     FROM issues i
     JOIN projects p ON p.id=i.project_id
     JOIN project_members pm ON pm.project_id=i.project_id
     LEFT JOIN users u ON u.id=i.assignee_id
     WHERE pm.user_id=? AND i.created_at>=? AND i.created_at<?$projectCondition
     ORDER BY i.created_at DESC,i.id DESC"
);
$bind($detailsStmt);
$detailsStmt->execute();
$details = $detailsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'bugtrack-report-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Không thể tạo tệp CSV để tải xuống.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Dự án', 'Mã', 'Công việc', 'Mức độ', 'Ưu tiên', 'Trạng thái', 'Người phụ trách', 'Ngày tạo', 'Cập nhật lần cuối', 'Đang bị chặn']);
    foreach ($details as $row) {
        fputcsv($output, array_map('report_csv_value', [
            $row['project_name'],
            '#' . $row['id'],
            $row['title'],
            label_vi((string)$row['severity']),
            label_vi((string)$row['priority']),
            label_vi((string)$row['status']),
            $row['assignee_name'],
            $row['created_at'],
            $row['updated_at'],
            (int)$row['is_blocked'] === 1 ? 'Có' : 'Không',
        ]));
    }
    fclose($output);
    exit;
}

page_header('Báo cáo công việc');
?>
<section class="panel">
<form method="get" class="inline-form">
<label>Dự án<select name="project_id"><option value="">Tất cả dự án của tôi</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= $projectId === (string)$project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select></label>
<label>Từ ngày<input type="date" name="from" value="<?= e($from) ?>" required></label>
<label>Đến ngày<input type="date" name="to" value="<?= e($to) ?>" required></label>
<button type="submit">Xem báo cáo</button>
<a class="button secondary" href="reports.php?<?= e(http_build_query(['project_id' => $projectId, 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">Xuất CSV</a>
</form>
<p class="sub">Tổng hợp công việc được tạo trong khoảng <?= e($from) ?> đến <?= e($to) ?>. Số hoàn thành theo thời gian dựa trên lần cập nhật gần nhất của công việc.</p>
</section>
<section class="stats">
<div class="stat"><span>Công việc được tạo</span><strong><?= $total ?></strong><small>Trong khoảng thời gian đã chọn</small></div>
<div class="stat"><span>Đã hoàn thành</span><strong><?= (int)$summary['completed'] ?></strong><small class="sub"><?= $completionPercent ?>% công việc trong kỳ; <?= $completedInPeriod ?> việc được cập nhật hoàn thành trong kỳ</small></div>
<div class="stat"><span>Đang mở / xử lý</span><strong><?= (int)$summary['active'] ?></strong><small class="sub"><?= (int)$summary['critical'] ?> việc mức nghiêm trọng hoặc chặn phát hành</small></div>
<div class="stat stat-warning"><span>Đang bị chặn</span><strong><?= $blocked ?></strong><small>Công việc đang mở có phụ thuộc chưa hoàn tất</small></div>
</section>
<div class="dashboard-grid">
<?php foreach ($groupedQueries as $key => $query): ?>
<section class="panel"><h2><?= e($query['title']) ?></h2>
<?php if (!$grouped[$key]): ?><p class="empty-state">Không có dữ liệu trong khoảng thời gian này.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Nhóm</th><th>Số lượng</th></tr></thead><tbody>
<?php foreach ($grouped[$key] as $row): ?><tr><td><?= e(label_vi((string)$row['label'])) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endforeach; ?>
</div>
<section class="panel"><h2>Tiến độ theo người phụ trách</h2>
<?php if (!$assignees): ?><p class="empty-state">Không có công việc trong kỳ.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Người phụ trách</th><th>Tổng việc</th><th>Hoàn thành</th><th>Đang mở / xử lý</th></tr></thead><tbody>
<?php foreach ($assignees as $assignee): ?><tr><td><?= e($assignee['assignee_name']) ?></td><td><?= (int)$assignee['total'] ?></td><td><?= (int)$assignee['completed'] ?></td><td><?= (int)$assignee['active'] ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="panel"><div class="panel-head"><h2>Chi tiết công việc</h2><span><?= count($details) ?> dòng</span></div>
<?php if (!$details): ?><p class="empty-state">Không có công việc được tạo trong khoảng thời gian đã chọn.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Dự án</th><th>Mã / tiêu đề</th><th>Mức độ / ưu tiên</th><th>Trạng thái</th><th>Phụ trách</th><th>Ngày tạo</th><th>Chặn</th></tr></thead><tbody>
<?php foreach ($details as $row): ?><tr><td><?= e($row['project_name']) ?></td><td>#<?= (int)$row['id'] ?> · <?= e($row['title']) ?></td><td><?= e(label_vi((string)$row['severity'])) ?> · <?= e(label_vi((string)$row['priority'])) ?></td><td><?= e(label_vi((string)$row['status'])) ?></td><td><?= e($row['assignee_name']) ?></td><td><?= e($row['created_at']) ?></td><td><?= (int)$row['is_blocked'] === 1 ? 'Có' : 'Không' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php page_footer(); ?>
