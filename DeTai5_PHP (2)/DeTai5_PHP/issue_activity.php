<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_valid_csrf();

$issueId = filter_input(INPUT_POST, 'issue_id', FILTER_VALIDATE_INT);
if (!$issueId) {
    http_response_code(400);
    exit('Mã công việc không hợp lệ.');
}
$action = (string)($_POST['action'] ?? '');
$capability = $action === 'comment' ? 'comment.add' : ($action === 'test' ? 'test.record' : '');
if ($capability === '') {
    http_response_code(400);
    exit('Loại hoạt động không hợp lệ.');
}
require_issue_member($conn, $issueId, (string)$_SESSION['user_id'], $capability);
$body = trim((string)($_POST['body'] ?? ''));
$userId = (string)$_SESSION['user_id'];

if ($action === 'comment' && $body !== '' && mb_strlen($body) <= 5000) {
    $stmt = $conn->prepare('INSERT INTO comments(issue_id,user_id,content) VALUES(?,?,?)');
    $stmt->bind_param('iss', $issueId, $userId, $body);
    $stmt->execute();
} elseif ($action === 'test' && $body !== '' && mb_strlen($body) <= 5000) {
    $result = (string)($_POST['result'] ?? '');
    if (!in_array($result, ['Passed','Failed','Blocked'], true)) {
        http_response_code(400);
        exit('Kết quả kiểm thử không hợp lệ.');
    }
    $stmt = $conn->prepare(
        'INSERT INTO test_results(issue_id,tester_id,result,notes) VALUES(?,?,?,?)'
    );
    $stmt->bind_param('isss', $issueId, $userId, $result, $body);
    $stmt->execute();
} else {
    http_response_code(400);
    exit('Nội dung thao tác không hợp lệ hoặc vượt quá 5.000 ký tự.');
}

header('Location: issue_form.php?id=' . $issueId);
exit;
