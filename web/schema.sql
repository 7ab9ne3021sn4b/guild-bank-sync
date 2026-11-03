-- Guild Bank Sync database schema (MySQL / MariaDB).
-- Import this once through phpMyAdmin (or the mysql CLI) on your hosting.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discord_id VARCHAR(32) NOT NULL UNIQUE,
  username VARCHAR(100) NOT NULL,
  avatar VARCHAR(100) NULL,
  character_name VARCHAR(50) NULL,
  roles TEXT NULL,
  access_token VARCHAR(255) NULL,
  refresh_token VARCHAR(255) NULL,
  token_expires INT NOT NULL DEFAULT 0,
  roles_checked_at INT NOT NULL DEFAULT 0,
  created_at INT NOT NULL,
  last_seen_at INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_tabs (
  tab_index INT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(100) NULL,
  updated_at INT NOT NULL,
  updated_by VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_items (
  tab_index INT NOT NULL,
  slot INT NOT NULL,
  item_id INT NOT NULL,
  name VARCHAR(150) NULL,
  quality INT NULL,
  count INT NOT NULL DEFAULT 1,
  PRIMARY KEY (tab_index, slot),
  KEY idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tab_index INT NOT NULL,            -- 0 = money log
  type VARCHAR(30) NOT NULL,
  player VARCHAR(50) NOT NULL,
  item_id INT NULL,
  item_name VARCHAR(150) NULL,
  quality INT NULL,
  count INT NULL,
  from_tab INT NULL,
  to_tab INT NULL,
  copper BIGINT NULL,
  approx_time INT NOT NULL,          -- unix time, accurate to about an hour
  fingerprint CHAR(40) NOT NULL,
  created_at INT NOT NULL,
  KEY idx_fp (fingerprint, approx_time),
  KEY idx_time (approx_time),
  KEY idx_player (player)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  character_name VARCHAR(50) NOT NULL,
  item_id INT NOT NULL,
  item_name VARCHAR(150) NULL,
  quality INT NULL,
  quantity INT NOT NULL,
  fulfilled_qty INT NOT NULL DEFAULT 0,
  note VARCHAR(300) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, denied, fulfilled, cancelled
  decided_by INT NULL,
  decision_note VARCHAR(300) NULL,
  decided_at INT NULL,
  fulfilled_at INT NULL,
  created_at INT NOT NULL,
  KEY idx_status (status),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scanner VARCHAR(50) NULL,
  scanned_at INT NOT NULL,
  received_at INT NOT NULL,
  tabs_updated INT NOT NULL DEFAULT 0,
  new_transactions INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(50) PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Officers' personal sync keys (also created automatically by the site if missing).
CREATE TABLE IF NOT EXISTS sync_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  key_hash CHAR(64) NOT NULL,
  key_hint VARCHAR(8) NOT NULL,
  created_at INT NOT NULL,
  last_used_at INT NULL,
  revoked_at INT NULL,
  KEY idx_sync_hash (key_hash),
  KEY idx_sync_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
