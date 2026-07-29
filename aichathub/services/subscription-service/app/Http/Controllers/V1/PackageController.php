<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\AuditLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * PATCH /packages/{slug} — admin-only (admin.gate). Update any subset of a
     * package's editable fields. Not full CRUD (no create/delete) — just makes
     * what already exists genuinely editable, including the new
     * credit_buffer_percentage, rather than DB-edits-only.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $package = Package::where('slug', $slug)->first();

        if (! $package) {
            return response()->json(['message' => 'Package not found.', 'error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'name'                      => 'sometimes|string|max:100',
            'description'               => 'sometimes|nullable|string',
            'monthly_price_usd'         => 'sometimes|numeric|min:0',
            'monthly_price_bdt'         => 'sometimes|numeric|min:0',
            'monthly_wallet_credit_usd' => 'sometimes|numeric|min:0',
            'credit_buffer_percentage'  => 'sometimes|numeric|min:0|max:100',
            'model_access'              => 'sometimes|array',
            'features'                  => 'sometimes|array',
            'is_active'                 => 'sometimes|boolean',
            'sort_order'                => 'sometimes|integer',
        ]);

        $old = $package->toArray();
        $package->update($data);

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'package.updated', 'package', $package->id,
            $old, $package->fresh()->toArray(), $request->ip(), $request->userAgent(),
        );

        return response()->json(['package' => $package->fresh()]);
    }

    /** POST /packages — admin-only. Packages were previously seeder-only; this is the first real create path. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                      => 'required|string|max:100',
            'slug'                      => 'required|string|max:100|unique:packages,slug',
            'description'               => 'nullable|string',
            'monthly_price_usd'         => 'required|numeric|min:0',
            'monthly_price_bdt'         => 'nullable|numeric|min:0',
            'monthly_wallet_credit_usd' => 'required|numeric|min:0',
            'credit_buffer_percentage'  => 'nullable|numeric|min:0|max:100',
            'model_access'              => 'nullable|array',
            'features'                  => 'nullable|array',
            'is_active'                 => 'nullable|boolean',
            'sort_order'                => 'nullable|integer',
        ]);

        $package = Package::create($data + [
            'credit_buffer_percentage' => $data['credit_buffer_percentage'] ?? 30.00,
            'model_access'             => $data['model_access'] ?? [],
            'features'                 => $data['features'] ?? [],
            'is_active'                => $data['is_active'] ?? true,
            'sort_order'               => $data['sort_order'] ?? ((int) Package::max('sort_order') + 1),
        ]);

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'package.created', 'package', $package->id,
            null, $package->toArray(), $request->ip(), $request->userAgent(),
        );

        return response()->json(['package' => $package], 201);
    }

    /** GET /packages/admin — admin-only. Unlike index(), spans inactive packages too — an admin needs to see and reactivate them. */
    public function adminIndex(): JsonResponse
    {
        $packages = Package::orderBy('sort_order')->get();

        return response()->json(['packages' => $packages]);
    }
}
