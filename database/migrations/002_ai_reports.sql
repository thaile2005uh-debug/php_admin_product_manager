-- AI query jobs and asynchronous Markdown/PDF reports. Apply after migration 001.
CREATE TABLE ai_jobs (
    id CHAR(36) PRIMARY KEY,
    user_id INT NOT NULL,
    job_type ENUM('question','report') NOT NULL,
    status ENUM('queued','running','succeeded','failed') NOT NULL DEFAULT 'queued',
    payload JSON NOT NULL,
    result_json JSON DEFAULT NULL,
    result_markdown MEDIUMTEXT DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_ai_jobs_user_status(user_id,status,created_at),
    FOREIGN KEY(user_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO permissions(code,name) VALUES
('ai.ask','Đặt câu hỏi dữ liệu bằng AI'),
('reports.view','Xem và tạo báo cáo Markdown/PDF');
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='ADMIN' AND p.code IN ('ai.ask','reports.view');
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='VIEWER' AND p.code IN ('ai.ask','reports.view');
INSERT INTO schema_migrations(version) VALUES ('002_ai_reports');
