<?php
require_once 'db.php';

function updateIssueWithLock($conn, $issue_id, $new_title, $new_status, $user_form_lock_version) {
    // 1. Kiểm tra lock_version hiện tại trong database
    $sql = "SELECT lock_version, title FROM issues WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $issue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();

    if (!$current) {
        return [false, "Không tìm thấy công việc!"];
    }

    // 2. So sánh phiên bản form với database
    if ($current['lock_version'] != $user_form_lock_version) {
        return [false, "[XUNG ĐỘT] Dữ liệu đã bị người khác thay đổi trong lúc bạn đang chỉnh sửa! Lưu thất bại."];
    }

    // 3. Nếu khớp, tiến hành cập nhật và tăng lock_version lên 1
    $update_sql = "UPDATE issues SET title = ?, status = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssii", $new_title, $new_status, $issue_id, $user_form_lock_version);
    $update_stmt->execute();

    if ($update_stmt->affected_rows > 0) {
        return [true, "[THÀNH CÔNG] Cập nhật công việc thành công!"];
    } else {
        return [false, "[XUNG ĐỘT] Không thể cập nhật do xung đột phiên bản đồng thời."];
    }
}

// --- PHẦN CHẠY THỬ NGHIỆM MÔ PHỎNG 2 NGƯỜI CÙNG SỬA ---
$issue_id = 1;

// Giả sử Người dùng A lấy dữ liệu lúc lock_version = 0
$version_cua_A = 0; 

echo "--- MÔ PHỎNG NGƯỜI DÙNG B SỬA TRƯỚC ---\n";
// Người dùng B sửa trước và làm lock_version tăng lên 1
$conn->query("UPDATE issues SET title = 'Đã sửa bởi Người dùng B', lock_version = lock_version + 1 WHERE id = $issue_id");
echo "Người dùng B đã lưu thành công thay đổi.\n\n";

echo "--- NGƯỜI DÙNG A CỐ GẮNG LƯU SAU VỚI PHIÊN BẢN CŨ ($version_cua_A) ---\n";
list($success, $message) = updateIssueWithLock($conn, $issue_id, 'Tiêu đề mới của A', 'Closed', $version_cua_A);
echo $message . "\n";
?>
