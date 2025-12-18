-- ==================================================================
-- Settings (compatible with existing schema in your DB)
-- Table: settings(`key`, `value`, updated_at)
-- ==================================================================

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Safe defaults (won't overwrite if already present)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('site.brand.name', 'killsineve.online'),
('site.identity.type', 'corporation'),
('site.identity.id', '0');
