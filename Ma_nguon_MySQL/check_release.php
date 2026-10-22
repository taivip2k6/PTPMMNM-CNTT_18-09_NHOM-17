<?php
require_once 'db.php';

function validateAndReleaseVersion($conn, $release_id) {
    // 1. Lấy thông tin phiên bản
    $sql_rel = "SELECT * FROM releases WHERE id = ?";
    $stmt_rel = $conn->prepare($sql_rel);
    $stmt_rel->bind_param("i", $release_id);
    $stmt_rel->execute();
    $release = $stmt_rel->get_result()->fetch_assoc();

    if (!$release) {
        return [false, "Không tìm thấy phiên bản!"];
    }

    // 2. Kiểm tra xem dự án có còn lỗi mức chặn (Blocking) chưa được kiểm tra đạt yêu cầu (Passed) không
    $sql_check = "SELECT COUNT(*) as blocking_count FROM issues 
                  WHERE project_id = ? AND priority = 'Blocking' AND (test_result != 'Passed' OR status != 'Closed')";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $release['project_id']);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result()->fetch_assoc();

    if ($result_check['blocking_count'] > 0) {
        return [false, "[TỪ CHỐI PHÁT HÀNH] Phiên bản này vẫn còn {$result_check['blocking_count']} lỗi mức chặn (Blocking) chưa được kiểm tra lại hoặc chưa đóng!"];
    }

    // 3. Cho phép phát hành nếu thỏa mãn
    $sql_update = "UPDATE releases SET status = 'Released' WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("i", $release_id);
    $stmt_update->execute();

    return [true, "[THÀNH CÔNG] Đã xác nhận phát hành phiên bản thành công!"];
}

// --- CHẠY THỬ NGHIỆM PHÉP THỬ 2 ---
// Thêm một phiên bản mẫu thuộc dự án 1
$conn->query("INSERT INTO releases (project_id, version_name, status) VALUES (1, 'v1.0.0', 'Draft')");
$release_id = $conn->insert_id;

echo "Đang thử nghiệm xác nhận phát hành phiên bản v1.0.0...\n";
list($success, $message) = validateAndReleaseVersion($conn, $release_id);
echo $message . "\n";
?>
