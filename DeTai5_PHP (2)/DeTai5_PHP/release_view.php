<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('Mã phiên bản không hợp lệ.');
}
$stmt = $conn->prepare(
    'SELECT r.id,r.project_id,r.version_name AS version,r.name,r.status,r.planned_date,
            r.finalized_at,r.notes,p.name AS project_name
     FROM releases r JOIN projects p ON p.id=r.project_id WHERE r.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$release = $stmt->get_result()->fetch_assoc();
if (!$release) {
    http_response_code(404);
    exit('Không tìm thấy kế hoạch phát hành.');
}
if (!is_project_member($conn, (string)$release['project_id'], (string)$_SESSION['user_id'])) {
    http_response_code(403);
    exit('Bạn không có quyền xem kế hoạch phát hành này.');
}
$finalized = $release['finalized_at'] !== null;
$canManage = project_role_allows(
    project_member_role($conn, (string)$release['project_id'], (string)$_SESSION['user_id']),
    'release.manage'
);
$itemsStmt = $conn->prepare(
    'SELECT ri.issue_id,ri.snapshot_json,ri.snapshot_title,ri.snapshot_status,
            i.title,i.description,i.severity,i.priority,i.status,
            i.assignee_id,i.commit_url,i.repro_steps,i.expected_result,i.actual_result
     FROM release_items ri JOIN issues i ON i.id=ri.issue_id
     WHERE ri.release_id=? ORDER BY ri.issue_id'
);
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$selected = array_map(static fn(array $row): int => (int)$row['issue_id'], $items);
$failureMessage = (string)($_SESSION['release_error'] ?? '');
unset($_SESSION['release_error']);
$failures = $finalized ? [] : release_gate_failures($conn, $id);
$availableStmt = $conn->prepare('SELECT id,title,status,severity FROM issues WHERE project_id=? ORDER BY id');
$availableStmt->bind_param('s', $release['project_id']);
$availableStmt->execute();
$availableIssues = $availableStmt->get_result()->fetch_all(MYSQLI_ASSOC);
page_header('Phiên bản ' . $release['version']);
?>
<section class="panel">
<div class="panel-head"><div><h2><?= e($release['name']) ?></h2><p><?= e($release['project_name']) ?> · Dự kiến <?= e($release['planned_date']) ?> · <?= e(label_vi((string)$release['status'])) ?></p></div><div class="actions"><?php if (!$finalized && $canManage): ?><a class="button secondary" href="release_form.php?id=<?= $id ?>">Sửa thông tin</a><?php elseif ($finalized): ?><a class="button" href="release_notes.php?id=<?= $id ?>">Tải ghi chú phát hành</a><?php endif; ?></div></div>
<?php if ($release['notes']): ?><p><?= nl2br(e($release['notes'])) ?></p><?php endif; ?>
<?php if ($failureMessage): ?><div class="alert"><?= nl2br(e($failureMessage)) ?></div><?php endif; ?>
<?php if ($finalized): ?><p class="success-message">Danh sách đã chốt lúc <?= e($release['finalized_at']) ?>. Nội dung dưới đây được đọc từ snapshot và không đổi khi công việc tiếp tục được cập nhật.</p>
<div class="table-wrap"><table><thead><tr><th>Mã</th><th>Tiêu đề đã chốt</th><th>Mức độ</th><th>Trạng thái tại thời điểm chốt</th><th>Kiểm thử đã chốt</th></tr></thead><tbody><?php foreach ($items as $item): $snapshot = json_decode((string)$item['snapshot_json'], true); $snapshot = is_array($snapshot) ? $snapshot : []; $testResults = is_array($snapshot['test_results'] ?? null) ? $snapshot['test_results'] : []; ?><tr><td>#<?= (int)$item['issue_id'] ?></td><td><?= e($snapshot['title'] ?? $item['snapshot_title'] ?? $item['title']) ?></td><td><?= e(label_vi((string)($snapshot['severity'] ?? $item['severity']))) ?></td><td><?= e(label_vi((string)($snapshot['status'] ?? $item['snapshot_status'] ?? $item['status']))) ?></td><td><?php if (!$testResults): ?>Chưa có<?php else: ?><?php foreach ($testResults as $testResult): ?><div><strong><?= e(label_vi((string)$testResult['result'])) ?></strong> · <?= e($testResult['tester_name']) ?> · <?= e($testResult['created_at']) ?><small class="sub"><?= e($testResult['notes']) ?></small></div><?php endforeach; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?>
<h3>Điều kiện phát hành</h3><?php if ($failures): ?><div class="alert"><strong>Chưa thể chốt:</strong><ul><?php foreach ($failures as $failure): ?><li><?= e($failure) ?></li><?php endforeach; ?></ul></div><?php else: ?><p class="success-message">Không phát hiện phụ thuộc đang chặn hoặc lỗi mức nghiêm trọng chưa kiểm thử lại.</p><?php endif; ?>
<?php if ($canManage): ?><form method="post" action="release_action.php"><?= csrf_input() ?><input type="hidden" name="release_id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
<h3>Danh sách công việc dự kiến</h3><p>Chọn các công việc thuộc dự án này. Danh sách sẽ được đóng băng khi chốt phát hành.</p>
<div class="checklist"><?php foreach ($availableIssues as $issue): ?><label><input type="checkbox" name="issue_ids[]" value="<?= (int)$issue['id'] ?>" <?= in_array((int)$issue['id'], $selected, true) ? 'checked' : '' ?>><span>#<?= (int)$issue['id'] ?> · <?= e($issue['title']) ?><small><?= e(label_vi((string)$issue['severity'])) ?> · <?= e(label_vi((string)$issue['status'])) ?></small></span></label><?php endforeach; ?></div>
<button type="submit">Lưu danh sách dự kiến</button></form>
<form method="post" action="release_action.php" class="finalize-form" onsubmit="return confirm('Chốt phiên bản? Danh sách và ghi chú sẽ trở thành lịch sử bất biến.')"><?= csrf_input() ?><input type="hidden" name="release_id" value="<?= $id ?>"><input type="hidden" name="action" value="finalize"><button type="submit" <?= $failures ? 'disabled' : '' ?>>Kiểm tra điều kiện và chốt phát hành</button></form>
<?php else: ?><p class="alert">Chỉ quản lý dự án hoặc phụ trách phát hành mới được thay đổi danh sách và chốt phiên bản.</p><?php endif; ?>
<?php endif; ?>
</section>
<?php page_footer(); ?>
