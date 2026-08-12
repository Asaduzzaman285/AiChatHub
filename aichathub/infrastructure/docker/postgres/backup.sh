#!/bin/sh
set -eu

# Full logical dump of the shared database (every service's schema, one dump — simpler
# to restore than per-schema dumps and the DB is small enough that this is cheap),
# gzipped, uploaded straight to S3-compatible storage without ever touching local
# disk. --endpoint-url makes this work against any S3-compatible provider (AWS,
# DigitalOcean Spaces, Cloudflare R2, or dev's own MinIO), not just AWS S3 itself.

TIMESTAMP=$(date -u +%Y-%m-%dT%H-%M-%SZ)
DEST="s3://${BACKUP_S3_BUCKET}/postgres/${TIMESTAMP}.sql.gz"

echo "[backup] $(date -u +%FT%TZ) starting dump -> ${DEST}"

PGPASSWORD="${POSTGRES_PASSWORD}" pg_dump \
  --host="${POSTGRES_HOST:-postgres}" \
  --port="${POSTGRES_PORT:-5432}" \
  --username="${POSTGRES_USER:-postgres}" \
  --dbname="${POSTGRES_DB:-ai_chathub_db}" \
  | gzip \
  | aws s3 cp - "${DEST}" \
      --endpoint-url "${AWS_ENDPOINT}" \
      --region "${AWS_DEFAULT_REGION:-us-east-1}"

echo "[backup] $(date -u +%FT%TZ) done"
