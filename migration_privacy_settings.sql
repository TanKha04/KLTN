-- Migration: Add privacy settings columns to users table
-- Run this SQL to add the new columns for privacy settings

ALTER TABLE users ADD COLUMN IF NOT EXISTS show_phone TINYINT(1) DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS show_email TINYINT(1) DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS allow_messages TINYINT(1) DEFAULT 1;

-- For MySQL versions that don't support IF NOT EXISTS in ALTER TABLE:
-- You can use these alternative queries (will error if column exists, but that's okay)
-- ALTER TABLE users ADD COLUMN show_phone TINYINT(1) DEFAULT 1;
-- ALTER TABLE users ADD COLUMN show_email TINYINT(1) DEFAULT 1;
-- ALTER TABLE users ADD COLUMN allow_messages TINYINT(1) DEFAULT 1;
