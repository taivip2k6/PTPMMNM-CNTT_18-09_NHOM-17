<?php
require_once 'db.php';
require_once 'dependency_checker.php';

// Giả sử ta thử thêm liên kết: Công việc 1 phụ thuộc vào Công việc 3 (trong khi đang có sẵn: 3 phụ thuộc 2, 2 phụ thuộc 1 -> tạo thành vòng 1 -> 3 -> 2 -> 1)
$issue_id = 1;        // Công việc A
$depends_on_id = 3;   // Công việc C

echo "Đang kiểm tra thêm quan hệ: Công việc {$issue_id} phụ thuộc vào Công việc {$depends_on_id}...\n";

list($hasCycle, $path) = hasDependencyCycle($conn, $issue_id, $depends_on_id);

if ($hasCycle) {
    echo "[TỪ CHỐI] Phát hiện quan hệ vòng lặp!\n";
    echo "Chuỗi gây vòng: " . implode(" -> ", $path) . "\n";
} else {
    echo "[HỢP LỆ] Không có vòng, cho phép thêm.\n";
}
?>
