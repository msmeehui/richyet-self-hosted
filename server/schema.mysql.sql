-- Apply only to a dedicated RichYet database. All tables require transactions.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS portfolios (
    owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    revision BIGINT NOT NULL DEFAULT 0,
    holdings_json MEDIUMTEXT NOT NULL,
    updated_at BIGINT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    csrf_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at BIGINT NOT NULL,
    last_seen_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts INT NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_accounts (
    owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    registry_json MEDIUMTEXT NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    state_json MEDIUMTEXT NOT NULL,
    updated_at BIGINT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_usage (
    provider VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start BIGINT NOT NULL,
    calls INT NOT NULL,
    PRIMARY KEY (provider, window_start)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_catalogues (
    id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    audience VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registry_json MEDIUMTEXT NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    state_json MEDIUMTEXT NOT NULL,
    policy_json MEDIUMTEXT NOT NULL,
    revision BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_catalogue_accounts (
    owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    catalogue_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at BIGINT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (catalogue_id) REFERENCES market_catalogues(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_directory (
    catalogue_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instrument_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name_key VARCHAR(160) NOT NULL,
    symbol_key VARCHAR(160) NOT NULL,
    isin_key VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instrument_json MEDIUMTEXT NOT NULL,
    policy_json TEXT NOT NULL,
    preparation_json MEDIUMTEXT NOT NULL,
    attempted_at BIGINT NOT NULL DEFAULT 0,
    retry_after BIGINT NOT NULL DEFAULT 0,
    touched_at BIGINT NOT NULL DEFAULT 0,
    lease_token VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (catalogue_id,instrument_id),
    INDEX directory_name (catalogue_id,name_key),
    INDEX directory_symbol (catalogue_id,symbol_key),
    INDEX directory_isin (catalogue_id,isin_key),
    FOREIGN KEY (catalogue_id) REFERENCES market_catalogues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_directory_tokens (
    catalogue_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token VARCHAR(160) NOT NULL,
    instrument_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (catalogue_id,token,instrument_id),
    INDEX directory_token_listing (catalogue_id,instrument_id),
    FOREIGN KEY (catalogue_id,instrument_id) REFERENCES market_directory(catalogue_id,instrument_id) ON DELETE CASCADE
) ENGINE=InnoDB;
