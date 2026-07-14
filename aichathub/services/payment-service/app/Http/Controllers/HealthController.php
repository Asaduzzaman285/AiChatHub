<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        $checks = [];

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
        }

        // Redis check
        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Exception $e) {
            $checks['redis'] = 'error: ' . $e->getMessage();
        }

        $allOk = !in_array(true, array_map(fn($v) => str_starts_with($v, 'error'), $checks));

        return response()->json([
            'status'  => $allOk ? 'ok' : 'degraded',
            'service' => config('app.name'),
            'checks'  => $checks,
        ], $allOk ? 200 : 503);
    }
}
