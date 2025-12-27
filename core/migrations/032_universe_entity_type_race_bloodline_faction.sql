-- Extend universe_entities.entity_type to include race, bloodline, faction
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
  'structure',
  'race',
  'bloodline',
  'faction'
) NOT NULL;
