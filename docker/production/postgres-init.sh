#!/bin/sh
set -eu
AGRO_APP_PASSWORD="$(cat /run/secrets/db_app_password)"
AGRO_MIGRATION_PASSWORD="$(cat /run/secrets/db_migration_password)"
export AGRO_APP_PASSWORD AGRO_MIGRATION_PASSWORD
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv app_password AGRO_APP_PASSWORD
\getenv migration_password AGRO_MIGRATION_PASSWORD
SELECT format('CREATE ROLE agroconecta_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L', :'app_password') \gexec
SELECT format('CREATE ROLE agroconecta_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L', :'migration_password') \gexec
REVOKE ALL ON DATABASE agroconecta FROM PUBLIC;
GRANT CONNECT ON DATABASE agroconecta TO agroconecta_app, agroconecta_migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO agroconecta_app;
GRANT USAGE, CREATE ON SCHEMA public TO agroconecta_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE agroconecta_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO agroconecta_app;
ALTER DEFAULT PRIVILEGES FOR ROLE agroconecta_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO agroconecta_app;
SQL
unset AGRO_APP_PASSWORD AGRO_MIGRATION_PASSWORD

