<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line.');
}
require_once __DIR__ . '/config.php';

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_assoc()['total'] === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "Added $table.$column\n";
    }
}

function scalar(mysqli $conn, string $sql): int
{
    return (int)$conn->query($sql)->fetch_row()[0];
}

ensure_column($conn, 'projects', 'manager_id', 'INT NULL');
ensure_column($conn, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
ensure_column($conn, 'issues', 'repro_steps', 'TEXT NULL');
ensure_column($conn, 'issues', 'expected_result', 'TEXT NULL');
ensure_column($conn, 'issues', 'actual_result', 'TEXT NULL');
ensure_column($conn, 'issues', 'created_by', 'INT NULL');
ensure_column($conn, 'issues', 'commit_url', 'VARCHAR(500) NULL');
ensure_column($conn, 'releases', 'name', 'VARCHAR(160) NULL');
ensure_column($conn, 'releases', 'created_by', 'INT NULL');
ensure_column($conn, 'releases', 'checked_at', 'DATETIME NULL');
ensure_column($conn, 'releases', 'finalized_at', 'DATETIME NULL');
ensure_column($conn, 'releases', 'row_version', 'INT UNSIGNED NOT NULL DEFAULT 1');
ensure_column($conn, 'releases', 'notes', 'TEXT NULL');
ensure_column($conn, 'release_items', 'snapshot_json', 'LONGTEXT NULL');

$adminAccount = $conn->query(
    "SELECT id FROM users WHERE username='admin' ORDER BY id LIMIT 1"
)->fetch_assoc();
if ($adminAccount) {
    $adminId = (int)$adminAccount['id'];
    $adminRoleUpdate = $conn->prepare("UPDATE users SET role='admin' WHERE id=?");
    $adminRoleUpdate->bind_param('i', $adminId);
    $adminRoleUpdate->execute();
} else {
    $adminUsername = 'admin';
    $adminName = 'Quản trị hệ thống';
    $adminPasswordHash = password_hash('Admin@123', PASSWORD_DEFAULT);
    $adminRole = 'admin';
    $adminInsert = $conn->prepare(
        'INSERT INTO users(username,name,password_hash,role,is_active) VALUES(?,?,?,?,1)'
    );
    $adminInsert->bind_param(
        'ssss',
        $adminUsername,
        $adminName,
        $adminPasswordHash,
        $adminRole
    );
    $adminInsert->execute();
    echo "Created system administrator account: admin\n";
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS test_results (
       id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
       issue_id INT NOT NULL,
       tester_id INT NOT NULL,
       result VARCHAR(20) NOT NULL,
       notes TEXT NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_test_issue (issue_id,created_at),
       CONSTRAINT fk_test_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
       CONSTRAINT fk_test_tester FOREIGN KEY (tester_id) REFERENCES users(id)
     ) ENGINE=InnoDB"
);

$fullNameColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'")->fetch_assoc();
if ($fullNameColumn) {
    $conn->query(
        "UPDATE users SET name=COALESCE(NULLIF(name,''),NULLIF(full_name,''),username)"
    );
} else {
    $conn->query("UPDATE users SET name=COALESCE(NULLIF(name,''),username)");
}
$conn->query(
    "UPDATE projects p
     SET manager_id=COALESCE(
       (SELECT pm.user_id FROM project_members pm
        WHERE pm.project_id=p.id AND pm.role='Manager' ORDER BY pm.user_id LIMIT 1),
       (SELECT pm.user_id FROM project_members pm
        WHERE pm.project_id=p.id ORDER BY pm.user_id LIMIT 1)
     )
     WHERE p.manager_id IS NULL"
);
$conn->query(
    "UPDATE project_members pm JOIN projects p ON p.id=pm.project_id
     SET pm.role='Manager' WHERE p.manager_id=pm.user_id"
);
$demoUser = $conn->query(
    "SELECT id FROM users WHERE username='u1' AND role='admin' ORDER BY id LIMIT 1"
)->fetch_assoc();
$firstProject = $conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetch_assoc();
if ($demoUser && $firstProject) {
    $demoUserId = (int)$demoUser['id'];
    $firstProjectId = (int)$firstProject['id'];
    $addDemoMember = $conn->prepare(
        "INSERT IGNORE INTO project_members(project_id,user_id,role) VALUES(?,?,'Release Manager')"
    );
    $addDemoMember->bind_param('ii', $firstProjectId, $demoUserId);
    $addDemoMember->execute();
}
$conn->query(
    "UPDATE issues i JOIN projects p ON p.id=i.project_id
     SET i.created_by=COALESCE(i.created_by,i.assignee_id,p.manager_id)
     WHERE i.created_by IS NULL"
);
$conn->query("UPDATE issues SET status='Resolved' WHERE status='Fixed'");

$legacyTestColumn = $conn->query("SHOW COLUMNS FROM issues LIKE 'test_result'")->fetch_assoc();
$legacyTests = $legacyTestColumn
    ? $conn->query(
        "SELECT i.id,i.project_id,i.test_result,p.manager_id,
                (SELECT pm.user_id FROM project_members pm
                 WHERE pm.project_id=i.project_id AND pm.role='Tester'
                 ORDER BY pm.user_id LIMIT 1) AS tester_id
         FROM issues i JOIN projects p ON p.id=i.project_id
         WHERE i.test_result IS NOT NULL AND i.test_result NOT IN ('','Pending')
           AND NOT EXISTS (SELECT 1 FROM test_results t WHERE t.issue_id=i.id)"
    )->fetch_all(MYSQLI_ASSOC)
    : [];
$legacyTestInsert = $conn->prepare(
    'INSERT INTO test_results(issue_id,tester_id,result,notes) VALUES(?,?,?,?)'
);
foreach ($legacyTests as $legacyTest) {
    $testResult = stripos((string)$legacyTest['test_result'], 'pass') !== false ? 'Passed' : 'Failed';
    $issueId = (int)$legacyTest['id'];
    $testerId = (int)($legacyTest['tester_id'] ?? $legacyTest['manager_id']);
    $notes = 'Kết quả kiểm thử được chuyển từ dữ liệu cũ.';
    $legacyTestInsert->bind_param('iiss', $issueId, $testerId, $testResult, $notes);
    $legacyTestInsert->execute();
}

$legacyPasswordColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch_assoc();
$passwordRows = [];
if ($legacyPasswordColumn) {
$passwordRows = $conn->query(
    "SELECT id,password,password_hash FROM users
     WHERE password_hash IS NULL OR password_hash=''"
)->fetch_all(MYSQLI_ASSOC);
$passwordUpdate = $conn->prepare('UPDATE users SET password_hash=?,password=? WHERE id=?');
foreach ($passwordRows as $user) {
    $legacyPassword = (string)$user['password'];
    if ($legacyPassword === '') {
        throw new RuntimeException('A user has no password hash or legacy password; set a password before migration.');
    }
    $hash = password_hash($legacyPassword, PASSWORD_DEFAULT);
    $emptyPassword = '';
    $userId = (int)$user['id'];
    $passwordUpdate->bind_param('ssi', $hash, $emptyPassword, $userId);
    $passwordUpdate->execute();
}
} else {
    $missingHashes = scalar(
        $conn,
        "SELECT COUNT(*) FROM users WHERE password_hash IS NULL OR password_hash=''"
    );
    if ($missingHashes > 0) {
        throw new RuntimeException(
            'Some accounts have no password hash and this schema has no legacy password column; set a password hash before migration.'
        );
    }
}
if ($passwordRows !== []) {
    echo 'Hashed ' . count($passwordRows) . " legacy account passwords\n";
}

$conn->query(
    "UPDATE releases r JOIN projects p ON p.id=r.project_id
     SET r.name=COALESCE(NULLIF(r.name,''),r.version_name),
         r.created_by=COALESCE(r.created_by,p.manager_id),
         r.planned_date=COALESCE(r.planned_date,r.release_date,DATE(r.created_at),CURDATE()),
         r.finalized_at=CASE WHEN r.status='Released'
           THEN COALESCE(r.finalized_at,r.release_date,r.created_at,NOW())
           ELSE r.finalized_at END,
         r.checked_at=CASE WHEN r.status='Released'
           THEN COALESCE(r.checked_at,r.finalized_at,r.release_date,r.created_at,NOW())
           ELSE r.checked_at END"
);

$conn->query(
    "UPDATE release_items ri
     JOIN issues i ON i.id=ri.issue_id
     JOIN releases r ON r.id=ri.release_id
     SET ri.snapshot_json=JSON_OBJECT(
       'title',COALESCE(NULLIF(ri.snapshot_title,''),i.title),
       'description',i.description,
       'repro_steps',i.repro_steps,
       'expected_result',i.expected_result,
       'actual_result',i.actual_result,
       'severity',i.severity,
       'priority',i.priority,
       'status',COALESCE(NULLIF(ri.snapshot_status,''),i.status),
       'assignee_id',i.assignee_id,
       'commit_url',i.commit_url,
       'test_results',JSON_ARRAY()
     )
     WHERE r.finalized_at IS NOT NULL AND ri.snapshot_json IS NULL"
);

$memberStmt = $conn->prepare(
    "SELECT pm.user_id FROM project_members pm WHERE pm.project_id=? ORDER BY
     CASE pm.role WHEN 'Developer' THEN 0 ELSE 1 END, pm.user_id LIMIT 1"
);
$issueCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM issues WHERE project_id=?');
$issueInsert = $conn->prepare(
    "INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
     VALUES(?,?,?,'Medium','Medium','Open',?,?)"
);
$issueExists = $conn->prepare('SELECT 1 FROM issues WHERE project_id=? AND title=? LIMIT 1');
$demoUsers = $conn->query(
    "SELECT id,username FROM users WHERE username IN ('u1','u2','u3','u5')"
)->fetch_all(MYSQLI_ASSOC);
$demoUserIds = [];
foreach ($demoUsers as $demoUserRow) {
    $demoUserIds[(string)$demoUserRow['username']] = (int)$demoUserRow['id'];
}
if (!isset($demoUserIds['u1'])) {
    throw new RuntimeException('Không tìm thấy tài khoản mẫu u1 để tạo dữ liệu dự án.');
}
$seedDefinitions = [
    [
        'name' => 'Cổng thanh toán trực tuyến',
        'description' => 'Theo dõi lỗi và công việc tích hợp thanh toán trực tuyến.',
        'issues' => [
            ['Thiết lập cấu hình cổng thanh toán', 'Khai báo môi trường và thông tin kết nối thanh toán.', 'High', 'High', 'In Progress', 'u2'],
            ['Xác thực chữ ký giao dịch', 'Từ chối callback có chữ ký không hợp lệ.', 'Critical', 'Blocking', 'Open', 'u2'],
            ['Xử lý callback thanh toán', 'Cập nhật trạng thái đơn hàng từ cổng thanh toán.', 'High', 'High', 'Open', 'u2'],
            ['Chống ghi nhận giao dịch trùng', 'Đảm bảo callback gửi lại không tạo giao dịch trùng.', 'High', 'Urgent', 'Open', 'u2'],
            ['Lưu nhật ký giao dịch', 'Tra cứu được lịch sử yêu cầu và phản hồi thanh toán.', 'Medium', 'Medium', 'Open', 'u2'],
            ['Hiển thị kết quả thanh toán', 'Thông báo rõ trạng thái thành công hoặc thất bại.', 'Medium', 'Medium', 'Open', 'u3'],
            ['Kiểm thử hoàn tiền', 'Xác minh luồng hoàn tiền và số tiền hoàn.', 'High', 'High', 'Open', 'u3'],
            ['Chuẩn bị kiểm tra phát hành', 'Rà soát cấu hình và các điều kiện trước khi phát hành.', 'Medium', 'Medium', 'Open', 'u5'],
        ],
        'dependencies' => [[2, 1], [3, 2], [4, 3], [6, 3], [7, 4]],
    ],
    [
        'name' => 'Quản lý kho và đơn hàng',
        'description' => 'Theo dõi lỗi và công việc quản lý tồn kho, nhập xuất và đơn hàng.',
        'issues' => [
            ['Thiết kế quy trình nhập kho', 'Ghi nhận lô hàng nhập và người thực hiện.', 'Medium', 'Medium', 'In Progress', 'u2'],
            ['Kiểm tra số lượng tồn kho', 'Đối chiếu tồn kho trước khi xác nhận giao dịch.', 'Critical', 'Blocking', 'Open', 'u2'],
            ['Cập nhật tồn kho khi nhập hàng', 'Tăng tồn kho sau khi phiếu nhập được xác nhận.', 'High', 'High', 'Open', 'u2'],
            ['Trừ tồn kho khi tạo đơn', 'Giảm tồn kho đúng một lần khi đơn hàng được xác nhận.', 'High', 'Urgent', 'Open', 'u2'],
            ['Ngăn tồn kho âm', 'Từ chối giao dịch làm số lượng tồn kho nhỏ hơn không.', 'Critical', 'Blocking', 'Open', 'u2'],
            ['Tra cứu lịch sử xuất nhập', 'Hiển thị lịch sử biến động theo sản phẩm.', 'Medium', 'Medium', 'Open', 'u3'],
            ['Thông báo hàng sắp hết', 'Cảnh báo khi số lượng chạm ngưỡng tối thiểu.', 'Low', 'Low', 'Open', 'u3'],
            ['Kiểm thử đối soát đơn hàng', 'Đối chiếu số lượng đơn hàng và tồn kho trước phát hành.', 'High', 'High', 'Open', 'u5'],
        ],
        'dependencies' => [[2, 1], [3, 2], [4, 2], [5, 4], [7, 3], [8, 5]],
    ],
];
$projectExists = $conn->prepare('SELECT id FROM projects WHERE name=? LIMIT 1');
$projectInsert = $conn->prepare(
    'INSERT INTO projects(name,description,manager_id) VALUES(?,?,?)'
);
$seedMemberInsert = $conn->prepare(
    'INSERT IGNORE INTO project_members(project_id,user_id,role) VALUES(?,?,?)'
);
$seedIssueExists = $conn->prepare(
    'SELECT id FROM issues WHERE project_id=? AND title=? LIMIT 1'
);
$seedIssueInsert = $conn->prepare(
    'INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
     VALUES(?,?,?,?,?,?,?,?)'
);
$seedReleaseExists = $conn->prepare(
    'SELECT id FROM releases WHERE project_id=? AND version_name=? LIMIT 1'
);
$seedReleaseInsert = $conn->prepare(
    'INSERT INTO releases(project_id,version_name,name,status,planned_date,created_by,notes)
     VALUES(?,?,?,"Draft",?,?,?)'
);
$seedReleaseItemInsert = $conn->prepare(
    'INSERT IGNORE INTO release_items(release_id,issue_id) VALUES(?,?)'
);
$seedDependencyCheck = $conn->prepare(
    'SELECT 1 FROM dependencies WHERE issue_id=? AND depends_on_issue_id=?'
);
$seedDependencyInsert = $conn->prepare(
    'INSERT INTO dependencies(issue_id,depends_on_issue_id) VALUES(?,?)'
);
foreach ($seedDefinitions as $definition) {
    $projectName = $definition['name'];
    $projectExists->bind_param('s', $projectName);
    $projectExists->execute();
    $existingProject = $projectExists->get_result()->fetch_assoc();
    if ($existingProject) {
        $seedProjectId = (int)$existingProject['id'];
    } else {
        $projectDescription = $definition['description'];
        $managerId = $demoUserIds['u1'];
        $projectInsert->bind_param('ssi', $projectName, $projectDescription, $managerId);
        $projectInsert->execute();
        $seedProjectId = (int)$conn->insert_id;
    }
    foreach ([
        ['u1', 'Manager'],
        ['u2', 'Developer'],
        ['u3', 'Tester'],
        ['u5', 'Release Manager'],
    ] as [$username, $role]) {
        if (!isset($demoUserIds[$username])) {
            continue;
        }
        $memberUserId = $demoUserIds[$username];
        $seedMemberInsert->bind_param('iis', $seedProjectId, $memberUserId, $role);
        $seedMemberInsert->execute();
    }

    $seedIssueIds = [];
    foreach ($definition['issues'] as [$title, $description, $severity, $priority, $status, $assigneeName]) {
        $seedIssueExists->bind_param('is', $seedProjectId, $title);
        $seedIssueExists->execute();
        $existingIssue = $seedIssueExists->get_result()->fetch_assoc();
        if ($existingIssue) {
            $seedIssueIds[$title] = (int)$existingIssue['id'];
            continue;
        }
        $assigneeId = $demoUserIds[$assigneeName] ?? $demoUserIds['u1'];
        $creatorId = $demoUserIds['u1'];
        $seedIssueInsert->bind_param(
            'isssssii',
            $seedProjectId,
            $title,
            $description,
            $severity,
            $priority,
            $status,
            $assigneeId,
            $creatorId
        );
        $seedIssueInsert->execute();
        $seedIssueIds[$title] = (int)$conn->insert_id;
    }
    foreach ($definition['dependencies'] as [$dependentIndex, $prerequisiteIndex]) {
        $dependentId = $seedIssueIds[$definition['issues'][$dependentIndex - 1][0]];
        $prerequisiteId = $seedIssueIds[$definition['issues'][$prerequisiteIndex - 1][0]];
        $seedDependencyCheck->bind_param('ii', $dependentId, $prerequisiteId);
        $seedDependencyCheck->execute();
        if (!$seedDependencyCheck->get_result()->fetch_row()) {
            $seedDependencyInsert->bind_param('ii', $dependentId, $prerequisiteId);
            $seedDependencyInsert->execute();
        }
    }

    $versionName = 'v1.0.0';
    $seedReleaseExists->bind_param('is', $seedProjectId, $versionName);
    $seedReleaseExists->execute();
    $existingRelease = $seedReleaseExists->get_result()->fetch_assoc();
    if ($existingRelease) {
        $seedReleaseId = (int)$existingRelease['id'];
    } else {
        $releaseName = $projectName . ' 1.0';
        $plannedDate = date('Y-m-d', strtotime('+30 days'));
        $releaseCreatorId = $demoUserIds['u1'];
        $releaseNotes = 'Phiên bản mẫu để kiểm tra phụ thuộc và điều kiện phát hành.';
        $seedReleaseInsert->bind_param(
            'isssis',
            $seedProjectId,
            $versionName,
            $releaseName,
            $plannedDate,
            $releaseCreatorId,
            $releaseNotes
        );
        $seedReleaseInsert->execute();
        $seedReleaseId = (int)$conn->insert_id;
    }
    foreach (array_slice(array_values($seedIssueIds), 0, 4) as $selectedIssueId) {
        $seedReleaseItemInsert->bind_param('ii', $seedReleaseId, $selectedIssueId);
        $seedReleaseItemInsert->execute();
    }
}
$projectRows = $conn->query('SELECT id,name,manager_id FROM projects ORDER BY id')->fetch_all(MYSQLI_ASSOC);
if ($projectRows === []) {
    throw new RuntimeException('No projects were found; create a project and member before running this migration.');
}
foreach ($projectRows as $project) {
    $projectId = (int)$project['id'];
    $managerId = (int)$project['manager_id'];
    $issueCountStmt->bind_param('i', $projectId);
    $issueCountStmt->execute();
    $currentCount = (int)$issueCountStmt->get_result()->fetch_assoc()['total'];
    $memberStmt->bind_param('i', $projectId);
    $memberStmt->execute();
    $assigneeId = (int)($memberStmt->get_result()->fetch_row()[0] ?? $managerId);
    $seedNumber = 1;
    while ($currentCount < 20) {
        $title = sprintf('Công việc mẫu bổ sung %s %02d', $project['name'], $seedNumber);
        $issueExists->bind_param('is', $projectId, $title);
        $issueExists->execute();
        if ($issueExists->get_result()->fetch_row()) {
            $seedNumber++;
            continue;
        }
        $description = 'Dữ liệu mẫu để kiểm thử phân công, phụ thuộc và luồng phát hành.';
        $issueInsert->bind_param('issii', $projectId, $title, $description, $assigneeId, $managerId);
        $issueInsert->execute();
        $currentCount++;
        $seedNumber++;
    }
}

$dependencyCount = scalar($conn, 'SELECT COUNT(*) FROM dependencies');
$dependencyCheck = $conn->prepare(
    'SELECT 1 FROM dependencies WHERE issue_id=? AND depends_on_issue_id=?'
);
$dependencyInsert = $conn->prepare(
    'INSERT INTO dependencies(issue_id,depends_on_issue_id) VALUES(?,?)'
);
foreach ($projectRows as $project) {
    $projectId = (int)$project['id'];
    $issueIdsStmt = $conn->prepare('SELECT id FROM issues WHERE project_id=? ORDER BY id');
    $issueIdsStmt->bind_param('i', $projectId);
    $issueIdsStmt->execute();
    $issueIds = array_map(
        'intval',
        array_column($issueIdsStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
    );
    for ($index = 1; $index < count($issueIds) && $dependencyCount < 15; $index++) {
        $issueId = $issueIds[$index];
        $dependsOnId = $issueIds[$index - 1];
        $dependencyCheck->bind_param('ii', $issueId, $dependsOnId);
        $dependencyCheck->execute();
        if (!$dependencyCheck->get_result()->fetch_row()) {
            $dependencyInsert->bind_param('ii', $issueId, $dependsOnId);
            $dependencyInsert->execute();
            $dependencyCount++;
        }
    }
}

if (scalar($conn, "SELECT COUNT(*) FROM releases WHERE name='Bản phát hành mẫu'") === 0
    && scalar($conn, 'SELECT COUNT(*) FROM releases') < 3) {
    $releaseProject = (int)$projectRows[count($projectRows) - 1]['id'];
    $releaseCreator = (int)$projectRows[count($projectRows) - 1]['manager_id'];
    $versionName = 'v1.0.0';
    $releaseName = 'Bản phát hành mẫu';
    $releaseStatus = 'Draft';
    $plannedDate = date('Y-m-d', strtotime('+30 days'));
    $releaseInsert = $conn->prepare(
        'INSERT INTO releases(project_id,version_name,name,status,planned_date,created_by,notes)
         VALUES(?,?,?,?,?,?,?)'
    );
    $releaseNotes = 'Phiên bản mẫu để thử cổng kiểm tra và chốt snapshot.';
    $releaseInsert->bind_param(
        'issssis',
        $releaseProject,
        $versionName,
        $releaseName,
        $releaseStatus,
        $plannedDate,
        $releaseCreator,
        $releaseNotes
    );
    $releaseInsert->execute();
}
$sampleReleaseResult = $conn->query(
    "SELECT id,project_id FROM releases WHERE name='Bản phát hành mẫu' ORDER BY id LIMIT 1"
);
$sampleRelease = $sampleReleaseResult->fetch_assoc();
$sampleReleaseResult->free();
if ($sampleRelease) {
    $newReleaseId = (int)$sampleRelease['id'];
    $releaseProject = (int)$sampleRelease['project_id'];
    $selectedStmt = $conn->prepare('SELECT id FROM issues WHERE project_id=? ORDER BY id LIMIT 2');
    $selectedStmt->bind_param('i', $releaseProject);
    $selectedStmt->execute();
    $selectedResult = $selectedStmt->get_result();
    $selectedIssues = $selectedResult->fetch_all(MYSQLI_ASSOC);
    $selectedResult->free();
    $itemInsert = $conn->prepare(
        'INSERT IGNORE INTO release_items(release_id,issue_id) VALUES(?,?)'
    );
    foreach ($selectedIssues as $selectedIssue) {
        $issueId = (int)$selectedIssue['id'];
        $itemInsert->bind_param('ii', $newReleaseId, $issueId);
        $itemInsert->execute();
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS app_migrations (
       migration_key VARCHAR(120) NOT NULL PRIMARY KEY,
       applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB"
);
$progressMigration = 'sample_project_progress_20261029';
$progressMigrationCheck = $conn->prepare(
    'SELECT 1 FROM app_migrations WHERE migration_key=?'
);
$progressMigrationCheck->bind_param('s', $progressMigration);
$progressMigrationCheck->execute();
if (!$progressMigrationCheck->get_result()->fetch_row()) {
    $testerRow = $conn->query(
        "SELECT id FROM users WHERE username='u3' ORDER BY id LIMIT 1"
    )->fetch_assoc();
    if ($testerRow) {
        $testerId = (int)$testerRow['id'];
        $webProject = $conn->query(
            "SELECT id FROM projects WHERE name='Web Platform' ORDER BY id LIMIT 1"
        )->fetch_assoc();
        if ($webProject) {
            $webProjectId = (int)$webProject['id'];
            $memberAdd = $conn->prepare(
                "INSERT IGNORE INTO project_members(project_id,user_id,role) VALUES(?,?,'Tester')"
            );
            $memberAdd->bind_param('ii', $webProjectId, $testerId);
            $memberAdd->execute();
        }
        $progressItems = [
            ['Web Platform', 'Trang danh sách công việc'],
            ['Web Platform', 'Lọc công việc theo trạng thái'],
            ['Web Platform', 'Phân công người xử lý'],
            ['Web Platform', 'Ghi nhận mức ưu tiên'],
            ['Web Platform', 'Tối ưu trang tổng quan'],
            ['Mobile App', 'Thông báo lỗi mạng'],
            ['Mobile App', 'Phân trang danh sách'],
            ['Mobile App', 'Bộ lọc trạng thái hồ sơ'],
            ['Mobile App', 'Tối ưu ảnh hồ sơ'],
            ['Mobile App', 'Bổ sung nhật ký hoạt động'],
            ['Cổng thanh toán trực tuyến', 'Lưu nhật ký giao dịch'],
            ['Cổng thanh toán trực tuyến', 'Chuẩn bị kiểm tra phát hành'],
            ['Quản lý kho và đơn hàng', 'Tra cứu lịch sử xuất nhập'],
            ['Quản lý kho và đơn hàng', 'Thông báo hàng sắp hết'],
        ];
        $progressUpdate = $conn->prepare(
            "UPDATE issues i JOIN projects p ON p.id=i.project_id
             SET i.status='Resolved'
             WHERE p.name=? AND i.title=? AND i.status IN ('Open','In Progress')
               AND i.id NOT IN (
                 SELECT ri.issue_id FROM release_items ri
                 JOIN releases r ON r.id=ri.release_id
                 WHERE r.finalized_at IS NOT NULL
               )"
        );
        $progressTestCheck = $conn->prepare(
            'SELECT 1 FROM test_results WHERE issue_id=? LIMIT 1'
        );
        $progressTestInsert = $conn->prepare(
            'INSERT INTO test_results(issue_id,tester_id,result,notes) VALUES(?,?,"Passed",?)'
        );
        $progressTestNotes = 'Kiểm thử mẫu: chức năng hoạt động đúng theo yêu cầu.';
        foreach ($progressItems as [$projectName, $issueTitle]) {
            $progressUpdate->bind_param('ss', $projectName, $issueTitle);
            $progressUpdate->execute();
            $issueLookup = $conn->prepare(
                'SELECT i.id FROM issues i JOIN projects p ON p.id=i.project_id
                 WHERE p.name=? AND i.title=? AND i.status="Resolved" LIMIT 1'
            );
            $issueLookup->bind_param('ss', $projectName, $issueTitle);
            $issueLookup->execute();
            $progressIssue = $issueLookup->get_result()->fetch_assoc();
            if (!$progressIssue) {
                continue;
            }
            $progressIssueId = (int)$progressIssue['id'];
            $progressTestCheck->bind_param('i', $progressIssueId);
            $progressTestCheck->execute();
            if (!$progressTestCheck->get_result()->fetch_row()) {
                $progressTestInsert->bind_param(
                    'iis',
                    $progressIssueId,
                    $testerId,
                    $progressTestNotes
                );
                $progressTestInsert->execute();
            }
        }
    }
    $markProgressMigration = $conn->prepare(
        'INSERT INTO app_migrations(migration_key) VALUES(?)'
    );
    $markProgressMigration->bind_param('s', $progressMigration);
    $markProgressMigration->execute();
    echo "Applied sample project progress data\n";
}

echo 'Migration complete. Projects: ' . scalar($conn, 'SELECT COUNT(*) FROM projects')
    . ', issues: ' . scalar($conn, 'SELECT COUNT(*) FROM issues')
    . ', dependencies: ' . scalar($conn, 'SELECT COUNT(*) FROM dependencies')
    . ', releases: ' . scalar($conn, 'SELECT COUNT(*) FROM releases') . "\n";
