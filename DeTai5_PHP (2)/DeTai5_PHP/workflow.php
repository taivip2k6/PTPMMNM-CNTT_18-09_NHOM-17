<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function is_project_member(mysqli $conn, string $projectId, string $userId): bool
{
    return project_member_role($conn, $projectId, $userId) !== null;
}

function project_member_role(mysqli $conn, string $projectId, string $userId): ?string
{
    $stmt = $conn->prepare('SELECT role FROM project_members WHERE project_id=? AND user_id=?');
    $stmt->bind_param('ss', $projectId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string)$row['role'] : null;
}

function project_role_allows(?string $role, string $capability): bool
{
    $capabilities = [
        'Manager' => ['issue.create', 'issue.edit', 'issue.delete', 'comment.add', 'test.record', 'release.manage'],
        'Developer' => ['issue.create', 'issue.edit', 'comment.add'],
        'Tester' => ['issue.create', 'comment.add', 'test.record'],
        'Release Manager' => ['comment.add', 'release.manage'],
    ];
    return $role !== null && in_array($capability, $capabilities[$role] ?? [], true);
}

function require_project_capability(mysqli $conn, string $projectId, string $userId, string $capability): string
{
    $role = project_member_role($conn, $projectId, $userId);
    if ($role === null) {
        http_response_code(403);
        exit('Bạn không phải thành viên của dự án này.');
    }
    if (!project_role_allows($role, $capability)) {
        http_response_code(403);
        exit('Vai trò ' . label_vi($role) . ' không được phép thực hiện thao tác này.');
    }
    return $role;
}

function require_issue_member(mysqli $conn, int $issueId, string $userId, ?string $capability = null): array
{
    $stmt = $conn->prepare(
        'SELECT i.*, p.name AS project_name FROM issues i
         JOIN projects p ON p.id=i.project_id WHERE i.id=?'
    );
    $stmt->bind_param('i', $issueId);
    $stmt->execute();
    $issue = $stmt->get_result()->fetch_assoc();
    if (!$issue) {
        http_response_code(404);
        exit('Không tìm thấy công việc.');
    }
    if ($capability === null) {
        if (!is_project_member($conn, (string)$issue['project_id'], $userId)) {
            http_response_code(403);
            exit('Bạn không có quyền thao tác công việc thuộc dự án này.');
        }
    } else {
        require_project_capability($conn, (string)$issue['project_id'], $userId, $capability);
    }
    return $issue;
}

function find_dependency_cycle(mysqli $conn, string $projectId, int $issueId, array $newDependencies): array
{
    $stmt = $conn->prepare(
        'SELECT d.issue_id, d.depends_on_issue_id
         FROM dependencies d
         JOIN issues i ON i.id=d.issue_id
         WHERE i.project_id=? AND d.issue_id<>?'
    );
    $stmt->bind_param('si', $projectId, $issueId);
    $stmt->execute();
    $graph = [];
    $result = $stmt->get_result();
    while ($edge = $result->fetch_assoc()) {
        $graph[(int)$edge['issue_id']][] = (int)$edge['depends_on_issue_id'];
    }
    $graph[$issueId] = array_values(array_unique(array_map('intval', $newDependencies)));

    $state = [];
    $stack = [];
    $visit = function (int $node) use (&$visit, &$state, &$stack, $graph): array {
        $state[$node] = 1;
        $stack[] = $node;
        foreach ($graph[$node] ?? [] as $next) {
            if (($state[$next] ?? 0) === 1) {
                $start = array_search($next, $stack, true);
                return array_merge(array_slice($stack, (int)$start), [$next]);
            }
            if (($state[$next] ?? 0) === 0) {
                $cycle = $visit($next);
                if ($cycle !== []) {
                    return $cycle;
                }
            }
        }
        array_pop($stack);
        $state[$node] = 2;
        return [];
    };

    foreach (array_keys($graph) as $node) {
        if (($state[$node] ?? 0) === 0) {
            $cycle = $visit((int)$node);
            if ($cycle !== []) {
                return $cycle;
            }
        }
    }
    return [];
}

function issue_blockers(mysqli $conn, int $issueId): array
{
    $stmt = $conn->prepare(
        "SELECT d.depends_on_issue_id AS id, i.title, i.status
         FROM dependencies d
         JOIN issues i ON i.id=d.depends_on_issue_id
         WHERE d.issue_id=? AND i.status NOT IN ('Resolved','Closed')
         ORDER BY d.depends_on_issue_id"
    );
    $stmt->bind_param('i', $issueId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function release_gate_failures(mysqli $conn, int $releaseId): array
{
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM release_items WHERE release_id=?');
    $countStmt->bind_param('i', $releaseId);
    $countStmt->execute();
    if ((int)$countStmt->get_result()->fetch_assoc()['total'] === 0) {
        return ['Danh sách phiên bản đang trống. Hãy chọn ít nhất một công việc trước khi chốt.'];
    }
    $stmt = $conn->prepare(
        "SELECT i.id, i.title, i.severity, i.priority, i.status, i.updated_at,
                (SELECT t.result FROM test_results t
                 WHERE t.issue_id=i.id ORDER BY t.created_at DESC,t.id DESC LIMIT 1) AS latest_test_result,
                (SELECT t.created_at FROM test_results t
                 WHERE t.issue_id=i.id ORDER BY t.created_at DESC,t.id DESC LIMIT 1) AS latest_test_at
         FROM release_items ri
         JOIN issues i ON i.id=ri.issue_id
         WHERE ri.release_id=?"
    );
    $stmt->bind_param('i', $releaseId);
    $stmt->execute();
    $failures = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $issue) {
        $issueId = (int)$issue['id'];
        foreach (issue_blockers($conn, $issueId) as $blocker) {
            $failures[] = sprintf(
                '#%d "%s" đang chờ công việc #%d "%s" (%s)',
                $issueId,
                $issue['title'],
                (int)$blocker['id'],
                $blocker['title'],
                label_vi((string)$blocker['status'])
            );
        }
        if (($issue['severity'] === 'Critical' || $issue['priority'] === 'Blocking')
            && ($issue['latest_test_result'] !== 'Passed'
                || $issue['latest_test_at'] === null
                || $issue['latest_test_at'] < $issue['updated_at'])) {
            $failures[] = sprintf(
                '#%d "%s" là lỗi mức nghiêm trọng/chặn phát hành và chưa có kiểm thử đạt sau lần cập nhật gần nhất',
                $issueId,
                $issue['title']
            );
        }
    }
    return $failures;
}

function snapshot_issue(mysqli $conn, array $issue): string
{
    $stmt = $conn->prepare(
        'SELECT t.result,t.notes,t.created_at,u.name AS tester_name
         FROM test_results t JOIN users u ON u.id=t.tester_id
         WHERE t.issue_id=? ORDER BY t.id'
    );
    $issueId = (int)$issue['id'];
    $stmt->bind_param('i', $issueId);
    $stmt->execute();
    $testResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return json_encode([
        'title' => $issue['title'],
        'description' => $issue['description'],
        'repro_steps' => $issue['repro_steps'],
        'expected_result' => $issue['expected_result'],
        'actual_result' => $issue['actual_result'],
        'severity' => $issue['severity'],
        'priority' => $issue['priority'],
        'status' => $issue['status'],
        'assignee_id' => $issue['assignee_id'],
        'commit_url' => $issue['commit_url'],
        'test_results' => $testResults,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
