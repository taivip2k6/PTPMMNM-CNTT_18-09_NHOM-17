<?php
$host = '127.0.0.1';
$username = 'communitypass_mysql_v5';
$password = '110106';
$database = 'issue_tracker_db';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>
