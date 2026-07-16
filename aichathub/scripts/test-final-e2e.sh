#!/bin/sh
# Full end-to-end test: register -> queue fires -> wallet auto-created
# docker cp scripts/test-final-e2e.sh aichathub-auth-nginx:/t.sh && docker exec aichathub-auth-nginx sh /t.sh

TIMESTAMP=$(date +%s)
EMAIL="test${TIMESTAMP}@example.com"

echo "=== 1. Register user: $EMAIL ==="
RESULT=$(curl -s -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"name\":\"Test\",\"email\":\"${EMAIL}\",\"password\":\"Password123!\",\"password_confirmation\":\"Password123!\",\"currency\":\"USD\"}")
echo $RESULT
USER_ID=$(echo $RESULT | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
echo "User ID: $USER_ID"

echo ""
echo "=== 2. Wait 3 seconds for queue worker ==="
sleep 3

echo ""
echo "=== 3. Check wallet created via wallet-service API ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://wallet-nginx/api/internal/wallet/$USER_ID \
  -H "Accept: application/json" \
  -H "X-Internal-Service-Key: internal-secret-change-in-production"

echo ""
echo "=== 4. Health check all services ==="
for PORT in 8001 8002 8003 8004 8005 8006 8007 8008; do
  RESP=$(curl -s -w "%{http_code}" -o /dev/null http://aichathub-auth-nginx:80/api/v1/health 2>/dev/null || echo "000")
done
curl -s -w "auth HTTP:%{http_code}\n" -o /dev/null http://localhost/api/v1/health
curl -s -w "wallet HTTP:%{http_code}\n" -o /dev/null http://wallet-nginx/api/v1/health
curl -s -w "subscription HTTP:%{http_code}\n" -o /dev/null http://subscription-nginx/api/v1/health
