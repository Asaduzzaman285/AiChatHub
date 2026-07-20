<?php

return [
    // No config/wallet.php existed before — WalletService::checkBalanceThresholds()
    // called config('wallet.low_balance_threshold') / config('wallet.critical_balance_threshold')
    // with no file backing either key, so both silently used their hardcoded
    // fallback (5.00 / 1.00) regardless of .env — LOW_BALANCE_THRESHOLD in .env was
    // never actually read.
    'low_balance_threshold'      => (float) env('LOW_BALANCE_THRESHOLD', 5.00),
    'critical_balance_threshold' => (float) env('CRITICAL_BALANCE_THRESHOLD', 1.00),
    'credit_buffer_default'      => (float) env('CREDIT_BUFFER_DEFAULT', 3.00),
];
