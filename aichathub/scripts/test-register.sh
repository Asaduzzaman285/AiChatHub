#!/bin/sh
# Run this INSIDE the nginx container:
# docker exec aichathub-auth-nginx sh /test-register.sh

echo "=== TEST 1: Register ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"Password123!","password_confirmation":"Password123!","currency":"USD"}'

echo ""
echo "=== TEST 2: Login (before verify - should be 403) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}'

echo ""
echo "=== TEST 3: Firebase (should be 401 with bad token) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/firebase \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"id_token":"bad-token-test"}'

echo ""
echo "=== TEST 4: Health ==="
curl -s -w "\nHTTP:%{http_code}\n" http://localhost/api/v1/health
