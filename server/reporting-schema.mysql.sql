-- Additive D059 migration; never run automatically on a page request.
CREATE TABLE IF NOT EXISTS reporting_settings (
 owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_at BIGINT NOT NULL,
 FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reporting_state (
 state_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 value_json MEDIUMTEXT NOT NULL,
 updated_at BIGINT NOT NULL
) ENGINE=InnoDB;
