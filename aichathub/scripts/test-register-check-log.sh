#!/bin/sh
# docker exec aichathub-auth-nginx sh /tr.sh

TIMESTAMP=$(date +%s)
EMAIL="logtest${TIMESTAMP}@test.com"

echo "=== Registering $EMAIL ==="
curl -s -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"name\":\"Test\",\"email\":\"${EMAIL}\",\"password\":\"Password123!\",\"password_confirmation\":\"Password123!\",\"currency\":\"USD\"}"

echo ""
echo "HTTP done"
