-- Adds missing column expected by modules/auth and corptools UI
ALTER TABLE module_corptools_character_summary
  ADD COLUMN last_login_at TIMESTAMP NULL AFTER total_sp;

-- Optional but recommended for list screens/sorting
ALTER TABLE module_corptools_character_summary
  ADD KEY idx_corptools_char_last_login (last_login_at);

