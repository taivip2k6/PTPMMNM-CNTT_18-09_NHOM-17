<?php
require_once 'db.php';

// --- MÔ PHỎNG PHÉP THỬ 5 ---
echo "--- BƯỚC 1: CHỐT PHIÊN BẢN VÀ LƯU SNAPSHOT ---\n";

// Lấy một công việc mẫu (ví dụ: id = 1)
$issue_id = 1;
$sql_get = "SELECT title, status FROM issues WHERE id = ?";
$stmt = $conn->prepare($sql_get);
$stmt->bind_param("i", $issue_id);
$stmt->execute();
$issue = $stmt->get_result()->fetch_assoc();

// Tạo một phiên bản phát hành mới (id giả sử)
$conn->query("INSERT INTO releases (project_id, version_name, status) VALUES (1, 'v1.0.0', 'Released')");
$release_id = $conn->insert_id;

// Lưu snapshot vào bảng release_items tại thời điểm chốt
$sql_snap = "INSERT INTO release_items (release_id, issue_id, snapshot_title, snapshot_status) VALUES (?, ?, ?, ?)";
$stmt_snap = $conn->prepare($sql_snap);
$stmt_snap->bind_param("iiss", $release_id, $issue_id, $issue['title'], $issue['status']);
$stmt_snap->execute();

echo "Đã chốt phiên bản v1.0.0 với tiêu đề gốc: '{$issue['title']}' và trạng thái: '{$issue['status']}'\n\n";

echo "--- BƯỚC 2: SAU KHI CHỐT, SỬA ĐỔI CÔNG VIỆC Ở BẢNG CHÍNH ---\n";
$conn->query("UPDATE issues SET title = 'Tiêu đề công việc đã bị sửa đổi sau phát hành', status = 'Closed' WHERE id = $issue_id");
echo "Công việc ở bảng chính (issues) đã được cập nhật thành công.\n\n";

echo "--- BƯỚC 3: KIỂM TRA LẠI DỮ LIỆU ĐÃ CHỐT TRONG PHIÊN BẢN CŨ ---\n";
$sql_check_snap = "SELECT * FROM release_items WHERE release_id = ? AND issue_id = ?";
$stmt_check = $conn->prepare($sql_check_snap);
$stmt_check->bind_param("ii", $release_id, $issue_id);
$stmt_check->execute();
$snapshot_data = $stmt_check->get_result()->fetch_assoc();

echo "Dữ liệu tra cứu được từ lịch sử phiên bản v1.0.0:\n";
echo "- Tiêu đề snapshot: " . $snapshot_data['snapshot_title'] . "\n";
echo "- Trạng thái snapshot: " . $snapshot_data['snapshot_status'] . "\n";

if ($snapshot_data['snapshot_title'] != 'Tiêu đề công việc đã bị sửa đổi sau phát hành') {
    echo "\n[THÀNH CÔNG] Phép thử 5 đạt yêu cầu: Sửa công việc sau phát hành KHÔNG làm thay đổi danh sách và kết quả đã chốt của phiên bản cũ!\n";
} else {
    echo "\n[THẤT BẠI] Dữ liệu lịch sử bị thay đổi theo.\n";
}
?>
