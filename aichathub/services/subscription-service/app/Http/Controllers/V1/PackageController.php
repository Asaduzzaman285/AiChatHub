<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = DB::table('packages')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'slug'        => $p->slug,
                'description' => $p->description,
                'price'       => [
                    'usd' => (float) $p->monthly_price_usd,
                    'bdt' => (float) $p->monthly_price_bdt,
                ],
                'wallet_credit_usd' => (float) $p->monthly_wallet_credit_usd,
                'features'    => json_decode($p->features, true),
                'model_access'=> json_decode($p->model_access, true),
            ]);

        return response()->json(['packages' => $packages]);
    }

    public function show(string $slug): JsonResponse
    {
        $p = DB::table('packages')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $p) {
            return response()->json(['message' => 'Package not found.'], 404);
        }

        return response()->json([
            'id'          => $p->id,
            'name'        => $p->name,
            'slug'        => $p->slug,
            'description' => $p->description,
            'price'       => [
                'usd' => (float) $p->monthly_price_usd,
                'bdt' => (float) $p->monthly_price_bdt,
            ],
            'wallet_credit_usd' => (float) $p->monthly_wallet_credit_usd,
            'features'    => json_decode($p->features, true),
            'model_access'=> json_decode($p->model_access, true),
        ]);
    }
}
