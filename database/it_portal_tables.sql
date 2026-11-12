-- IT Portal (it_portal/) tables. The portal also creates these itself on first open.
-- Access is seeded with the admin user only.

CREATE TABLE IF NOT EXISTS it_access (
  user_id INT NOT NULL PRIMARY KEY,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  pin_hash VARCHAR(255) NULL,
  pin_fails INT NOT NULL DEFAULT 0,
  pin_locked_until DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  added_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO it_access (user_id, role, added_by)
SELECT id, 'admin', NULL FROM `user` WHERE username = 'admin';

CREATE TABLE IF NOT EXISTS it_records (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(60) NULL,
  ref_code VARCHAR(40) NULL,
  parent_id INT NULL,
  due_date DATE NULL,
  data LONGTEXT NOT NULL,
  secret_enc TEXT NULL,
  secret_changed_at DATETIME NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  deleted_by INT NULL,
  KEY idx_entity (entity, deleted_at),
  KEY idx_due (due_date),
  KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS it_secret_history (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT NOT NULL,
  secret_enc TEXT NOT NULL,
  changed_by INT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_record (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS it_maintenance_logs (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT NOT NULL,
  done_at DATETIME NOT NULL,
  done_by INT NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'ok',
  note TEXT NULL,
  checklist LONGTEXT NULL,
  due_was DATE NULL,
  due_next DATE NULL,
  KEY idx_record (record_id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS it_activity (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(40) NULL,
  record_id INT NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_record (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS it_settings (
  page_key VARCHAR(40) NOT NULL PRIMARY KEY,
  settings LONGTEXT NOT NULL,
  version INT NOT NULL DEFAULT 1,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS it_settings_history (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key VARCHAR(40) NOT NULL,
  settings LONGTEXT NOT NULL,
  version INT NOT NULL,
  saved_by INT NULL,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_page (page_key, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Device photos and Library files (stored in storage/it_photos/, served only through it_portal/photo.php and file.php)
CREATE TABLE IF NOT EXISTS it_files (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'photo',
  file_name VARCHAR(80) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
  ext VARCHAR(10) NOT NULL DEFAULT 'jpg',
  sha1 CHAR(40) NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  width INT NULL,
  height INT NULL,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_record (record_id),
  KEY idx_sha1 (sha1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
