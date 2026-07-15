#!/bin/sh

TOKEN="QiLIAw8rmlCCGNSM9QUSszJodatc3hjS5LQtOmWu9ZQ7VsqYjq9JYvwsOPyXfSr5"

echo "=== TEST 1: Verify email ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://localhost/api/v1/auth/verify/$TOKEN \
  -H "Accept: application/json"

echo ""
echo "=== TEST 2: Login after verification ==="
RESPONSE=$(curl -s -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}')

echo $RESPONSE
ACCESS_TOKEN=$(echo $RESPONSE | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4)
echo "HTTP Status: 200 (if token received)"
echo "Access token extracted: ${ACCESS_TOKEN:0:30}..."

echo ""
echo "=== TEST 3: GET /me with JWT ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  http://localhost/api/v1/auth/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $ACCESS_TOKEN"
