<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$userId = (string)$_SESSION['user_id'];
$data = [
    'project_id' => trim((string)($_GET['project_id'] ?? '')),
    'version' => '',
    'name' => '',
    'planned_date' => date('Y-m-d'),
    'status' => 'Draft',
    'notes' => '',
    'row_version' => 1,
];
$existing = null;
if ($id) {
    $stmt = $conn->prepare(
        'SELECT id,project_id,version_name AS version,name,planned_date,status,notes,row_version,finalized_at
         FROM releases WHERE id=?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if (!$existing) {
        http_response_code(404);
        exit('Không tìm thấy kế hoạch phát hành.');
    }
    if (!is_project_member($conn, (string)$existing['project_id'], $userId)) {
        http_response_code(403);
        exit('Bạn không có quyền xem kế hoạch phát hành này.');
    }
    $canManage = project_role_allows(
        project_member_role($conn, (string)$existing['project_id'], $userId),
        'release.manage'
    );
    if ($existing['finalized_at'] !== null) {
        http_response_code(409);
        exit('Phiên bản đã chốt; không thể sửa thông tin của lịch sử phát hành.');
    }
    $data = $existing;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    foreach (['project_id','version','name','planned_date','status','notes'] as $key) {
        $data[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $baseVersion = filter_var($_POST['row_version'] ?? null, FILTER_VALIDATE_INT);
    if ($data['project_id'] === '' || $data['version'] === '' || $data['name'] === '' || $data['planned_date'] === '') {
        $error = 'Vui lòng chọn dự án và nhập phiên bản, tên, ngày dự kiến.';
    } elseif (!in_array($data['status'], ['Draft','In Progress','Delayed','Cancelled'], true)) {
        $error = 'Trạng thái kế hoạch không hợp lệ.';
    } elseif (!is_project_member($conn, $data['project_id'], $userId)) {
        $error = 'Bạn không phải thành viên của dự án đã chọn.';
    } elseif ($id && $data['project_id'] !== $existing['project_id']) {
        $error = 'Không thể chuyển kế hoạch phát hành sang dự án khác.';
    } elseif ($id && (!$baseVersion || $baseVersion !== (int)$existing['row_version'])) {
        $error = 'Kế hoạch đã được người khác cập nhật. Hãy tải lại trước khi lưu.';
    }

    if ($error === '') {
        require_project_capability($conn, $data['project_id'], $userId, 'release.manage');
        try {
            if ($id) {
                $stmt = $conn->prepare(
                    'UPDATE releases SET version_name=?,name=?,planned_date=?,status=?,
                     notes=?,row_version=row_version+1 WHERE id=? AND row_version=? AND finalized_at IS NULL'
                );
                $stmt->bind_param(
                    'sssssii',
                    $data['version'],
                    $data['name'],
                    $data['planned_date'],
                    $data['status'],
                    $data['notes'],
                    $id,
                    $baseVersion
                );
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $error = 'Xung đột cập nhật hoặc phiên bản vừa được chốt. Tải lại trang để kiểm tra.';
                } else {
                    header('Location: release_view.php?id=' . $id);
                    exit;
                }
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO releases(project_id,version_name,name,planned_date,status,created_by,notes)
                     VALUES(?,?,?,?,?,?,?)'
                );
                $stmt->bind_param(
                    'sssssss',
                    $data['project_id'],
                    $data['version'],
                    $data['name'],
                    $data['planned_date'],
                    $data['status'],
                    $userId,
                    $data['notes']
                );
                $stmt->execute();
                header('Location: release_view.php?id=' . (int)$conn->insert_id);
                exit;
            }
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $error = 'Phiên bản này đã tồn tại trong dự án.';
            } else {
                throw $exception;
            }
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
    static fn(array $project): bool => project_role_allows((string)$project['role'], 'release.manage')
));
$canManage = $id === 0 ? $projects !== [] : ($canManage ?? false);
page_header($id ? 'Sửa kế hoạch phát hành' : 'Tạo kế hoạch phát hành');
?>
<section class="panel form-panel"><?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><?php if (!$canManage): ?><div class="alert">Vai trò của bạn chỉ được xem kế hoạch phát hành.</div><?php endif; ?><form method="post" class="form-grid"><?= csrf_input() ?><?php if ($id): ?><input type="hidden" name="project_id" value="<?= e($data['project_id']) ?>"><?php endif; ?><fieldset <?= $canManage ? '' : 'disabled' ?> class="form-grid">
<label>Dự án<select name="project_id" required <?= $id ? 'disabled' : '' ?>><option value="">Chọn dự án</option><?php foreach ($projects as $project): ?><option value="<?= e($project['id']) ?>" <?= $data['project_id'] === $project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select></label>
<label>Phiên bản<input name="version" maxlength="40" required value="<?= e($data['version']) ?>" placeholder="Ví dụ: v1.0.0"></label>
<label>Tên phát hành<input name="name" maxlength="160" required value="<?= e($data['name']) ?>"></label>
<label>Ngày dự kiến<input type="date" name="planned_date" required value="<?= e($data['planned_date']) ?>"></label>
<label>Trạng thái<select name="status"><?php foreach (['Draft','In Progress','Delayed','Cancelled'] as $value): ?><option value="<?= e($value) ?>" <?= $data['status'] === $value ? 'selected' : '' ?>><?= e(label_vi($value)) ?></option><?php endforeach; ?></select></label>
<label class="wide">Ghi chú<textarea name="notes" rows="4"><?= e($data['notes']) ?></textarea></label>
<?php if ($id): ?><input type="hidden" name="row_version" value="<?= (int)$data['row_version'] ?>"><?php endif; ?>
<div class="wide actions"><button type="submit">Lưu kế hoạch</button><a class="button secondary" href="<?= $id ? 'release_view.php?id=' . $id : 'releases.php' ?>">Hủy</a></div>
</fieldset>
</form></section>
<?php page_footer(); ?>
