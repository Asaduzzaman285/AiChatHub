<?php
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "DB OK\n";
} catch (\Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}

try {
    Illuminate\Support\Facades\Redis::ping();
    echo "Redis OK\n";
} catch (\Exception $e) {
    echo "Redis Error: " . $e->getMessage() . "\n";
}
