CREATE DATABASE IF NOT EXISTS issue_tracker_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE issue_tracker_db;

CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(80) NOT NULL DEFAULT 'user',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projects (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  manager_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS project_members (
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'Developer',
  PRIMARY KEY (project_id,user_id),
  CONSTRAINT fk_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issues (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  repro_steps TEXT NULL,
  expected_result TEXT NULL,
  actual_result TEXT NULL,
  severity VARCHAR(50) NOT NULL DEFAULT 'Medium',
  priority VARCHAR(50) NOT NULL DEFAULT 'Medium',
  status VARCHAR(50) NOT NULL DEFAULT 'Open',
  assignee_id INT NULL,
  created_by INT NOT NULL,
  commit_url VARCHAR(500) NULL,
  lock_version INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_issues_project_status (project_id,status),
  KEY idx_issues_assignee (assignee_id),
  CONSTRAINT fk_issues_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_issues_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_issues_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dependencies (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  depends_on_issue_id INT NOT NULL,
  UNIQUE KEY uq_dependency_edge (issue_id,depends_on_issue_id),
  CONSTRAINT fk_dependency_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_dependency_target FOREIGN KEY (depends_on_issue_id) REFERENCES issues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comments_issue (issue_id,created_at),
  CONSTRAINT fk_comments_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_results (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  tester_id INT NOT NULL,
  result VARCHAR(20) NOT NULL,
  notes TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_test_issue (issue_id,created_at),
  CONSTRAINT fk_test_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_test_tester FOREIGN KEY (tester_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS releases (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  version_name VARCHAR(100) NOT NULL,
  name VARCHAR(160) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'Draft',
  planned_date DATE NOT NULL,
  release_date DATE NULL,
  created_by INT NOT NULL,
  checked_at DATETIME NULL,
  finalized_at DATETIME NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_releases_project (project_id),
  CONSTRAINT fk_releases_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_releases_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS release_items (
  release_id INT NOT NULL,
  issue_id INT NOT NULL,
  snapshot_title VARCHAR(255) NULL,
  snapshot_status VARCHAR(50) NULL,
  snapshot_json LONGTEXT NULL,
  PRIMARY KEY (release_id,issue_id),
  CONSTRAINT fk_release_items_release FOREIGN KEY (release_id) REFERENCES releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_items_issue FOREIGN KEY (issue_id) REFERENCES issues(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (id,username,name,password_hash,role) VALUES
  (1,'u1','Nguyễn Văn A','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','Quản lý dự án'),
  (2,'u2','Trần Văn B','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','Nhà phát triển'),
  (3,'u3','Lê Thị C','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','Kiểm thử'),
  (4,'u4','Phạm Văn D','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','Nhà phát triển'),
  (5,'u5','Hoàng Văn E','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','Phụ trách phát hành'),
  (6,'admin','Quản trị hệ thống','$2y$10$AGaHgJTTribMAhKo2NgQKeVcvO5oF/A/P780gNGaSwTcZA4YcOtWa','admin');

INSERT IGNORE INTO projects (id,name,description,manager_id) VALUES
  (1,'Web Platform','Quản lý lỗi và kế hoạch phát hành nền tảng web.',1),
  (2,'Mobile App','Quản lý lỗi và kế hoạch phát hành ứng dụng di động.',2);

INSERT IGNORE INTO project_members (project_id,user_id,role) VALUES
  (1,1,'Manager'),(1,2,'Developer'),(1,4,'Developer'),(1,5,'Release Manager'),
  (1,3,'Tester'),
  (2,2,'Manager'),(2,3,'Tester'),(2,4,'Developer'),(2,5,'Release Manager');

INSERT IGNORE INTO issues
  (id,project_id,title,description,severity,priority,status,assignee_id,created_by)
VALUES
  (1,1,'Không đăng nhập được khi mật khẩu có ký tự đặc biệt','Đăng nhập thất bại với một số ký tự hợp lệ.','Critical','Blocking','Open',2,1),
  (2,1,'Thiết lập cấu hình xác thực','Chuẩn hóa cấu hình xác thực dùng chung.','High','High','In Progress',4,1),
  (3,1,'Tạo endpoint đăng nhập','Endpoint kiểm tra thông tin đăng nhập.','High','High','Open',2,1),
  (4,1,'Bổ sung kiểm tra đầu vào đăng nhập','Kiểm tra dữ liệu trước khi xác thực.','Medium','Medium','Open',4,1),
  (5,1,'Lưu phiên đăng nhập','Quản lý vòng đời phiên người dùng.','Medium','Medium','Open',2,1),
  (6,1,'Hiển thị lỗi xác thực dễ hiểu','Cải thiện thông báo khi đăng nhập lỗi.','Low','Low','Open',4,1),
  (7,1,'Trang danh sách công việc','Hiển thị công việc theo dự án.','Medium','Medium','Open',2,1),
  (8,1,'Lọc công việc theo trạng thái','Bổ sung bộ lọc trạng thái.','Low','Low','Open',4,1),
  (9,1,'Phân công người xử lý','Cho phép giao việc cho thành viên dự án.','Medium','Medium','Open',2,1),
  (10,1,'Ghi nhận mức ưu tiên','Hỗ trợ mức ưu tiên khẩn cấp.','Low','Low','Open',4,1),
  (11,1,'Nhật ký bình luận công việc','Lưu trao đổi theo từng công việc.','Medium','Medium','Open',2,1),
  (12,1,'Lưu kết quả kiểm thử lại','Ghi nhận kết quả sau khi sửa lỗi.','High','High','Open',4,1),
  (13,1,'Hiển thị việc đang chặn','Cho biết các phụ thuộc chưa hoàn thành.','Medium','Medium','Open',2,1),
  (14,1,'Kiểm tra quyền thành viên dự án','Giới hạn truy cập theo dự án.','Critical','Blocking','Open',4,1),
  (15,1,'Tạo kế hoạch phát hành','Tạo phiên bản theo dự án.','Medium','Medium','Open',2,1),
  (16,1,'Chốt danh sách thay đổi','Lưu ảnh chụp công việc của phiên bản.','High','High','Open',4,1),
  (17,1,'Xuất ghi chú phát hành','Xuất danh sách thay đổi đã chốt.','Low','Low','Open',2,1),
  (18,1,'Phát hiện sửa đồng thời','Ngăn ghi đè khi dữ liệu nền thay đổi.','High','High','Open',4,1),
  (19,1,'Liên kết commit sửa lỗi','Lưu liên kết commit hoặc PR.','Low','Low','Open',2,1),
  (20,1,'Tối ưu trang tổng quan','Hiển thị tiến độ dự án gần đây.','Medium','Medium','Open',4,1),
  (21,2,'Ứng dụng đóng khi mở hồ sơ','Ứng dụng thoát khi tải một hồ sơ đặc biệt.','High','High','Resolved',4,2),
  (22,2,'Tối ưu tải danh sách hồ sơ','Giảm thời gian tải dữ liệu hồ sơ.','Medium','Medium','In Progress',2,2),
  (23,2,'Đăng nhập ứng dụng di động','Thêm luồng đăng nhập người dùng.','High','High','Open',4,2),
  (24,2,'Làm mới access token','Tự động làm mới phiên.','High','High','Open',2,2),
  (25,2,'Lưu hồ sơ ngoại tuyến','Hiển thị hồ sơ đã lưu khi mất mạng.','Medium','Medium','Open',4,2),
  (26,2,'Đồng bộ trạng thái trực tuyến','Đồng bộ dữ liệu khi có mạng trở lại.','Medium','Medium','Open',2,2),
  (27,2,'Thông báo lỗi mạng','Hiển thị lỗi mạng thân thiện.','Low','Low','Open',4,2),
  (28,2,'Kiểm tra đầu vào hồ sơ','Xác thực dữ liệu nhập hồ sơ.','Medium','Medium','Open',2,2),
  (29,2,'Phân trang danh sách','Tải danh sách theo từng trang.','Low','Low','Open',4,2),
  (30,2,'Tìm kiếm hồ sơ','Tìm nhanh hồ sơ theo tên.','Medium','Medium','Open',2,2),
  (31,2,'Bộ lọc trạng thái hồ sơ','Lọc hồ sơ theo trạng thái.','Low','Low','Open',4,2),
  (32,2,'Kiểm thử lại lỗi đóng ứng dụng','Xác minh bản sửa lỗi đóng ứng dụng.','High','High','Open',3,2),
  (33,2,'Tối ưu ảnh hồ sơ','Giảm dung lượng ảnh tải về.','Medium','Medium','Open',4,2),
  (34,2,'Cập nhật quyền truy cập','Giới hạn tính năng theo quyền.','Critical','Blocking','Open',2,2),
  (35,2,'Bổ sung nhật ký hoạt động','Lưu lịch sử cập nhật hồ sơ.','Low','Low','Open',4,2),
  (36,2,'Ghi nhận kết quả kiểm thử di động','Lưu kết quả kiểm thử trên thiết bị.','Medium','Medium','Open',3,2),
  (37,2,'Chuẩn bị ghi chú phiên bản','Tổng hợp thay đổi của ứng dụng.','Low','Low','Open',4,2),
  (38,2,'Phát hiện xung đột cập nhật','Không ghi đè chỉnh sửa mới hơn.','High','High','Open',2,2),
  (39,2,'Hiển thị tiến độ đồng bộ','Cho biết trạng thái đồng bộ.','Low','Low','Open',4,2),
  (40,2,'Kiểm tra phát hành ứng dụng','Kiểm tra các điều kiện trước phát hành.','High','High','Open',3,2);

INSERT IGNORE INTO dependencies (issue_id,depends_on_issue_id) VALUES
  (3,2),(2,1),(4,3),(5,3),(6,4),(8,7),(9,7),(10,9),
  (12,11),(13,12),(16,15),(17,16),(24,23),(26,25),(32,21);

INSERT IGNORE INTO releases
  (id,project_id,version_name,name,status,planned_date,release_date,created_by,checked_at,finalized_at,notes)
VALUES
  (1,1,'v1.0.0','Web Platform 1.0','Draft','2026-12-01',NULL,5,NULL,NULL,'Bản phát hành đầu tiên'),
  (2,1,'v1.1.0','Web Platform 1.1','Draft','2027-01-15',NULL,5,NULL,NULL,'Cải tiến quản lý công việc'),
  (3,2,'v1.0.0','Mobile App 1.0','Released','2026-09-15','2026-09-15',5,'2026-09-15 10:00:00','2026-09-15 10:00:00','Bản phát hành di động đầu tiên');

INSERT IGNORE INTO release_items (release_id,issue_id,snapshot_title,snapshot_status,snapshot_json) VALUES
  (1,1,NULL,NULL,NULL),(1,2,NULL,NULL,NULL),(1,3,NULL,NULL,NULL),(1,4,NULL,NULL,NULL),
  (2,15,NULL,NULL,NULL),(2,16,NULL,NULL,NULL),(2,17,NULL,NULL,NULL),
  (3,21,'Ứng dụng đóng khi mở hồ sơ','Resolved','{"title":"Ứng dụng đóng khi mở hồ sơ","description":"Ứng dụng thoát khi tải một hồ sơ đặc biệt.","severity":"High","priority":"High","status":"Resolved","assignee_id":4,"commit_url":null,"test_results":[]}'),
  (3,22,'Tối ưu tải danh sách hồ sơ','In Progress','{"title":"Tối ưu tải danh sách hồ sơ","description":"Giảm thời gian tải dữ liệu hồ sơ.","severity":"Medium","priority":"Medium","status":"In Progress","assignee_id":2,"commit_url":null,"test_results":[]}');

INSERT INTO projects(name,description,manager_id)
SELECT 'Cổng thanh toán trực tuyến','Theo dõi lỗi và công việc tích hợp thanh toán trực tuyến.',u.id
FROM users u WHERE u.username='u1'
  AND NOT EXISTS (SELECT 1 FROM projects WHERE name='Cổng thanh toán trực tuyến');
INSERT INTO projects(name,description,manager_id)
SELECT 'Quản lý kho và đơn hàng','Theo dõi lỗi và công việc quản lý tồn kho, nhập xuất và đơn hàng.',u.id
FROM users u WHERE u.username='u1'
  AND NOT EXISTS (SELECT 1 FROM projects WHERE name='Quản lý kho và đơn hàng');

INSERT IGNORE INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,'Manager' FROM projects p JOIN users u ON u.username='u1'
WHERE p.name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng');
INSERT IGNORE INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,'Developer' FROM projects p JOIN users u ON u.username='u2'
WHERE p.name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng');
INSERT IGNORE INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,'Tester' FROM projects p JOIN users u ON u.username='u3'
WHERE p.name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng');
INSERT IGNORE INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,'Release Manager' FROM projects p JOIN users u ON u.username='u5'
WHERE p.name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng');

INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Thiết lập cấu hình cổng thanh toán','Khai báo môi trường và thông tin kết nối thanh toán.','High','High','In Progress',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Thiết lập cấu hình cổng thanh toán');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Xác thực chữ ký giao dịch','Từ chối callback có chữ ký không hợp lệ.','Critical','Blocking','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Xác thực chữ ký giao dịch');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Xử lý callback thanh toán','Cập nhật trạng thái đơn hàng từ cổng thanh toán.','High','High','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Xử lý callback thanh toán');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Chống ghi nhận giao dịch trùng','Đảm bảo callback gửi lại không tạo giao dịch trùng.','High','Urgent','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Chống ghi nhận giao dịch trùng');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Lưu nhật ký giao dịch','Tra cứu được lịch sử yêu cầu và phản hồi thanh toán.','Medium','Medium','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Lưu nhật ký giao dịch');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Hiển thị kết quả thanh toán','Thông báo rõ trạng thái thành công hoặc thất bại.','Medium','Medium','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u3' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Hiển thị kết quả thanh toán');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Kiểm thử hoàn tiền','Xác minh luồng hoàn tiền và số tiền hoàn.','High','High','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u3' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Kiểm thử hoàn tiền');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Chuẩn bị kiểm tra phát hành','Rà soát cấu hình và các điều kiện trước khi phát hành.','Medium','Medium','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u5' JOIN users m ON m.username='u1'
WHERE p.name='Cổng thanh toán trực tuyến' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Chuẩn bị kiểm tra phát hành');

INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Thiết kế quy trình nhập kho','Ghi nhận lô hàng nhập và người thực hiện.','Medium','Medium','In Progress',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Thiết kế quy trình nhập kho');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Kiểm tra số lượng tồn kho','Đối chiếu tồn kho trước khi xác nhận giao dịch.','Critical','Blocking','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Kiểm tra số lượng tồn kho');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Cập nhật tồn kho khi nhập hàng','Tăng tồn kho sau khi phiếu nhập được xác nhận.','High','High','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Cập nhật tồn kho khi nhập hàng');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Trừ tồn kho khi tạo đơn','Giảm tồn kho đúng một lần khi đơn hàng được xác nhận.','High','Urgent','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Trừ tồn kho khi tạo đơn');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Ngăn tồn kho âm','Từ chối giao dịch làm số lượng tồn kho nhỏ hơn không.','Critical','Blocking','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u2' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Ngăn tồn kho âm');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Tra cứu lịch sử xuất nhập','Hiển thị lịch sử biến động theo sản phẩm.','Medium','Medium','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u3' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Tra cứu lịch sử xuất nhập');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Thông báo hàng sắp hết','Cảnh báo khi số lượng chạm ngưỡng tối thiểu.','Low','Low','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u3' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Thông báo hàng sắp hết');
INSERT INTO issues(project_id,title,description,severity,priority,status,assignee_id,created_by)
SELECT p.id,'Kiểm thử đối soát đơn hàng','Đối chiếu số lượng đơn hàng và tồn kho trước phát hành.','High','High','Open',a.id,m.id
FROM projects p JOIN users a ON a.username='u5' JOIN users m ON m.username='u1'
WHERE p.name='Quản lý kho và đơn hàng' AND NOT EXISTS (SELECT 1 FROM issues WHERE project_id=p.id AND title='Kiểm thử đối soát đơn hàng');

INSERT IGNORE INTO dependencies(issue_id,depends_on_issue_id)
SELECT i.id,d.id FROM issues i JOIN issues d ON d.project_id=i.project_id
WHERE (i.title='Xác thực chữ ký giao dịch' AND d.title='Thiết lập cấu hình cổng thanh toán')
   OR (i.title='Xử lý callback thanh toán' AND d.title='Xác thực chữ ký giao dịch')
   OR (i.title='Chống ghi nhận giao dịch trùng' AND d.title='Xử lý callback thanh toán')
   OR (i.title='Hiển thị kết quả thanh toán' AND d.title='Xử lý callback thanh toán')
   OR (i.title='Kiểm thử hoàn tiền' AND d.title='Chống ghi nhận giao dịch trùng')
   OR (i.title='Kiểm tra số lượng tồn kho' AND d.title='Thiết kế quy trình nhập kho')
   OR (i.title='Cập nhật tồn kho khi nhập hàng' AND d.title='Kiểm tra số lượng tồn kho')
   OR (i.title='Trừ tồn kho khi tạo đơn' AND d.title='Kiểm tra số lượng tồn kho')
   OR (i.title='Ngăn tồn kho âm' AND d.title='Trừ tồn kho khi tạo đơn')
   OR (i.title='Thông báo hàng sắp hết' AND d.title='Cập nhật tồn kho khi nhập hàng')
   OR (i.title='Kiểm thử đối soát đơn hàng' AND d.title='Ngăn tồn kho âm');

INSERT INTO releases(project_id,version_name,name,status,planned_date,created_by,notes)
SELECT p.id,'v1.0.0',CONCAT(p.name,' 1.0'),'Draft',DATE_ADD(CURDATE(),INTERVAL 30 DAY),u.id,
       'Phiên bản mẫu để kiểm tra phụ thuộc và điều kiện phát hành.'
FROM projects p JOIN users u ON u.username='u1'
WHERE p.name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng')
  AND NOT EXISTS (SELECT 1 FROM releases r WHERE r.project_id=p.id AND r.version_name='v1.0.0');
INSERT IGNORE INTO release_items(release_id,issue_id)
SELECT r.id,i.id FROM releases r JOIN issues i ON i.project_id=r.project_id
WHERE r.version_name='v1.0.0'
  AND r.project_id IN (SELECT id FROM projects WHERE name IN ('Cổng thanh toán trực tuyến','Quản lý kho và đơn hàng'))
  AND i.title IN ('Thiết lập cấu hình cổng thanh toán','Xác thực chữ ký giao dịch',
                  'Xử lý callback thanh toán','Chống ghi nhận giao dịch trùng',
                  'Thiết kế quy trình nhập kho','Kiểm tra số lượng tồn kho',
                  'Cập nhật tồn kho khi nhập hàng','Trừ tồn kho khi tạo đơn');

INSERT IGNORE INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,'Tester' FROM projects p JOIN users u ON u.username='u3'
WHERE p.name='Web Platform';

UPDATE issues SET status='Resolved'
WHERE (project_id=1 AND title IN (
  'Trang danh sách công việc','Lọc công việc theo trạng thái','Phân công người xử lý',
  'Ghi nhận mức ưu tiên','Tối ưu trang tổng quan'
)) OR (project_id=2 AND title IN (
  'Thông báo lỗi mạng','Phân trang danh sách','Bộ lọc trạng thái hồ sơ',
  'Tối ưu ảnh hồ sơ','Bổ sung nhật ký hoạt động'
));
UPDATE issues i JOIN projects p ON p.id=i.project_id
SET i.status='Resolved'
WHERE (p.name='Cổng thanh toán trực tuyến' AND i.title IN (
  'Lưu nhật ký giao dịch','Chuẩn bị kiểm tra phát hành'
)) OR (p.name='Quản lý kho và đơn hàng' AND i.title IN (
  'Tra cứu lịch sử xuất nhập','Thông báo hàng sắp hết'
));

INSERT INTO test_results(issue_id,tester_id,result,notes)
SELECT i.id,u.id,'Passed','Kiểm thử mẫu: chức năng hoạt động đúng theo yêu cầu.'
FROM issues i
JOIN projects p ON p.id=i.project_id
JOIN users u ON u.username='u3'
LEFT JOIN test_results t ON t.issue_id=i.id
WHERE i.status='Resolved' AND t.id IS NULL
  AND (
    (p.name='Web Platform' AND i.title IN (
      'Trang danh sách công việc','Lọc công việc theo trạng thái','Phân công người xử lý',
      'Ghi nhận mức ưu tiên','Tối ưu trang tổng quan'
    ))
    OR (p.name='Mobile App' AND i.title IN (
      'Thông báo lỗi mạng','Phân trang danh sách','Bộ lọc trạng thái hồ sơ',
      'Tối ưu ảnh hồ sơ','Bổ sung nhật ký hoạt động'
    ))
    OR (p.name='Cổng thanh toán trực tuyến' AND i.title IN (
      'Lưu nhật ký giao dịch','Chuẩn bị kiểm tra phát hành'
    ))
    OR (p.name='Quản lý kho và đơn hàng' AND i.title IN (
      'Tra cứu lịch sử xuất nhập','Thông báo hàng sắp hết'
    ))
  );
