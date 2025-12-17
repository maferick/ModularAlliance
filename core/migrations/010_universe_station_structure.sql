-- 010_universe_station_structure.sql
-- Extend universe_entities.entity_type to include station + structure (and keep prior values)
ALTER TABLE universe_entities
MODIFY entity_type ENUM(
  'system',
  'constellation',
  'region',
  'type',
  'group',
  'category',
  'station',
  'structure'
) NOT NULL;
