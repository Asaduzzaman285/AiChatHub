#!/bin/sh
# Tests registration through the API Gateway (port 8000)
# docker exec aichathub-gateway-nginx sh /tgr.sh

TIMESTAMP=$(date +%s)
EMAIL="gwtest${TIMESTAMP}@test.com"

echo "=== Register via API Gateway ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"name\":\"GW Test\",\"email\":\"${EMAIL}\",\"password\":\"Password123!\",\"password_confirmation\":\"Password123!\",\"currency\":\"USD\"}"

echo ""
echo "=== Login via API Gateway ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}' | grep -o '"access_token":"[^"]*"' | cut -c1-50

echo ""
echo "=== Packages via API Gateway ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://localhost/api/v1/packages \
  -H "Accept: application/json" | grep -o '"name":"[^"]*"'
