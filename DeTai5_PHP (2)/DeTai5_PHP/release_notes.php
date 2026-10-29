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
            r.release_date,r.finalized_at,r.notes,p.name AS project_name
     FROM releases r JOIN projects p ON p.id=r.project_id WHERE r.id=?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$release = $stmt->get_result()->fetch_assoc();
if (!$release) {
    http_response_code(404);
    exit('Không tìm thấy phiên bản.');
}
if (!is_project_member($conn, (string)$release['project_id'], (string)$_SESSION['user_id'])) {
    http_response_code(403);
    exit('Bạn không có quyền xem phiên bản này.');
}
if ($release['finalized_at'] === null) {
    http_response_code(409);
    exit('Chỉ có thể xuất ghi chú sau khi chốt phiên bản.');
}
$itemsStmt = $conn->prepare(
    'SELECT issue_id,snapshot_json FROM release_items WHERE release_id=? ORDER BY issue_id'
);
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lines = [
    '# ' . $release['name'] . ' (' . $release['version'] . ')',
    '',
    '- Dự án: ' . $release['project_name'],
    '- Ngày phát hành: ' . $release['finalized_at'],
    '',
    '## Thay đổi',
];
foreach ($items as $item) {
    $snapshot = json_decode((string)$item['snapshot_json'], true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('Snapshot của công việc #' . $item['issue_id'] . ' không hợp lệ.');
    }
    $lines[] = '- **#' . $item['issue_id'] . ' ' . ($snapshot['title'] ?? '(không có tiêu đề)')
        . '** — ' . ($snapshot['description'] ?? '')
        . ' _(Mức độ: ' . label_vi((string)($snapshot['severity'] ?? 'N/A')) . '; trạng thái: '
        . label_vi((string)($snapshot['status'] ?? 'N/A')) . ')_';
    foreach (($snapshot['test_results'] ?? []) as $testResult) {
        $lines[] = '  - Kiểm thử ' . label_vi((string)($testResult['result'] ?? 'N/A')) . ' bởi '
            . ($testResult['tester_name'] ?? 'N/A') . ' lúc '
            . ($testResult['created_at'] ?? 'N/A') . ': '
            . ($testResult['notes'] ?? '');
    }
}
if ($release['notes'] !== '') {
    $lines[] = '';
    $lines[] = '## Ghi chú';
    $lines[] = $release['notes'];
}
header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="release-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $release['version']) . '.md"');
echo implode("\r\n", $lines);
