#!/bin/sh

echo "=== 1. REGISTER (new user) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Jane Doe","email":"jane@example.com","password":"Password123!","password_confirmation":"Password123!","currency":"USD"}'

echo ""
echo "=== 2. RUN QUEUE WORKER (send verification email) ==="
# Run queue worker in the auth container (not nginx)
# This is done externally; here we just log it
echo "(Queue worker must be running separately)"

echo ""
echo "=== 3. LOGIN with existing verified user ==="
RESPONSE=$(curl -s -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}')

echo $RESPONSE | grep -o '"access_token":"[^"]*"' | cut -c1-50
ACCESS_TOKEN=$(echo $RESPONSE | sed 's/.*"access_token":"\([^"]*\)".*/\1/')

echo ""
echo "=== 4. GET /me with JWT ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://localhost/api/v1/auth/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

echo ""
echo "=== 5. FIREBASE endpoint (bad token = 401) ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/firebase \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"id_token":"invalid"}'

echo ""
echo "=== 6. TOKEN REFRESH ==="
REFRESH_TOKEN=$(echo $RESPONSE | sed 's/.*"refresh_token":"\([^"]*\)".*/\1/')
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}"
