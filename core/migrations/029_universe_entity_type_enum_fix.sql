-- Fix universe_entities.entity_type enum to include all types used by Universe + modules
ALTER TABLE universe_entities
MODIFY entity_type ENUM(
  'character',
  'corporation',
  'alliance',
  'system',
  'constellation',
  'region',
  'type',
  'group',
  'category',
  'station',
  'structure'
) NOT NULL;

