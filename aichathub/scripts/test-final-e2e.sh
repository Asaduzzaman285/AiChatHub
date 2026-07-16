#!/bin/sh
# docker exec aichathub-auth-nginx sh /t.sh

TIMESTAMP=$(date +%s)
EMAIL="user${TIMESTAMP}@test.com"

echo "=== 1. Register ==="
RESULT=$(curl -s -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"name\":\"Test\",\"email\":\"${EMAIL}\",\"password\":\"Password123!\",\"password_confirmation\":\"Password123!\",\"currency\":\"USD\"}")
echo $RESULT
USER_ID=$(echo $RESULT | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "USER_ID=$USER_ID"

echo ""
echo "=== 2. Wait 4s for async listener ==="
sleep 4

echo ""
echo "=== 3. Check wallet created ==="
curl -s -w "\nHTTP:%{http_code}" \
  "http://wallet-nginx/api/internal/wallet/${USER_ID}" \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production"

echo ""
echo ""
echo "=== 4. Login ==="
curl -s -w "\nHTTP:%{http_code}" \
  -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"email\":\"test@example.com\",\"password\":\"Password123!\"}" | grep -o '"access_token":"[^"]*"' | cut -c1-40
