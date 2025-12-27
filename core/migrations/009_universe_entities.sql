CREATE TABLE IF NOT EXISTS universe_entities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM(
    'character','corporation','alliance',
    'type','system','constellation','region',
    'station','structure',
    'race','bloodline','faction'
  ) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  extra_json MEDIUMTEXT NULL,
  icon_json MEDIUMTEXT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ttl_seconds INT NOT NULL DEFAULT 86400,
  UNIQUE KEY uniq_entity (entity_type, entity_id),
  KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
