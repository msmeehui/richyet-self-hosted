-- Test fixture only. Production uses server/schema.mysql.sql and InnoDB.
PRAGMA foreign_keys = ON;
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT, created_at INTEGER NOT NULL);
CREATE TABLE portfolios (owner_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, revision INTEGER NOT NULL DEFAULT 0, holdings_json TEXT NOT NULL, updated_at INTEGER NOT NULL);
CREATE TABLE sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, csrf_token TEXT NOT NULL, created_at INTEGER NOT NULL, last_seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE password_tokens (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE, expires_at INTEGER NOT NULL);
CREATE TABLE rate_limits (bucket_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE market_accounts (owner_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, registry_json TEXT NOT NULL, snapshot_json TEXT NOT NULL, state_json TEXT NOT NULL, updated_at INTEGER NOT NULL);
CREATE TABLE market_usage (provider TEXT NOT NULL, window_start INTEGER NOT NULL, calls INTEGER NOT NULL, PRIMARY KEY (provider, window_start));
CREATE TABLE market_catalogues (id TEXT PRIMARY KEY, audience TEXT NOT NULL, registry_json TEXT NOT NULL, snapshot_json TEXT NOT NULL, state_json TEXT NOT NULL, policy_json TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL);
CREATE TABLE market_catalogue_accounts (owner_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, catalogue_id TEXT NOT NULL REFERENCES market_catalogues(id), created_at INTEGER NOT NULL);
CREATE TABLE market_directory (catalogue_id TEXT NOT NULL REFERENCES market_catalogues(id) ON DELETE CASCADE, instrument_id TEXT NOT NULL, name_key TEXT NOT NULL, symbol_key TEXT NOT NULL, isin_key TEXT NOT NULL, instrument_json TEXT NOT NULL, policy_json TEXT NOT NULL, preparation_json TEXT NOT NULL, attempted_at INTEGER NOT NULL DEFAULT 0, retry_after INTEGER NOT NULL DEFAULT 0, touched_at INTEGER NOT NULL DEFAULT 0, lease_token TEXT, lease_until INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(catalogue_id,instrument_id));
CREATE INDEX directory_name ON market_directory(catalogue_id,name_key);
CREATE INDEX directory_symbol ON market_directory(catalogue_id,symbol_key);
CREATE INDEX directory_isin ON market_directory(catalogue_id,isin_key);
CREATE TABLE market_directory_tokens (catalogue_id TEXT NOT NULL, token TEXT NOT NULL, instrument_id TEXT NOT NULL, PRIMARY KEY(catalogue_id,token,instrument_id), FOREIGN KEY(catalogue_id,instrument_id) REFERENCES market_directory(catalogue_id,instrument_id) ON DELETE CASCADE);
CREATE INDEX directory_token_listing ON market_directory_tokens(catalogue_id,instrument_id);
