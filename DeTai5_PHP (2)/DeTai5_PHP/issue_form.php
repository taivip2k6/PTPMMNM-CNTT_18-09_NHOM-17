<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$userId = (string)$_SESSION['user_id'];
$issue = $id ? require_issue_member($conn, $id, $userId) : null;
$issueRole = $issue ? project_member_role($conn, (string)$issue['project_id'], $userId) : null;
$canEdit = $id === 0 || project_role_allows($issueRole, 'issue.edit');
$canComment = project_role_allows($issueRole, 'comment.add');
$canRecordTest = project_role_allows($issueRole, 'test.record');
$data = $issue ?? [
    'project_id' => '',
    'title' => '',
    'description' => '',
    'repro_steps' => '',
    'expected_result' => '',
    'actual_result' => '',
    'severity' => 'Medium',
    'priority' => 'Medium',
    'status' => 'Open',
    'assignee_id' => '',
    'commit_url' => '',
    'lock_version' => 0,
];
$selectedDependencies = [];
if ($id) {
    $stmt = $conn->prepare('SELECT depends_on_issue_id FROM dependencies WHERE issue_id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $selectedDependencies = array_map(
        'intval',
        array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'depends_on_issue_id')
    );
}
$error = '';
$conflict = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    if ($id) {
        require_issue_member($conn, $id, $userId, 'issue.edit');
    }
    foreach ([
        'project_id', 'title', 'description', 'repro_steps', 'expected_result',
        'actual_result', 'severity', 'priority', 'status', 'assignee_id', 'commit_url',
    ] as $key) {
        $data[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $selectedDependencies = array_values(array_unique(array_map(
        'intval',
        is_array($_POST['dependencies'] ?? null) ? $_POST['dependencies'] : []
    )));
    $baseVersion = filter_var($_POST['lock_version'] ?? null, FILTER_VALIDATE_INT);

    if ($data['project_id'] === '' || $data['title'] === '' || $data['description'] === '') {
        $error = 'Vui lòng chọn dự án và nhập tiêu đề, mô tả.';
    } elseif (!in_array($data['severity'], ['Low','Medium','High','Critical'], true)
        || !in_array($data['priority'], ['Low','Medium','High','Urgent','Blocking'], true)
        || !in_array($data['status'], ['Open','In Progress','Resolved','Closed'], true)) {
        $error = 'Mức độ, ưu tiên hoặc trạng thái không hợp lệ.';
    } elseif ($id && $data['project_id'] !== $issue['project_id']) {
        $error = 'Không thể chuyển công việc sang dự án khác vì các liên kết phụ thuộc và lịch sử đang thuộc dự án hiện tại.';
    } elseif (!is_project_member($conn, $data['project_id'], $userId)) {
        $error = 'Bạn không phải thành viên của dự án đã chọn.';
    } elseif ($id && ($baseVersion === false || $baseVersion !== (int)$issue['lock_version'])) {
        $error = 'Dữ liệu nền đã thay đổi. Hãy tải lại công việc trước khi lưu.';
        $conflict = true;
    }

    if ($error === '' && $data['assignee_id'] !== ''
        && !is_project_member($conn, $data['project_id'], $data['assignee_id'])) {
        $error = 'Người phụ trách phải là thành viên của dự án.';
    }
    if ($error === '' && $data['commit_url'] !== ''
        && filter_var($data['commit_url'], FILTER_VALIDATE_URL) === false) {
        $error = 'Liên kết commit/PR không hợp lệ.';
    }
    if ($error === '' && ($id === 0 && $selectedDependencies !== [])) {
        $error = 'Lưu công việc trước, sau đó có thể khai báo các công việc phụ thuộc.';
    }
    if ($error === '' && $id === 0) {
        require_project_capability($conn, $data['project_id'], $userId, 'issue.create');
    }
    if ($error === '' && $id && in_array($id, $selectedDependencies, true)) {
        $error = 'Công việc không thể phụ thuộc vào chính nó.';
    }

    if ($error === '' && $id && $selectedDependencies !== []) {
        $dependencyProject = $conn->prepare('SELECT project_id FROM issues WHERE id=?');
        foreach ($selectedDependencies as $dependencyId) {
            $dependencyProject->bind_param('i', $dependencyId);
            $dependencyProject->execute();
            $dependency = $dependencyProject->get_result()->fetch_assoc();
            if (!$dependency || $dependency['project_id'] !== $data['project_id']) {
                $error = 'Các công việc phụ thuộc phải thuộc cùng dự án.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id FROM issues WHERE project_id=? FOR UPDATE');
            $lock->bind_param('s', $data['project_id']);
            $lock->execute();
            $lock->get_result()->fetch_all(MYSQLI_ASSOC);

            if ($id) {
                $stmt = $conn->prepare(
                    'UPDATE issues SET title=?, description=?, repro_steps=?, expected_result=?,
                     actual_result=?, severity=?, priority=?, status=?, assignee_id=?, commit_url=?,
                     lock_version=lock_version+1 WHERE id=? AND lock_version=?'
                );
                $assigneeId = $data['assignee_id'] === '' ? null : $data['assignee_id'];
                $stmt->bind_param(
                    'ssssssssssii',
                    $data['title'],
                    $data['description'],
                    $data['repro_steps'],
                    $data['expected_result'],
                    $data['actual_result'],
                    $data['severity'],
                    $data['priority'],
                    $data['status'],
                    $assigneeId,
                    $data['commit_url'],
                    $id,
                    $baseVersion
                );
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $conn->rollback();
                    $error = 'Xung đột chỉnh sửa: người khác đã cập nhật công việc này. Tải lại để xem phiên bản mới nhất.';
                    $conflict = true;
                } else {
                    $cycle = find_dependency_cycle($conn, $data['project_id'], $id, $selectedDependencies);
                    if ($cycle !== []) {
                        $conn->rollback();
                        $error = 'Không thể lưu vì tạo quan hệ vòng: ' . implode(' → ', array_map(
                            static fn(int $cycleId): string => '#' . $cycleId,
                            $cycle
                        ));
                    } else {
                        $delete = $conn->prepare('DELETE FROM dependencies WHERE issue_id=?');
                        $delete->bind_param('i', $id);
                        $delete->execute();
                        $insert = $conn->prepare(
                            'INSERT INTO dependencies(issue_id, depends_on_issue_id) VALUES(?,?)'
                        );
                        foreach ($selectedDependencies as $dependencyId) {
                            $insert->bind_param('ii', $id, $dependencyId);
                            $insert->execute();
                        }
                        $conn->commit();
                    }
                }
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO issues(project_id,title,description,repro_steps,expected_result,actual_result,
                     severity,priority,status,assignee_id,created_by,commit_url)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $assigneeId = $data['assignee_id'] === '' ? null : $data['assignee_id'];
                $stmt->bind_param(
                    'ssssssssssss',
                    $data['project_id'],
                    $data['title'],
                    $data['description'],
                    $data['repro_steps'],
                    $data['expected_result'],
                    $data['actual_result'],
                    $data['severity'],
                    $data['priority'],
                    $data['status'],
                    $assigneeId,
                    $userId,
                    $data['commit_url']
                );
                $stmt->execute();
                $newId = (int)$conn->insert_id;
                $conn->commit();
                header('Location: issue_form.php?id=' . $newId);
                exit;
            }

            if ($error === '') {
                header('Location: issue_form.php?id=' . $id);
                exit;
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

$projectsStmt = $conn->prepare(
    'SELECT p.id,p.name,pm.role FROM projects p JOIN project_members pm ON pm.project_id=p.id
     WHERE pm.user_id=? ORDER BY p.name'
);
$projectsStmt->bind_param('s', $userId);
$projectsStmt->execute();
$projects = array_values(array_filter(
    $projectsStmt->get_result()->fetch_all(MYSQLI_ASSOC),
    static fn(array $project): bool => project_role_allows((string)$project['role'], 'issue.create')
));
$canEdit = $id === 0 ? $projects !== [] : $canEdit;
$membersStmt = $conn->prepare(
    'SELECT u.id,u.name,pm.role FROM project_members pm JOIN users u ON u.id=pm.user_id
     WHERE pm.project_id=? ORDER BY u.name'
);
$membersStmt->bind_param('s', $data['project_id']);
$membersStmt->execute();
$members = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dependencyStmt = $conn->prepare(
    'SELECT id,title,status FROM issues WHERE project_id=? AND id<>? ORDER BY id'
);
$dependencyStmt->bind_param('si', $data['project_id'], $id);
$dependencyStmt->execute();
$dependencyOptions = $dependencyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

page_header($id ? 'Cập nhật công việc' : 'Báo lỗi / tạo công việc');
?>
<?php if ($error): ?><div class="alert"><?= e($error) ?><?php if ($conflict && $id): ?> <a href="issue_form.php?id=<?= $id ?>">Tải phiên bản mới</a><?php endif; ?></div><?php endif; ?>
<section class="panel form-panel">
<form method="post" class="form-grid">
<?= csrf_input() ?>
<fieldset <?= $canEdit ? '' : 'disabled' ?> class="form-grid">
<label>Dự án<select name="project_id" required <?= $id ? 'disabled' : '' ?>><option value="">Chọn dự án</option><?php foreach ($projects as $project): ?><option value="<?= e($project['id']) ?>" <?= $data['project_id'] === $project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select><?php if ($id): ?><input type="hidden" name="project_id" value="<?= e($data['project_id']) ?>"><?php endif; ?></label>
<label>Người phụ trách<select name="assignee_id"><option value="">Chưa giao</option><?php foreach ($members as $member): ?><option value="<?= e($member['id']) ?>" <?= (string)$data['assignee_id'] === $member['id'] ? 'selected' : '' ?>><?= e($member['name']) ?> — <?= e(label_vi((string)$member['role'])) ?></option><?php endforeach; ?></select></label>
<label class="wide">Tiêu đề<input name="title" maxlength="180" required value="<?= e($data['title']) ?>"></label>
<label class="wide">Mô tả<textarea name="description" rows="4" required><?= e($data['description']) ?></textarea></label>
<label class="wide">Các bước tái hiện<textarea name="repro_steps" rows="3"><?= e($data['repro_steps']) ?></textarea></label>
<label>Kết quả mong đợi<textarea name="expected_result" rows="3"><?= e($data['expected_result']) ?></textarea></label>
<label>Kết quả thực tế<textarea name="actual_result" rows="3"><?= e($data['actual_result']) ?></textarea></label>
<label>Mức độ<select name="severity"><?php foreach (['Low','Medium','High','Critical'] as $value): ?><option value="<?= e($value) ?>" <?= $data['severity'] === $value ? 'selected' : '' ?>><?= e(label_vi($value)) ?></option><?php endforeach; ?></select></label>
<label>Ưu tiên<select name="priority"><?php foreach (['Low','Medium','High','Urgent','Blocking'] as $value): ?><option value="<?= e($value) ?>" <?= $data['priority'] === $value ? 'selected' : '' ?>><?= e(label_vi($value)) ?></option><?php endforeach; ?></select></label>
<label>Trạng thái<select name="status"><?php foreach (['Open','In Progress','Resolved','Closed'] as $value): ?><option value="<?= e($value) ?>" <?= $data['status'] === $value ? 'selected' : '' ?>><?= e(label_vi($value)) ?></option><?php endforeach; ?></select></label>
<label>Commit / PR URL<input type="url" name="commit_url" maxlength="500" value="<?= e($data['commit_url']) ?>" placeholder="https://..."></label>
<?php if ($id): ?><label class="wide">Công việc phải hoàn thành trước (Ctrl/⌘ để chọn nhiều)<select name="dependencies[]" multiple size="6"><?php foreach ($dependencyOptions as $dependency): ?><option value="<?= (int)$dependency['id'] ?>" <?= in_array((int)$dependency['id'], $selectedDependencies, true) ? 'selected' : '' ?>>#<?= (int)$dependency['id'] ?> · <?= e($dependency['title']) ?> (<?= e(label_vi((string)$dependency['status'])) ?>)</option><?php endforeach; ?></select></label><input type="hidden" name="lock_version" value="<?= (int)$data['lock_version'] ?>"><?php endif; ?>
<div class="wide actions"><?php if ($canEdit): ?><button type="submit"><?= $id ? 'Lưu công việc' : 'Tạo công việc' ?></button><?php else: ?><span class="sub">Vai trò của bạn chỉ được xem và ghi kết quả kiểm thử/bình luận.</span><?php endif; ?><a class="button secondary" href="issues.php">Quay lại</a></div>
</fieldset>
</form>
</section>
<?php if ($id): ?>
<?php $blockers = issue_blockers($conn, $id); ?>
<section class="panel"><h2>Tiến độ phụ thuộc</h2><?php if ($blockers): ?><p class="alert">Đang bị chặn bởi <?= count($blockers) ?> công việc chưa hoàn tất.</p><ul><?php foreach ($blockers as $blocker): ?><li><a href="issue_form.php?id=<?= (int)$blocker['id'] ?>">#<?= (int)$blocker['id'] ?> — <?= e($blocker['title']) ?></a> (<?= e(label_vi((string)$blocker['status'])) ?>)</li><?php endforeach; ?></ul><?php else: ?><p>Không có công việc nào đang chặn tiến độ.</p><?php endif; ?></section>
<section class="panel"><h2>Bình luận</h2><?php $commentStmt = $conn->prepare('SELECT c.content AS body,c.created_at,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.issue_id=? ORDER BY c.id DESC'); $commentStmt->bind_param('i',$id); $commentStmt->execute(); ?><div class="activity-list"><?php foreach ($commentStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $comment): ?><article><strong><?= e($comment['name']) ?></strong><small><?= e($comment['created_at']) ?></small><p><?= nl2br(e($comment['body'])) ?></p></article><?php endforeach; ?></div><?php if ($canComment): ?><form method="post" action="issue_activity.php" class="inline-form"><?= csrf_input() ?><input type="hidden" name="issue_id" value="<?= $id ?>"><input type="hidden" name="action" value="comment"><label class="wide">Thêm bình luận<textarea name="body" required maxlength="5000"></textarea></label><button type="submit">Gửi bình luận</button></form><?php endif; ?></section>
<section class="panel"><h2>Kết quả kiểm tra sau sửa</h2><?php $testStmt = $conn->prepare('SELECT t.result,t.notes,t.created_at,u.name FROM test_results t JOIN users u ON u.id=t.tester_id WHERE t.issue_id=? ORDER BY t.id DESC'); $testStmt->bind_param('i',$id); $testStmt->execute(); ?><div class="activity-list"><?php foreach ($testStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $test): ?><article><strong><?= e(label_vi((string)$test['result'])) ?> · <?= e($test['name']) ?></strong><small><?= e($test['created_at']) ?></small><p><?= nl2br(e($test['notes'])) ?></p></article><?php endforeach; ?></div><?php if ($canRecordTest): ?><form method="post" action="issue_activity.php" class="form-grid"><?= csrf_input() ?><input type="hidden" name="issue_id" value="<?= $id ?>"><input type="hidden" name="action" value="test"><label>Kết quả<select name="result"><option value="Passed">Đạt</option><option value="Failed">Không đạt</option><option value="Blocked">Bị chặn</option></select></label><label class="wide">Ghi chú kiểm thử<textarea name="notes" required maxlength="5000"></textarea></label><button type="submit">Lưu kết quả kiểm tra</button></form><?php endif; ?></section>
<?php endif; ?>
<?php page_footer(); ?>
