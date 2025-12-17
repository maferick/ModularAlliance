ALTER TABLE universe_entities
MODIFY entity_type ENUM(
  'system',
  'constellation',
  'region',
  'type',
  'group',
  'category'
) NOT NULL;
