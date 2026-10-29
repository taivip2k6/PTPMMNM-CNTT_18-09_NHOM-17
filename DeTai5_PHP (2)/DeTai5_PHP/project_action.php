<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_valid_csrf();
$action = (string)($_POST['action'] ?? '');
$userId = (string)$_SESSION['user_id'];
$transactionStarted = false;

try {
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new RuntimeException('Tên dự án không được để trống và tối đa 160 ký tự.');
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $insertProject = $conn->prepare('INSERT INTO projects(name,manager_id) VALUES(?,?)');
        $insertProject->bind_param('si', $name, $userId);
        $insertProject->execute();
        $projectId = (int)$conn->insert_id;
        $insertMember = $conn->prepare("INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,'Manager')");
        $insertMember->bind_param('ii', $projectId, $userId);
        $insertMember->execute();
        $conn->commit();
        $transactionStarted = false;
        $_SESSION['project_message'] = 'Đã tạo dự án #' . $projectId . ' và thêm bạn làm quản lý.';
    } elseif ($action === 'delete_project') {
        $projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$projectId) {
            throw new RuntimeException('Mã dự án không hợp lệ.');
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $managerCheck = $conn->prepare(
            'SELECT id FROM projects WHERE id=? AND manager_id=? FOR UPDATE'
        );
        $managerId = (int)$userId;
        $managerCheck->bind_param('ii', $projectId, $managerId);
        $managerCheck->execute();
        if (!$managerCheck->get_result()->fetch_row()) {
            $conn->rollback();
            $transactionStarted = false;
            http_response_code(403);
            exit('Chỉ quản lý dự án mới được xóa dự án.');
        }
        $finalizedCheck = $conn->prepare(
            'SELECT COUNT(*) FROM releases WHERE project_id=? AND finalized_at IS NOT NULL'
        );
        $finalizedCheck->bind_param('i', $projectId);
        $finalizedCheck->execute();
        if ((int)$finalizedCheck->get_result()->fetch_row()[0] > 0) {
            throw new RuntimeException('Không thể xóa dự án vì đã có phiên bản phát hành được chốt.');
        }
        $externalSnapshotCheck = $conn->prepare(
            'SELECT COUNT(*) FROM release_items ri
             JOIN issues i ON i.id=ri.issue_id
             JOIN releases r ON r.id=ri.release_id
             WHERE i.project_id=? AND r.project_id<>? AND r.finalized_at IS NOT NULL'
        );
        $externalSnapshotCheck->bind_param('ii', $projectId, $projectId);
        $externalSnapshotCheck->execute();
        if ((int)$externalSnapshotCheck->get_result()->fetch_row()[0] > 0) {
            throw new RuntimeException('Không thể xóa dự án vì công việc đang nằm trong lịch sử phát hành đã chốt của dự án khác.');
        }
        $removeReleaseItems = $conn->prepare(
            'DELETE ri FROM release_items ri
             JOIN issues i ON i.id=ri.issue_id
             WHERE i.project_id=?'
        );
        $removeReleaseItems->bind_param('i', $projectId);
        $removeReleaseItems->execute();
        $deleteProject = $conn->prepare('DELETE FROM projects WHERE id=?');
        $deleteProject->bind_param('i', $projectId);
        $deleteProject->execute();
        if ($deleteProject->affected_rows !== 1) {
            throw new RuntimeException('Không tìm thấy dự án cần xóa.');
        }
        $conn->commit();
        $transactionStarted = false;
        $_SESSION['project_message'] = 'Đã xóa dự án cùng công việc, bình luận, kết quả kiểm thử và các phiên bản chưa chốt.';
    } elseif (in_array($action, ['add_member','remove_member'], true)) {
        $projectId = trim((string)($_POST['project_id'] ?? ''));
        $targetId = trim((string)($_POST['user_id'] ?? ''));
        $managerCheck = $conn->prepare('SELECT 1 FROM projects WHERE id=? AND manager_id=?');
        $managerCheck->bind_param('ss', $projectId, $userId);
        $managerCheck->execute();
        if (!$managerCheck->get_result()->fetch_row()) {
            http_response_code(403);
            exit('Chỉ quản lý dự án mới được thay đổi danh sách thành viên.');
        }
        if ($action === 'add_member') {
            $userCheck = $conn->prepare('SELECT 1 FROM users WHERE id=?');
            $targetUserId = (int)$targetId;
            $userCheck->bind_param('i', $targetUserId);
            $userCheck->execute();
            if (!$userCheck->get_result()->fetch_row()) {
                throw new RuntimeException('Không tìm thấy tài khoản cần thêm.');
            }
            $role = (string)($_POST['member_role'] ?? '');
            if (!in_array($role, ['Developer','Tester','Release Manager'], true)) {
                throw new RuntimeException('Vai trò thành viên không hợp lệ.');
            }
            $projectInt = (int)$projectId;
            $stmt = $conn->prepare('INSERT IGNORE INTO project_members(project_id,user_id,role) VALUES(?,?,?)');
            $stmt->bind_param('iis', $projectInt, $targetUserId, $role);
            $stmt->execute();
            $_SESSION['project_message'] = $stmt->affected_rows ? 'Đã thêm thành viên.' : 'Người dùng đã là thành viên dự án.';
        } else {
            if ((int)$targetId === (int)$userId) {
                throw new RuntimeException('Quản lý dự án không thể tự gỡ mình khỏi dự án.');
            }
            $projectInt = (int)$projectId;
            $targetUserId = (int)$targetId;
            $stmt = $conn->prepare('DELETE FROM project_members WHERE project_id=? AND user_id=?');
            $stmt->bind_param('ii', $projectInt, $targetUserId);
            $stmt->execute();
            $_SESSION['project_message'] = $stmt->affected_rows ? 'Đã gỡ thành viên khỏi dự án.' : 'Thành viên không tồn tại trong dự án.';
        }
    } else {
        http_response_code(400);
        exit('Thao tác không hợp lệ.');
    }
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if ($exception->getCode() === 1062) {
        $_SESSION['project_message'] = 'Dữ liệu cần thêm đã tồn tại.';
    } else {
        throw $exception;
    }
} catch (RuntimeException $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    $_SESSION['project_message'] = $exception->getMessage();
}

header('Location: projects.php');
exit;
