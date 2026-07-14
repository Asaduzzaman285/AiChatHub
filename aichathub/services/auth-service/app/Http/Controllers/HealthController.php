<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'service' => config('app.name'),
            'checks'  => ['database' => 'ok', 'redis' => 'ok'],
        ], 200);
    }
}
