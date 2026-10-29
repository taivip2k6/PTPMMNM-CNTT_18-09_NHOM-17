<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DB_HOST = '127.0.0.1';
const DB_USER = 'communitypass_mysql_v5';
const DB_PASS = '110106';
const DB_NAME = 'issue_tracker_db';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('Không thể kết nối cơ sở dữ liệu. Hãy kiểm tra MySQL và config.php.');
}