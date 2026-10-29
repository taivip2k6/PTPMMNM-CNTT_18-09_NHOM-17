# BugTrack — Quản lý công việc, lỗi và phát hành

Ứng dụng PHP 8.1+, MySQL 8.0.16+, MySQLi chạy trên XAMPP. Mỗi công việc thuộc một dự án; thành viên chỉ xem và thao tác dữ liệu của các dự án họ tham gia.

## Cài đặt / nâng cấp bằng XAMPP

1. Bật Apache và MySQL trong XAMPP.
2. Kiểm tra `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` trong `config.php`.
3. Nếu đang dùng database cũ, chạy migration bổ sung an toàn từ thư mục dự án:

   ```powershell
   C:\xampp\php\php.exe upgrade_db.php
   ```

   Migration giữ nguyên ID và dữ liệu hiện có, bổ sung trường/bảng mới, chuyển mật khẩu cũ sang hash, giữ snapshot phát hành hiện có và thêm dữ liệu mẫu còn thiếu để đạt 40 công việc, 15 phụ thuộc, 3 phiên bản.
4. Nếu cài mới hoàn toàn, import `database.sql` bằng phpMyAdmin.
5. Truy cập `http://localhost/DeTai5_PHP/`.

Không import `database.sql` chồng lên các bảng cũ; file này dành cho database mới.
Tài khoản demo: `u1` đến `u5`, mật khẩu `Admin@123`. Tài khoản quản trị hệ thống: `admin` / `Admin@123`. Chỉ dùng mật khẩu mẫu trong môi trường học tập; hãy đặt lại ngay trước khi đưa ứng dụng lên mạng. Migration tạo tài khoản `admin` nếu chưa có và không thay mật khẩu một tài khoản `admin` đã tồn tại.

SQL mẫu tạo 2 dự án, 40 công việc, 15 quan hệ phụ thuộc và 3 phiên bản phát hành. Dữ liệu có một phiên bản đã chốt để thử tra cứu snapshot; phiên bản WEB `v1.0.0` cố ý chứa lỗi Critical chưa kiểm thử đạt để thử cổng phát hành.

## Luồng sử dụng

### Ghi nhận và xử lý lỗi

1. Mở **Công việc** để tạo lỗi/công việc trong một dự án mà tài khoản là thành viên.
2. Ghi mô tả, bước tái hiện, kết quả mong đợi/thực tế, mức độ, ưu tiên, người phụ trách và liên kết commit/PR.
3. Mở chi tiết công việc để khai báo các công việc phải hoàn thành trước. Lưu bị từ chối nếu phát sinh vòng phụ thuộc; thông báo chỉ rõ chuỗi mã công việc gây vòng.
4. Cập nhật trạng thái, trao đổi qua bình luận và ghi kết quả kiểm tra `Passed`, `Failed` hoặc `Blocked`. Kết quả kiểm tra được lưu thành lịch sử có người thực hiện và thời gian. Chỉ Quản lý dự án hoặc Kiểm thử được ghi kết quả.
5. Mỗi lần lưu công việc so sánh `lock_version`; nếu người khác đã lưu trước đó, lượt lưu cũ báo xung đột và không ghi đè.

### Chốt phiên bản phát hành

1. Tạo phiên bản, mở **Chi tiết**, chọn danh sách công việc thuộc dự án.
2. Hệ thống liệt kê các phụ thuộc chưa hoàn thành và chặn lỗi `Critical` hoặc ưu tiên `Blocking` nếu kết quả kiểm thử mới nhất chưa phải `Passed`, hoặc kết quả đạt được ghi trước lần cập nhật công việc gần nhất.
3. Lưu danh sách dự kiến, sau đó chọn **Kiểm tra điều kiện và chốt phát hành**. Điều kiện được kiểm tra lại tại máy chủ trong transaction.
4. Khi chốt, các trường công việc và lịch sử kiểm thử đến thời điểm đó được ghi vào `release_items.snapshot_json`. Sau đó không thể sửa/xóa phiên bản hoặc thay danh sách đã chốt; chỉnh sửa công việc nguồn không làm đổi snapshot.
5. **Tải ghi chú phát hành** xuất Markdown dựa trên snapshot, không đọc dữ liệu công việc hiện tại.

### Báo cáo công việc

1. Mở **Báo cáo**, chọn một dự án hoặc tất cả dự án bạn là thành viên, rồi chọn khoảng ngày.
2. Báo cáo tổng hợp công việc được tạo trong kỳ theo trạng thái, mức độ, ưu tiên và người phụ trách; đồng thời hiển thị công việc đang bị chặn bởi phụ thuộc chưa hoàn tất.
3. Tải CSV để lưu danh sách chi tiết theo đúng bộ lọc. Báo cáo và tệp xuất đều chỉ chứa dự án mà tài khoản hiện tại tham gia.

Ngày hoàn thành trong báo cáo được suy ra từ `updated_at` và trạng thái hiện tại do ứng dụng chưa lưu lịch sử chuyển trạng thái riêng; nếu một công việc được chỉnh sửa sau khi hoàn thành, thời điểm này phản ánh lần cập nhật gần nhất.

## Quyền theo vai trò dự án

Quyền thao tác được lấy từ `project_members.role` của đúng dự án; trường `users.role` là vai trò tài khoản toàn hệ thống và không tự động vượt qua ranh giới dự án.

| Vai trò | Quyền |
|---|---|
| Quản lý | Quản lý thành viên; tạo/sửa/xóa công việc; bình luận; ghi kết quả kiểm thử; quản lý phiên bản |
| Nhà phát triển | Tạo/sửa công việc; bình luận |
| Kiểm thử | Tạo công việc báo lỗi; bình luận; ghi kết quả kiểm thử |
| Phụ trách phát hành | Bình luận; tạo/sửa/xóa kế hoạch và chốt phiên bản |

Người không thuộc dự án bị từ chối tại máy chủ. Quản trị viên hệ thống vào **Quản trị** để tạo tài khoản, đặt lại mật khẩu, đổi quyền hệ thống và vô hiệu hóa/kích hoạt tài khoản. Tài khoản bị vô hiệu hóa không đăng nhập được; phiên đăng nhập đang mở cũng bị từ chối ở yêu cầu tiếp theo. Tài khoản người dùng không bị xóa vật lý để bảo toàn lịch sử công việc và phát hành. Admin hệ thống không tự động được xem dữ liệu dự án.

## Schema chính

- `users`, `projects`, `project_members`: tài khoản, dự án, thành viên và quản lý dự án.
- `issues`: lỗi/công việc, mô tả tái hiện, mức độ, ưu tiên, phân công, trạng thái và `lock_version`.
- `dependencies`: cạnh phụ thuộc giữa công việc.
- `comments`, `test_results`: bình luận và lịch sử kết quả kiểm tra.
- `releases`, `release_items`: kế hoạch phiên bản và snapshot lịch sử tại thời điểm chốt.

Các kiểm tra cần thử:

- Với chuỗi `#3 → #2 → #1`, thêm `#1 → #3`: thao tác bị từ chối và hiển thị chuỗi vòng.
- `WEB v1.0.0`: thử chốt trước khi ghi kiểm thử đạt cho lỗi #1; hệ thống phải chặn.
- Mở cùng công việc ở hai cửa sổ, lưu cửa sổ thứ nhất rồi lưu cửa sổ còn lại: cửa sổ cũ nhận thông báo xung đột.
- Đăng nhập tài khoản không thuộc dự án và thử gửi POST tới công việc dự án đó: server trả 403.
- Chốt bản phát hành thử nghiệm, sau đó sửa công việc nguồn: trang chi tiết/ghi chú của bản phát hành vẫn dùng snapshot cũ.

Chạy kiểm thử tích hợp với database đã cấu hình trong `config.php`:

```powershell
C:\xampp\php\php.exe tests\run.php
```

Bộ kiểm thử tạo dữ liệu tạm trong transaction rồi rollback; kiểm tra ma trận vai trò, thành viên dự án, chuỗi vòng phụ thuộc, kết quả kiểm thử mới nhất, kiểm thử đã cũ sau khi sửa công việc và snapshot phát hành bất biến.

## Tệp và kiểm thử

- `index.php`: tổng quan theo dự án, tiến độ, điểm nghẽn và phiên bản sắp tới.
- `reports.php`: báo cáo theo dự án/khoảng thời gian và xuất CSV.
- `workflow.php`: quyền dự án, ma trận vai trò, phát hiện vòng, điều kiện phát hành và tạo snapshot.
- `issue_form.php`, `issue_activity.php`: sửa công việc, phụ thuộc, bình luận, kết quả kiểm tra.
- `release_view.php`, `release_action.php`, `release_notes.php`: chọn nội dung, kiểm tra/chốt và xuất ghi chú.
- `database.sql`: schema và dữ liệu demo cho cài đặt mới.
- `upgrade_db.php`: migration bổ sung cho schema đang dùng, chạy bằng PHP CLI.
- `tests/run.php`: kiểm thử tích hợp luồng và các yêu cầu kỹ thuật chính.
- `user_admin.php`, `admin_action.php`: quản lý tài khoản hệ thống dành riêng cho admin.

Kiểm tra cú pháp PHP trên XAMPP:

```powershell
Get-ChildItem -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```

Biểu mẫu ghi dữ liệu dùng CSRF token. Đây vẫn là ứng dụng mẫu học tập; trước khi triển khai thực tế cần bổ sung nhật ký kiểm toán, quy trình sao lưu và chính sách phân quyền/đổi mật khẩu phù hợp.
