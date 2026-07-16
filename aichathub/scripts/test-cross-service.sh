#!/bin/sh
# docker exec aichathub-auth sh /test-cross.sh

echo "=== Create wallet for e2euser ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://wallet-nginx/api/internal/wallet/create \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production" \
  -d '{"user_id":"019f6994-13fc-7343-9fae-6d0e5996ea4a","currency":"USD"}'

echo ""
echo "=== Create wallet for wallettest2 ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://wallet-nginx/api/internal/wallet/create \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production" \
  -d '{"user_id":"019f697b-a1f6-718f-976f-e1f70d19a235","currency":"USD"}'

echo ""
echo "=== Register fresh test user (should auto-create wallet) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://auth-nginx/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Fresh User","email":"freshuser@example.com","password":"Password123!","password_confirmation":"Password123!","currency":"USD"}'
