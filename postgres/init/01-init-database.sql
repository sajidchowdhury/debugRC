-- =============================================================================
-- RC_ERP_v2 — PostgreSQL Initialization Script
-- =============================================================================
-- Runs automatically when the PostgreSQL container starts for the first time.
-- Creates the application role + database (if not already created by env vars).
-- =============================================================================

-- The POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD env vars already create
-- the database and user. This script adds:
--   1. Extensions (if needed)
--   2. Test database for PHPUnit
--   3. Grants

-- Create test database (for PHPUnit testing)
CREATE DATABASE rcerp_test;
GRANT ALL PRIVILEGES ON DATABASE rcerp_test TO rcerp_app;

-- Grant schema permissions (runs after database creation)
\connect rcerp

-- Ensure the app user can create tables, indexes, etc.
GRANT ALL ON SCHEMA public TO rcerp_app;

-- Enable required extensions (pg_trgm for ILIKE optimization)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Connect to test database and enable extensions there too
\connect rcerp_test
GRANT ALL ON SCHEMA public TO rcerp_app;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
