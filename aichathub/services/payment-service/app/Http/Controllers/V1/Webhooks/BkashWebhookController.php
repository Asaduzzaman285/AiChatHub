<?php

namespace App\Http\Controllers\V1\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BkashWebhookController extends Controller
{
    public function __call(string $method, array $args): JsonResponse
    {
        return response()->json(['error' => 'Not implemented', 'method' => $method], 501);
    }
}
