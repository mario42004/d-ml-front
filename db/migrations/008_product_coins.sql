CREATE TABLE IF NOT EXISTS product_coin_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  balance INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_coin_wallets_user_product (user_id, product_id),
  KEY idx_product_coin_wallets_product (product_id),
  CONSTRAINT fk_product_coin_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_wallets_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_coin_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  amount INT NOT NULL,
  balance_after INT NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  source_type VARCHAR(80) NULL,
  source_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_coin_ledger_user_product_time (user_id, product_id, created_at),
  KEY idx_product_coin_ledger_actor_time (actor_user_id, created_at),
  KEY idx_product_coin_ledger_source (source_type, source_id),
  CONSTRAINT fk_product_coin_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_ledger_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_coin_wallets (user_id, product_id, balance)
SELECT u.id, p.id, 5
FROM users u
CROSS JOIN products p
LEFT JOIN product_coin_wallets w ON w.user_id = u.id AND w.product_id = p.id
WHERE p.is_active = 1
  AND w.id IS NULL;

INSERT INTO product_coin_ledger (user_id, product_id, actor_user_id, amount, balance_after, movement_type, description)
SELECT w.user_id, w.product_id, NULL, w.balance, w.balance, 'initial_grant', 'Saldo inicial de producto'
FROM product_coin_wallets w
WHERE w.balance > 0
  AND NOT EXISTS (
    SELECT 1
    FROM product_coin_ledger l
    WHERE l.user_id = w.user_id
      AND l.product_id = w.product_id
  );
