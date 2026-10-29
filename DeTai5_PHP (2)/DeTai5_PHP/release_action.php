<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_valid_csrf();

$releaseId = filter_input(INPUT_POST, 'release_id', FILTER_VALIDATE_INT);
$action = (string)($_POST['action'] ?? '');
if (!$releaseId || !in_array($action, ['save','finalize'], true)) {
    http_response_code(400);
    exit('Yêu cầu không hợp lệ.');
}
$stmt = $conn->prepare(
    'SELECT project_id,finalized_at,status FROM releases WHERE id=?'
);
$stmt->bind_param('i', $releaseId);
$stmt->execute();
$release = $stmt->get_result()->fetch_assoc();
if (!$release) {
    http_response_code(404);
    exit('Không tìm thấy phiên bản.');
}
require_project_capability($conn, (string)$release['project_id'], (string)$_SESSION['user_id'], 'release.manage');
if ($release['finalized_at'] !== null) {
    http_response_code(409);
    exit('Phiên bản đã chốt và không thể thay đổi.');
}

try {
    $conn->begin_transaction();
    $lockRelease = $conn->prepare('SELECT project_id,finalized_at,status FROM releases WHERE id=? FOR UPDATE');
    $lockRelease->bind_param('i', $releaseId);
    $lockRelease->execute();
    $lockedRelease = $lockRelease->get_result()->fetch_assoc();
    if (!$lockedRelease || $lockedRelease['finalized_at'] !== null) {
        throw new RuntimeException('Phiên bản vừa được chốt bởi người khác.');
    }
    if ($action === 'finalize' && $lockedRelease['status'] === 'Cancelled') {
        throw new RuntimeException('Không thể chốt phiên bản đã hủy.');
    }

    if ($action === 'save') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($_POST['issue_ids'] ?? null) ? $_POST['issue_ids'] : []),
            static fn(int $value): bool => $value > 0
        )));
        if (count($ids) > 200) {
            throw new RuntimeException('Danh sách công việc vượt giới hạn.');
        }
        $verify = $conn->prepare('SELECT project_id FROM issues WHERE id=?');
        foreach ($ids as $issueId) {
            $verify->bind_param('i', $issueId);
            $verify->execute();
            $issue = $verify->get_result()->fetch_assoc();
            if (!$issue || $issue['project_id'] !== $lockedRelease['project_id']) {
                throw new RuntimeException('Danh sách chỉ được chứa công việc thuộc dự án của phiên bản.');
            }
        }
        $delete = $conn->prepare('DELETE FROM release_items WHERE release_id=?');
        $delete->bind_param('i', $releaseId);
        $delete->execute();
        $insert = $conn->prepare('INSERT INTO release_items(release_id,issue_id) VALUES(?,?)');
        foreach ($ids as $issueId) {
            $insert->bind_param('ii', $releaseId, $issueId);
            $insert->execute();
        }
    } else {
        $lockIssues = $conn->prepare('SELECT id FROM issues WHERE project_id=? FOR UPDATE');
        $lockIssues->bind_param('s', $lockedRelease['project_id']);
        $lockIssues->execute();
        $lockIssues->get_result()->fetch_all(MYSQLI_ASSOC);
        $failures = release_gate_failures($conn, $releaseId);
        if ($failures !== []) {
            throw new RuntimeException("Không thể chốt phát hành:\n- " . implode("\n- ", $failures));
        }
        $itemsStmt = $conn->prepare(
            'SELECT i.* FROM release_items ri JOIN issues i ON i.id=ri.issue_id WHERE ri.release_id=?'
        );
        $itemsStmt->bind_param('i', $releaseId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $snapshotStmt = $conn->prepare(
            'UPDATE release_items SET snapshot_json=? WHERE release_id=? AND issue_id=?'
        );
        foreach ($items as $item) {
            $snapshot = snapshot_issue($conn, $item);
            $issueId = (int)$item['id'];
            $snapshotStmt->bind_param('sii', $snapshot, $releaseId, $issueId);
            $snapshotStmt->execute();
        }
        $finalize = $conn->prepare(
            "UPDATE releases SET status='Released',release_date=CURDATE(),checked_at=NOW(),finalized_at=NOW(),
             row_version=row_version+1 WHERE id=? AND finalized_at IS NULL"
        );
        $finalize->bind_param('i', $releaseId);
        $finalize->execute();
        if ($finalize->affected_rows !== 1) {
            throw new RuntimeException('Phiên bản đã được chốt bởi người khác.');
        }
    }
    $conn->commit();
} catch (RuntimeException $exception) {
    $conn->rollback();
    $_SESSION['release_error'] = $exception->getMessage();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

header('Location: release_view.php?id=' . $releaseId);
exit;
