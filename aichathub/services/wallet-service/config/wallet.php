<?php

return [
    // No config/wallet.php existed before — WalletService::checkBalanceThresholds()
    // called config('wallet.low_balance_threshold') / config('wallet.critical_balance_threshold')
    // with no file backing either key, so both silently used their hardcoded
    // fallback (5.00 / 1.00) regardless of .env — LOW_BALANCE_THRESHOLD in .env was
    // never actually read.
    'low_balance_threshold'      => (float) env('LOW_BALANCE_THRESHOLD', 5.00),
    'critical_balance_threshold' => (float) env('CRITICAL_BALANCE_THRESHOLD', 1.00),
    // credit_buffer_default removed 2026-07-28 — the buffer is now sized per-package
    // (packages.credit_buffer_percentage, subscription-service), computed by the
    // caller and passed to WalletService::credit() as a real dollar credit_limit,
    // not a flat wallet-service-side fallback.
];
