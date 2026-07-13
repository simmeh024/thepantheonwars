-- Privacy-request workflow and its permission boundary. Run once through
-- phpMyAdmin's SQL tab against the `pantheonwars` database after deployment.
-- Requests are never fulfilled automatically: this table is the reviewed
-- work queue for access, correction, erasure, portability and related rights.

CREATE TABLE IF NOT EXISTS privacy_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_user_id INT UNSIGNED DEFAULT NULL,
  requester_email VARCHAR(255) NOT NULL,
  request_type ENUM('access','rectification','erasure','portability','restriction','objection','other') NOT NULL,
  message TEXT DEFAULT NULL,
  status ENUM('submitted','identity_check','in_progress','fulfilled','partially_fulfilled','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  staff_resolution TEXT DEFAULT NULL,
  handled_by INT UNSIGNED DEFAULT NULL,
  handled_at DATETIME DEFAULT NULL,
  due_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status_due_created (status, due_at, created_at),
  KEY idx_requester_created (requester_user_id, created_at),
  CONSTRAINT fk_privacy_requests_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_privacy_requests_handler FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('privacy_requests.view', 'View Privacy Requests', 'Privacy Requests'),
  ('privacy_requests.manage', 'Manage Privacy Requests', 'Privacy Requests')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
