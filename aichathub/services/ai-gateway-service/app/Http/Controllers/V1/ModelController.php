<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Services\SubscriptionClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModelController extends Controller
{
    public function __construct(private SubscriptionClientService $subscriptionClient) {}

    /** GET /models — catalog, cross-referenced against the caller's package access */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $access = $this->subscriptionClient->currentPackageAccess($userId);
        $allowed = $access['model_access'] ?? [];

        $models = AiModel::where('is_active', true)
            ->orderBy('provider')
            ->orderBy('name')
            ->get()
            ->map(function (AiModel $model) use ($allowed) {
                // Customer-facing rates only (input/output/currency) — the provider_*
                // cost fields and markup_percentage on ModelPricing are for admin margin
                // calculations and never belonged in a response any authenticated user
                // can read.
                $pricing = $model->activePricing();

                return [
                    'id'                => $model->id,
                    'model_id'          => $model->model_id,
                    'provider'          => $model->provider,
                    'name'              => $model->name,
                    'type'              => $model->type,
                    'description'       => $model->description,
                    'context_window'    => $model->context_window,
                    'max_output_tokens' => $model->max_output_tokens,
                    'capabilities'      => $model->capabilities,
                    'available'         => in_array($model->model_id, $allowed, true),
                    'pricing'           => $pricing ? [
                        'input_rate_per_million'  => $pricing->input_rate_per_million,
                        'output_rate_per_million' => $pricing->output_rate_per_million,
                        'currency'                => $pricing->currency,
                    ] : null,
                ];
            });

        return response()->json([
            'models'         => $models,
            'package_slug'   => $access['package_slug'] ?? null,
            'has_subscription' => $access !== null,
        ]);
    }

    /** GET /models/public — unauthenticated. No `available`/package_slug (those only
     * mean something relative to a real user's plan) — just the active text models'
     * real, admin-set capabilities and public pricing, for the landing page's navbar
     * popup to show honestly to a visitor who hasn't signed up yet. */
    public function public(): JsonResponse
    {
        $models = AiModel::where('is_active', true)
            ->where('type', 'text')
            ->orderBy('provider')
            ->orderBy('name')
            ->get()
            ->map(function (AiModel $model) {
                $pricing = $model->activePricing();

                return [
                    'id'           => $model->id,
                    'model_id'     => $model->model_id,
                    'provider'     => $model->provider,
                    'name'         => $model->name,
                    'capabilities' => $model->capabilities,
                    'pricing'      => $pricing ? [
                        'input_rate_per_million'  => $pricing->input_rate_per_million,
                        'output_rate_per_million' => $pricing->output_rate_per_million,
                        'currency'                => $pricing->currency,
                    ] : null,
                ];
            });

        return response()->json(['models' => $models]);
    }
}
