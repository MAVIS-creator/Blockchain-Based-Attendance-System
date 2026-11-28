-- Apply before using the transactional attendance writer. The existing
-- bbas_attendance_events table remains compatible with historical imports.
CREATE TABLE IF NOT EXISTS bbas_attendance_receipts (
  receipt_key BINARY(32) NOT NULL,
  request_hash BINARY(32) NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (receipt_key),
  UNIQUE KEY uq_receipt_event (event_id),
  CONSTRAINT fk_receipt_event FOREIGN KEY (event_id) REFERENCES bbas_attendance_events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bbas_attendance_device_claims (
  event_day DATE NOT NULL,
  course VARCHAR(191) NOT NULL,
  action ENUM('checkin','checkout') NOT NULL,
  identity_kind ENUM('device','token') NOT NULL,
  identity_hash BINARY(32) NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (event_day, course, action, identity_kind, identity_hash),
  KEY idx_device_claim_event (event_id),
  CONSTRAINT fk_device_claim_event FOREIGN KEY (event_id) REFERENCES bbas_attendance_events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bbas_attendance_outbox (
  event_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processing','done','dead') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  lease_until DATETIME(6) NULL,
  lease_token CHAR(32) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (event_id),
  KEY idx_attendance_outbox_ready (status, available_at),
  KEY idx_attendance_outbox_lease (status, lease_until),
  CONSTRAINT fk_outbox_event FOREIGN KEY (event_id) REFERENCES bbas_attendance_events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
