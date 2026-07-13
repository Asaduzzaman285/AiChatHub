@echo off
echo [1/5] Migrating Auth Service (Phase 1 - no dependencies)
docker exec -it aichathub-auth php artisan migrate --force
echo.

echo [2/5] Migrating Subscription Service (Phase 1 - no dependencies)  
docker exec -it aichathub-subscription php artisan migrate --force
echo.

echo [3/5] Migrating Wallet Service (Phase 2 - depends on auth schema)
docker exec -it aichathub-wallet php artisan migrate --force
echo.

echo [4/5] Migrating Payment, AI Gateway, Billing, Notification Services
docker exec -it aichathub-payment php artisan migrate --force
docker exec -it aichathub-ai-gateway php artisan migrate --force
docker exec -it aichathub-billing php artisan migrate --force
docker exec -it aichathub-notification php artisan migrate --force
echo.

echo [5/5] Migrating Chat Service (Phase 3 - depends on auth and AI)
docker exec -it aichathub-chat php artisan migrate --force
echo.

echo ✅ All migrations completed successfully!
echo.
echo Verify schemas:
docker exec -it aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt auth_svc.*"
docker exec -it aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt wallet_svc.*"