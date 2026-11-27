-- BBAS MySQL/MariaDB staging schema. Apply to a fresh, private database.
-- InnoDB transactions and a unique student/course/action/day constraint prevent
-- duplicate accepted attendance records when the SQL driver is activated.
CREATE TABLE IF NOT EXISTS bbas_attendance_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_day DATE NOT NULL,
  course VARCHAR(191) NOT NULL,
  matric VARCHAR(32) NOT NULL,
  action ENUM('checkin','checkout') NOT NULL,
  name VARCHAR(255) NOT NULL,
  fingerprint_hash CHAR(64) NULL,
  client_ip VARCHAR(45) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  source VARCHAR(40) NOT NULL DEFAULT 'submit',
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_action_day (event_day, course, matric, action),
  KEY idx_course_day (course, event_day),
  KEY idx_student (matric, event_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bbas_ai_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_timestamp VARCHAR(64) NOT NULL,
  status ENUM('pending','processing','done','dead') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  lease_until DATETIME(6) NULL,
  lease_token CHAR(32) NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_timestamp (ticket_timestamp),
  KEY idx_ai_ready (status,available_at),
  KEY idx_ai_lease (status,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
