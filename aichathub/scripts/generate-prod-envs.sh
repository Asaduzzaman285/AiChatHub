#!/bin/bash
# Run on the server, from /opt/aichathub, with /root/secrets/generated.env sourced.
# Builds every service's real .env from its .env.production.example template.
set -euo pipefail
cd "$(dirname "$0")/.."
source /root/secrets/generated.env

# R2_ACCESS_KEY, R2_SECRET_KEY, R2_ENDPOINT, R2_BUCKET, SENTRY_DSN, STRIPE_SECRET,
# and STRIPE_PUBLISHABLE all come from generated.env too (never hardcoded here) —
# this script is safe to commit; the real values only ever live in
# /root/secrets/generated.env on the server, which is not tracked in git.

SERVICES="auth-service subscription-service wallet-service payment-service ai-gateway-service chat-service billing-service notification-service api-gateway"

# --- 1. Copy templates to real .env ---
for s in $SERVICES; do
  cp "services/$s/.env.production.example" "services/$s/.env"
done

# --- 2. Global replacements (identical placeholder text across every service that has it) ---
for s in $SERVICES; do
  f="services/$s/.env"
  sed -i "s|REDIS_PASSWORD=CHANGE_ME_MATCH_REDIS_CONTAINER_PASSWORD|REDIS_PASSWORD=$REDIS_PASSWORD|" "$f"
  sed -i "s|INTERNAL_SERVICE_KEY=CHANGE_ME_SAME_VALUE_IN_EVERY_SERVICE|INTERNAL_SERVICE_KEY=$INTERNAL_SERVICE_KEY|" "$f"
  sed -i "s|JWT_SECRET=CHANGE_ME_32_CHAR_MIN_SECRET_KEY|JWT_SECRET=$JWT_SECRET|" "$f"
  sed -i "s|SENTRY_LARAVEL_DSN=CHANGE_ME|SENTRY_LARAVEL_DSN=$SENTRY_DSN|" "$f"
  sed -i "s|https://app.yourdomain.com|https://app.alveta.ai|g" "$f"
  sed -i "s|https://api.yourdomain.com|https://api.alveta.ai|g" "$f"
done

# --- 3. Per-service DB password ---
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$AUTH_DB_PASSWORD|" services/auth-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$SUB_DB_PASSWORD|" services/subscription-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$WALLET_DB_PASSWORD|" services/wallet-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$PAYMENT_DB_PASSWORD|" services/payment-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$AI_DB_PASSWORD|" services/ai-gateway-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$CHAT_DB_PASSWORD|" services/chat-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$BILLING_DB_PASSWORD|" services/billing-service/.env
sed -i "s|DB_PASSWORD=CHANGE_ME_ROTATE_FROM_DEV_DEFAULT|DB_PASSWORD=$NOTIF_DB_PASSWORD|" services/notification-service/.env
# api-gateway has no direct DB connection — nothing to patch there.

# --- 4. R2 storage (chat-service: file uploads, billing-service: invoices) ---
for s in chat-service billing-service; do
  f="services/$s/.env"
  sed -i "s|AWS_ACCESS_KEY_ID=CHANGE_ME|AWS_ACCESS_KEY_ID=$R2_ACCESS_KEY|" "$f"
  sed -i "s|AWS_SECRET_ACCESS_KEY=CHANGE_ME|AWS_SECRET_ACCESS_KEY=$R2_SECRET_KEY|" "$f"
  sed -i "s|AWS_DEFAULT_REGION=CHANGE_ME|AWS_DEFAULT_REGION=auto|" "$f"
  sed -i "s|AWS_BUCKET=CHANGE_ME|AWS_BUCKET=$R2_BUCKET|" "$f"
  sed -i "s|AWS_ENDPOINT=CHANGE_ME_REAL_S3_ENDPOINT|AWS_ENDPOINT=$R2_ENDPOINT|" "$f"
done

# --- 5. Stripe (test-mode, staying test per Phase 2 deferral — webhook secret patched later) ---
f="services/payment-service/.env"
sed -i "s|STRIPE_SECRET_KEY=sk_live_CHANGE_ME|STRIPE_SECRET_KEY=$STRIPE_SECRET|" "$f"
sed -i "s|STRIPE_PUBLISHABLE_KEY=pk_live_CHANGE_ME|STRIPE_PUBLISHABLE_KEY=$STRIPE_PUBLISHABLE|" "$f"

# --- 6. Postgres/Redis themselves (root .env for docker-compose.prod.yml's own substitution) ---
cat > .env <<EOF
POSTGRES_PASSWORD=$POSTGRES_PASSWORD
REDIS_PASSWORD=$REDIS_PASSWORD
AUTH_DB_PASSWORD=$AUTH_DB_PASSWORD
SUB_DB_PASSWORD=$SUB_DB_PASSWORD
WALLET_DB_PASSWORD=$WALLET_DB_PASSWORD
PAYMENT_DB_PASSWORD=$PAYMENT_DB_PASSWORD
BILLING_DB_PASSWORD=$BILLING_DB_PASSWORD
AI_DB_PASSWORD=$AI_DB_PASSWORD
CHAT_DB_PASSWORD=$CHAT_DB_PASSWORD
NOTIF_DB_PASSWORD=$NOTIF_DB_PASSWORD
APP_DOMAIN=app.alveta.ai
API_DOMAIN=api.alveta.ai
BACKUP_S3_BUCKET=$R2_BUCKET
BACKUP_AWS_ACCESS_KEY_ID=$R2_ACCESS_KEY
BACKUP_AWS_SECRET_ACCESS_KEY=$R2_SECRET_KEY
BACKUP_AWS_REGION=auto
BACKUP_AWS_ENDPOINT=$R2_ENDPOINT
EOF

# --- 7. Frontend .env.production (build-time, Next.js) ---
cat > frontend/.env.production <<EOF
API_GATEWAY_URL=http://api-gateway-nginx
NEXT_PUBLIC_API_URL=https://api.alveta.ai
NEXT_PUBLIC_GOOGLE_CLIENT_ID=CHANGE_ME.apps.googleusercontent.com
NEXT_PUBLIC_GOOGLE_REDIRECT_HANDLER=/auth/callback
NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY=$STRIPE_PUBLISHABLE
NEXT_PUBLIC_SENTRY_DSN=
EOF

echo "DONE — remaining CHANGE_ME markers (expected: Mailgun creds, DeepSeek key, Google OAuth dead-code, Stripe webhook secret):"
grep -rn "CHANGE_ME" services/*/.env .env frontend/.env.production || echo "(none)"
