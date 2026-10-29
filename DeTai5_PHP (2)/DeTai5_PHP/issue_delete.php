<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_valid_csrf();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('Mã công việc không hợp lệ.');
}
require_issue_member($conn, $id, (string)$_SESSION['user_id'], 'issue.delete');
$stmt = $conn->prepare(
    "SELECT 1 FROM release_items ri JOIN releases r ON r.id=ri.release_id
     WHERE ri.issue_id=? AND r.finalized_at IS NOT NULL LIMIT 1"
);
$stmt->bind_param('i', $id);
$stmt->execute();
if ($stmt->get_result()->fetch_row()) {
    http_response_code(409);
    exit('Không thể xóa công việc đã nằm trong phiên bản phát hành được chốt.');
}
$conn->begin_transaction();
try {
    $unlink = $conn->prepare('DELETE FROM release_items WHERE issue_id=?');
    $unlink->bind_param('i', $id);
    $unlink->execute();
    $delete = $conn->prepare('DELETE FROM issues WHERE id=?');
    $delete->bind_param('i', $id);
    $delete->execute();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
header('Location: issues.php');
exit;
