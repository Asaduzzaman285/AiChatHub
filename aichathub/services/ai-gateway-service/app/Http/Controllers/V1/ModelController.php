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
            ->map(fn (AiModel $model) => [
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
            ]);

        return response()->json([
            'models'         => $models,
            'package_slug'   => $access['package_slug'] ?? null,
            'has_subscription' => $access !== null,
        ]);
    }
}
