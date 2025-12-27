ALTER TABLE migration_log
  MODIFY status ENUM('applied','failed','mismatch') NOT NULL;
