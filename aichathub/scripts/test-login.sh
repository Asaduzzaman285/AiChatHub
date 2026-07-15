#!/bin/sh

echo "=== TEST: Login ==="
curl -s -w "\nHTTP:%{http_code}\n" \
  -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}'
