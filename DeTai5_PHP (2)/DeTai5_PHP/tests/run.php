<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run tests from the command line.');
}

require_once dirname(__DIR__) . '/workflow.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[OK] $message\n";
}

$users = $conn->query('SELECT id FROM users ORDER BY id LIMIT 3')->fetch_all(MYSQLI_ASSOC);
if (count($users) < 3) {
    throw new RuntimeException('Tests require at least three existing user accounts.');
}
$managerId = (int)$users[0]['id'];
$testerId = (int)$users[1]['id'];
$outsiderId = (int)$users[2]['id'];
$conn->begin_transaction();

try {
    check(project_role_allows('Manager', 'issue.delete'), 'Managers can delete project issues');
    check(project_role_allows('Developer', 'issue.edit'), 'Developers can edit issues');
    check(!project_role_allows('Tester', 'issue.edit'), 'Testers cannot edit issue fields');
    check(project_role_allows('Tester', 'test.record'), 'Testers can record test results');
    check(!project_role_allows('Developer', 'test.record'), 'Developers cannot record test results');
    check(project_role_allows('Release Manager', 'release.manage'), 'Release managers can manage releases');
    check(!project_role_allows('Release Manager', 'issue.edit'), 'Release managers cannot edit issues');

    $projectName = 'Automated workflow test ' . bin2hex(random_bytes(5));
    $projectDescription = 'Temporary test project, rolled back after the run.';
    $projectStmt = $conn->prepare('INSERT INTO projects(name,description,manager_id) VALUES(?,?,?)');
    $projectStmt->bind_param('ssi', $projectName, $projectDescription, $managerId);
    $projectStmt->execute();
    $projectId = (int)$conn->insert_id;
    $memberStmt = $conn->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,?)');
    $managerRole = 'Manager';
    $memberStmt->bind_param('iis', $projectId, $managerId, $managerRole);
    $memberStmt->execute();
    $testerRole = 'Tester';
    $memberStmt->bind_param('iis', $projectId, $testerId, $testerRole);
    $memberStmt->execute();

    check(is_project_member($conn, (string)$projectId, (string)$managerId), 'Project member is recognized');
    check(!is_project_member($conn, (string)$projectId, (string)$outsiderId), 'Non-member is denied project access');
    check(project_member_role($conn, (string)$projectId, (string)$testerId) === 'Tester', 'Membership role is loaded from the project');

    $issueStmt = $conn->prepare(
        'INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
         VALUES(?,?,?, ?, ?, ?, ?, ?)'
    );
    $titleA = 'Cycle test A';
    $titleB = 'Cycle test B';
    $titleC = 'Cycle test C';
    $description = 'Temporary test issue.';
    $severity = 'Medium';
    $priority = 'Medium';
    $status = 'Open';
    $issueIds = [];
    foreach ([$titleA, $titleB, $titleC] as $title) {
        $issueStmt->bind_param(
            'isssssii',
            $projectId,
            $title,
            $description,
            $severity,
            $priority,
            $status,
            $managerId,
            $managerId
        );
        $issueStmt->execute();
        $issueIds[] = (int)$conn->insert_id;
    }
    [$issueA, $issueB, $issueC] = $issueIds;
    $edgeStmt = $conn->prepare('INSERT INTO dependencies(issue_id,depends_on_issue_id) VALUES(?,?)');
    $edgeStmt->bind_param('ii', $issueA, $issueB);
    $edgeStmt->execute();
    $edgeStmt->bind_param('ii', $issueB, $issueC);
    $edgeStmt->execute();
    $cycle = find_dependency_cycle($conn, (string)$projectId, $issueC, [$issueA]);
    $validCycles = [
        [$issueA, $issueB, $issueC, $issueA],
        [$issueB, $issueC, $issueA, $issueB],
        [$issueC, $issueA, $issueB, $issueC],
    ];
    check(in_array($cycle, $validCycles, true), 'A → B → C cycle is returned with its full path');

    $versionStmt = $conn->prepare('SELECT lock_version FROM issues WHERE id=?');
    $versionStmt->bind_param('i', $issueA);
    $versionStmt->execute();
    $baseVersion = (int)$versionStmt->get_result()->fetch_row()[0];
    $saveStmt = $conn->prepare(
        'UPDATE issues SET title=?,lock_version=lock_version+1 WHERE id=? AND lock_version=?'
    );
    $firstTitle = 'Saved by editor one';
    $saveStmt->bind_param('sii', $firstTitle, $issueA, $baseVersion);
    $saveStmt->execute();
    $firstSaveSucceeded = $saveStmt->affected_rows === 1;
    $secondTitle = 'Stale save from editor two';
    $saveStmt->bind_param('sii', $secondTitle, $issueA, $baseVersion);
    $saveStmt->execute();
    $secondSaveConflicted = $saveStmt->affected_rows === 0;
    $verifyTitleStmt = $conn->prepare('SELECT title FROM issues WHERE id=?');
    $verifyTitleStmt->bind_param('i', $issueA);
    $verifyTitleStmt->execute();
    $currentTitle = (string)$verifyTitleStmt->get_result()->fetch_row()[0];
    check(
        $firstSaveSucceeded && $secondSaveConflicted && $currentTitle === $firstTitle,
        'A stale concurrent issue edit conflicts without overwriting the first save'
    );

    $criticalIssue = $issueC;
    $conn->query("UPDATE issues SET severity='Critical' WHERE id=" . $criticalIssue);
    $version = 'test-' . bin2hex(random_bytes(3));
    $releaseName = 'Automated release gate test';
    $plannedDate = date('Y-m-d');
    $releaseNotes = 'Temporary test release.';
    $releaseStmt = $conn->prepare(
        "INSERT INTO releases(project_id,version_name,name,status,planned_date,created_by,notes)
         VALUES(?,?,?,'Draft',?,?,?)"
    );
    $releaseStmt->bind_param(
        'isssis',
        $projectId,
        $version,
        $releaseName,
        $plannedDate,
        $managerId,
        $releaseNotes
    );
    $releaseStmt->execute();
    $releaseId = (int)$conn->insert_id;
    $itemStmt = $conn->prepare('INSERT INTO release_items(release_id,issue_id) VALUES(?,?)');
    $itemStmt->bind_param('ii', $releaseId, $criticalIssue);
    $itemStmt->execute();
    check(release_gate_failures($conn, $releaseId) !== [], 'Critical issue blocks a release without a retest');

    $testStmt = $conn->prepare(
        'INSERT INTO test_results(issue_id,tester_id,result,notes) VALUES(?,?,?,?)'
    );
    $testNotes = 'Automated integration test.';
    $testResult = 'Passed';
    $testStmt->bind_param('iiss', $criticalIssue, $testerId, $testResult, $testNotes);
    $testStmt->execute();
    check(release_gate_failures($conn, $releaseId) === [], 'Latest passing retest clears the release gate');

    $testResult = 'Failed';
    $testStmt->bind_param('iiss', $criticalIssue, $testerId, $testResult, $testNotes);
    $testStmt->execute();
    check(release_gate_failures($conn, $releaseId) !== [], 'A later failed retest blocks a release despite an earlier pass');

    $testResult = 'Passed';
    $testStmt->bind_param('iiss', $criticalIssue, $testerId, $testResult, $testNotes);
    $testStmt->execute();
    sleep(1);
    $conn->query("UPDATE issues SET title='Updated after retest' WHERE id=" . $criticalIssue);
    check(release_gate_failures($conn, $releaseId) !== [], 'Issue edits invalidate an older passing retest');
    $testStmt->bind_param('iiss', $criticalIssue, $testerId, $testResult, $testNotes);
    $testStmt->execute();
    check(release_gate_failures($conn, $releaseId) === [], 'A passing retest after the latest edit clears the gate');

    $issueQuery = $conn->prepare('SELECT * FROM issues WHERE id=?');
    $issueQuery->bind_param('i', $criticalIssue);
    $issueQuery->execute();
    $snapshot = snapshot_issue($conn, $issueQuery->get_result()->fetch_assoc());
    $snapshotData = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
    $snapshotUpdate = $conn->prepare(
        'UPDATE release_items SET snapshot_json=? WHERE release_id=? AND issue_id=?'
    );
    $snapshotUpdate->bind_param('sii', $snapshot, $releaseId, $criticalIssue);
    $snapshotUpdate->execute();
    $conn->query("UPDATE issues SET title='Changed after snapshot' WHERE id=" . $criticalIssue);
    $savedSnapshotStmt = $conn->prepare(
        'SELECT snapshot_json FROM release_items WHERE release_id=? AND issue_id=?'
    );
    $savedSnapshotStmt->bind_param('ii', $releaseId, $criticalIssue);
    $savedSnapshotStmt->execute();
    $savedSnapshot = json_decode(
        (string)$savedSnapshotStmt->get_result()->fetch_assoc()['snapshot_json'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    check(
        $savedSnapshot['title'] === $snapshotData['title']
            && $savedSnapshot['title'] !== 'Changed after snapshot',
        'Saved release snapshot remains unchanged when the source issue changes'
    );
} finally {
    $conn->rollback();
}

echo "All integration checks passed.\n";
