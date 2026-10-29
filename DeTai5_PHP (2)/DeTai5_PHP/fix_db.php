<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
page_header('Cập nhật cơ sở dữ liệu');
?>
<section class="panel">
<div class="alert">Không import <code>database.sql</code> chồng lên dữ liệu hiện tại. Migration tương thích được chạy từ PowerShell bằng <code>C:\xampp\php\php.exe upgrade_db.php</code>.</div>
<p>Migration giữ dữ liệu hiện tại, bổ sung luồng kiểm thử, kiểm soát phiên bản và snapshot phát hành. Hãy dừng các phiên làm việc đang chỉnh sửa dữ liệu trong lúc chạy migration.</p>
<p><a class="button" href="README.md">Xem hướng dẫn cài đặt</a></p>
</section>
<?php page_footer(); ?>
