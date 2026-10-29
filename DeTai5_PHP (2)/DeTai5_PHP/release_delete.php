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
    exit('Mã phiên bản không hợp lệ.');
}
$stmt = $conn->prepare('SELECT project_id,finalized_at FROM releases WHERE id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$release = $stmt->get_result()->fetch_assoc();
if (!$release) {
    http_response_code(404);
    exit('Không tìm thấy phiên bản.');
}
require_project_capability($conn, (string)$release['project_id'], (string)$_SESSION['user_id'], 'release.manage');
if ($release['finalized_at'] !== null) {
    http_response_code(409);
    exit('Không thể xóa phiên bản đã chốt.');
}
$delete = $conn->prepare('DELETE FROM releases WHERE id=? AND finalized_at IS NULL');
$delete->bind_param('i', $id);
$delete->execute();
if ($delete->affected_rows !== 1) {
    http_response_code(409);
    exit('Phiên bản vừa được cập nhật; tải lại danh sách.');
}
header('Location: releases.php');
exit;
