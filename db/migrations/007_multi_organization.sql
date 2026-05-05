CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizations_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (name, slug, is_active)
VALUES ('Genérica', 'generica', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active);

UPDATE organizations
SET name = 'Genérica', slug = 'generica', is_active = 1
WHERE slug = 'default'
  AND NOT EXISTS (
    SELECT 1
    FROM (SELECT id FROM organizations WHERE slug = 'generica') AS existing_generic
  );

ALTER TABLE users
  ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER email;

UPDATE users
SET organization_id = (SELECT id FROM organizations WHERE slug = 'generica' LIMIT 1)
WHERE organization_id IS NULL OR organization_id = 0;

ALTER TABLE users
  MODIFY organization_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE users
  ADD KEY idx_users_organization (organization_id);

ALTER TABLE user_product_roles
  ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER user_id;

UPDATE user_product_roles
SET organization_id = (SELECT id FROM organizations WHERE slug = 'generica' LIMIT 1)
WHERE organization_id IS NULL OR organization_id = 0;

DELETE duplicate_role
FROM user_product_roles duplicate_role
INNER JOIN user_product_roles kept_role
  ON kept_role.user_id = duplicate_role.user_id
 AND kept_role.product_id = duplicate_role.product_id
 AND kept_role.id < duplicate_role.id;

UPDATE user_product_roles upr
INNER JOIN users u ON u.id = upr.user_id
SET upr.organization_id = u.organization_id
WHERE upr.organization_id <> u.organization_id;

ALTER TABLE user_product_roles
  MODIFY organization_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE user_product_roles
  DROP INDEX uq_user_product_roles_user_product;

ALTER TABLE user_product_roles
  ADD UNIQUE KEY uq_user_org_product_roles (user_id, organization_id, product_id),
  ADD KEY idx_user_product_roles_org (organization_id);
