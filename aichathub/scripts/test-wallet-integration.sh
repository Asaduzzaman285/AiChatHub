#!/bin/sh
# Run this inside wallet-nginx: docker exec aichathub-wallet-nginx sh /test-wallet.sh

echo "=== TEST 1: Wallet internal create (existing user) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/internal/wallet/create \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production" \
  -d '{"user_id":"019f6575-f1bc-72fb-bbfd-7240512ea461","currency":"USD"}'

echo ""
echo "=== TEST 2: Wallet show (check balance) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://localhost/api/internal/wallet/019f6575-f1bc-72fb-bbfd-7240512ea461 \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production"

echo ""
echo "=== TEST 3: Register new user from auth-service ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://aichathub-auth-nginx/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Wallet Test","email":"wallettest2@example.com","password":"Password123!","password_confirmation":"Password123!","currency":"USD"}'
