-- Dijalankan sekali saat volume database pertama dibuat.
CREATE EXTENSION IF NOT EXISTS postgis;

-- Database terpisah untuk test (Pest), juga butuh PostGIS.
SELECT 'CREATE DATABASE testing' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')\gexec
\c testing
CREATE EXTENSION IF NOT EXISTS postgis;
