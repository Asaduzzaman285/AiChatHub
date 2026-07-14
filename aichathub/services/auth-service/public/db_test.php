<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

try {
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "DB OK";
} catch (\Exception $e) {
    echo "DB Error: " . $e->getMessage();
}
