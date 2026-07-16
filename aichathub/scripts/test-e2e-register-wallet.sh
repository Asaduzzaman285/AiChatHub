#!/bin/sh
# Full end-to-end: register user -> queue worker fires -> wallet auto-created
# Run inside auth-nginx: docker exec aichathub-auth-nginx sh /test-e2e.sh

echo "=== STEP 1: Register new user ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"E2E User","email":"e2euser@example.com","password":"Password123!","password_confirmation":"Password123!","currency":"USD"}'

echo ""
echo "=== STEP 2: Check Mailpit for verification email ==="
curl -s http://aichathub-mailpit:8025/api/v1/messages | grep -o '"Subject":"[^"]*"' | head -3

echo ""
echo "=== STEP 3: Check wallets in DB ==="
PGPASSWORD=secret psql -h postgres -U postgres -d ai_chathub_db \
  -c "SELECT user_id, balance, currency FROM wallet_svc.wallets ORDER BY created_at DESC LIMIT 5;"
