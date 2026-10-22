<?php
// Hàm kiểm tra xem việc thêm liên kết (issue_id phụ thuộc vào depends_on_id) có tạo thành vòng lặp không
function hasDependencyCycle($conn, $issue_id, $depends_on_id) {
    // Nếu một công việc tự phụ thuộc vào chính nó
    if ($issue_id == $depends_on_id) {
        return [true, [$issue_id, $depends_on_id]];
    }

    $visited = [];
    $path = [];
    
    // Đệ quy DFS để tìm đường đi từ depends_on_id quay ngược về issue_id
    function dfs($current, $target, &$conn, &$visited, &$path) {
        if ($current == $target) {
            return true;
        }
        $visited[$current] = true;
        $path[] = $current;

        // Lấy danh sách các công việc mà $current đang phụ thuộc vào
        $sql = "SELECT depends_on_issue_id FROM dependencies WHERE issue_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $current);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $next = $row['depends_on_issue_id'];
            if (!isset($visited[$next])) {
                if (dfs($next, $target, $conn, $visited, $path)) {
                    return true;
                }
            }
        }
        array_pop($path);
        return false;
    }

    $cycle_path = [$issue_id];
    if (dfs($depends_on_id, $issue_id, $conn, $visited, $cycle_path)) {
        $cycle_path[] = $depends_on_id;
        return [true, $cycle_path]; // Phát hiện vòng, trả về chuỗi gây vòng
    }

    return [false, []]; // Không có vòng, hợp lệ
}
?>
