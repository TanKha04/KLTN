-- Fix fullname issue by adding fullname column to users table

-- Add fullname column (will fail silently if already exists)
ALTER TABLE users ADD COLUMN fullname VARCHAR(150) DEFAULT NULL AFTER name;

-- Sync existing data
UPDATE users SET fullname = name WHERE fullname IS NULL OR fullname = '';
