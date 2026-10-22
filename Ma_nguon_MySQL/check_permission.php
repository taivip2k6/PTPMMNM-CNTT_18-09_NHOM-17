<?php
require_once 'db.php';

function checkUserCanModifyIssue($conn, $user_id, $issue_id) {
    // 1. Tìm xem công việc này thuộc dự án nào
    $sql_issue = "SELECT project_id FROM issues WHERE id = ?";
    $stmt_issue = $conn->prepare($sql_issue);
    $stmt_issue->bind_param("i", $issue_id);
    $stmt_issue->execute();
    $issue = $stmt_issue->get_result()->fetch_assoc();

    if (!$issue) {
        return [false, "Không tìm thấy công việc!"];
    }

    $project_id = $issue['project_id'];

    // 2. Kiểm tra xem user_id có nằm trong bảng project_members của dự án này không
    $sql_perm = "SELECT * FROM project_members WHERE project_id = ? AND user_id = ?";
    $stmt_perm = $conn->prepare($sql_perm);
    $stmt_perm->bind_param("ii", $project_id, $user_id);
    $stmt_perm->execute();
    $permission = $stmt_perm->get_result()->fetch_assoc();

    if (!$permission) {
        return [false, "[TỪ CHỐI QUYỀN] Thành viên này không thuộc dự án chứa công việc, không có quyền chỉnh sửa!"];
    }

    return [true, "[HỢP LỆ] Thành viên có quyền chỉnh sửa công việc này."];
}

// --- CHẠY THỬ NGHIỆM PHÉP THỬ 4 ---
// Lấy user 'dev_an' (có id = 1, chỉ thuộc Dự án 1)
$user_id = 1; 

// Tạo một công việc thuộc Dự án Beta (Dự án 2)
$conn->query("INSERT INTO issues (project_id, title, description, priority, status) VALUES (2, 'Lỗi bảo mật dự án B', 'Test phân quyền', 'High', 'Open')");
$issue_id_du_an_beta = $conn->insert_id;

echo "Thử nghiệm: Thành viên An (chỉ thuộc Dự án 1) cố gắng sửa công việc thuộc Dự án 2...\n";
list($success, $message) = checkUserCanModifyIssue($conn, $user_id, $issue_id_du_an_beta);
echo $message . "\n";
?>
