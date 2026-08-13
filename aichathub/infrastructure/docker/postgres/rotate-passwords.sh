#!/bin/sh
set -eu

# Runs after init.sql (docker-entrypoint-initdb.d executes files in filename order,
# and "rotate-passwords.sh" sorts after "init.sql"). init.sql creates each service's
# DB user with a placeholder dev password hardcoded in that (git-tracked) file — fine
# for dev, not something to ever put real secrets into. This script overwrites those
# placeholder passwords with the real ones, read from environment variables instead,
# so the actual secrets only ever live in the untracked .env files, never in git.
# In dev, none of these env vars are set, so every ALTER just silently keeps the
# existing dev password — this script is safe to run unconditionally either way.

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    ALTER USER auth_app     WITH PASSWORD '${AUTH_DB_PASSWORD:-auth_secret}';
    ALTER USER sub_app      WITH PASSWORD '${SUB_DB_PASSWORD:-sub_secret}';
    ALTER USER wallet_app   WITH PASSWORD '${WALLET_DB_PASSWORD:-wallet_secret}';
    ALTER USER payment_app  WITH PASSWORD '${PAYMENT_DB_PASSWORD:-payment_secret}';
    ALTER USER billing_app  WITH PASSWORD '${BILLING_DB_PASSWORD:-billing_secret}';
    ALTER USER ai_app       WITH PASSWORD '${AI_DB_PASSWORD:-ai_secret}';
    ALTER USER chat_app     WITH PASSWORD '${CHAT_DB_PASSWORD:-chat_secret}';
    ALTER USER notif_app    WITH PASSWORD '${NOTIF_DB_PASSWORD:-notif_secret}';
EOSQL
