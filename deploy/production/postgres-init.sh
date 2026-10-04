#!/bin/sh
set -eu
# First initialization of an EMPTY production database only. No demo/test database.
export HOLOUL_APP_PASSWORD="$(cat /run/holoul-app/database_password)"
export HOLOUL_MIGRATOR_PASSWORD="$(cat /run/holoul-migrator/password)"
psql --set ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<'SQL'
\getenv app_password HOLOUL_APP_PASSWORD
\getenv migrator_password HOLOUL_MIGRATOR_PASSWORD
CREATE ROLE holoul_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD :'app_password';
CREATE ROLE holoul_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD :'migrator_password';
GRANT holoul_app TO holoul_migrator WITH INHERIT FALSE, SET TRUE;
CREATE DATABASE holoul OWNER holoul_migrator;
REVOKE ALL ON DATABASE holoul FROM PUBLIC;
GRANT CONNECT ON DATABASE holoul TO holoul_app;
\connect holoul
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO holoul_app;
ALTER DEFAULT PRIVILEGES FOR ROLE holoul_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO holoul_app;
ALTER DEFAULT PRIVILEGES FOR ROLE holoul_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO holoul_app;
SQL
unset HOLOUL_APP_PASSWORD HOLOUL_MIGRATOR_PASSWORD
