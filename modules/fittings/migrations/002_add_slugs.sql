ALTER TABLE module_fittings_categories
  ADD COLUMN IF NOT EXISTS slug VARCHAR(128) NULL AFTER id;

UPDATE module_fittings_categories
  SET slug = CONCAT('category-', id)
  WHERE slug IS NULL OR slug = '';

ALTER TABLE module_fittings_categories
  MODIFY slug VARCHAR(128) NOT NULL,
  ADD UNIQUE KEY uniq_fittings_category_slug (slug);

ALTER TABLE module_fittings_doctrines
  ADD COLUMN IF NOT EXISTS slug VARCHAR(128) NULL AFTER id;

UPDATE module_fittings_doctrines
  SET slug = CONCAT('doctrine-', id)
  WHERE slug IS NULL OR slug = '';

ALTER TABLE module_fittings_doctrines
  MODIFY slug VARCHAR(128) NOT NULL,
  ADD UNIQUE KEY uniq_fittings_doctrine_slug (slug);

ALTER TABLE module_fittings_fits
  ADD COLUMN IF NOT EXISTS slug VARCHAR(128) NULL AFTER id;

UPDATE module_fittings_fits
  SET slug = CONCAT('fit-', id)
  WHERE slug IS NULL OR slug = '';

ALTER TABLE module_fittings_fits
  MODIFY slug VARCHAR(128) NOT NULL,
  ADD UNIQUE KEY uniq_fittings_fit_slug (slug);
