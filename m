-- Security hardening: forced admin password change, faster product search,
-- and database-backed login rate limiting.

-- Forced password change for the default admin account.
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Faster product search at scale (the app still falls back to LIKE when the
-- fulltext index is unavailable, e.g. MariaDB without the fulltext parser).
ALTER TABLE products ADD FULLTEXT INDEX ft_products_search (name, sku);

-- Persisted login attempt counters keyed by IP + identity so rate limits
-- survive cookie clearing.
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT NOT NULL AUTO_INCREMENT,
  attempt_key VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  attempt_count INT NOT NULL DEFAULT 1,
  locked_until DATETIME DEFAULT NULL,
  last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_attempt_key (attempt_key),
  KEY idx_login_attempts_ip (ip_address),
  KEY idx_login_attempts_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
