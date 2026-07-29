<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ModelPricing;
use App\Services\AuditLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manages the ai_models catalog + its versioned model_pricing rows — both
 * only ever came from a one-time seeder before this. "Delete" here always
 * means deactivate, never a real row delete: usage_logs.model_id is a real
 * FK with no cascade, so a model with usage history can't be hard-deleted
 * anyway, and the rest of the app already prefers is_active flags over real
 * deletes (packages, admins) — models follow the same convention.
 */
class AiModelAdminController extends Controller
{
    private const PRICING_TYPES = ['token_based', 'flat_per_image', 'character_based', 'per_minute'];

    /** GET /models/admin — every model, active or not, with its current pricing embedded. */
    public function index(): JsonResponse
    {
        $models = AiModel::orderBy('provider')->orderBy('name')->get()->map(fn (AiModel $model) => $this->format($model));

        return response()->json(['models' => $models]);
    }

    /**
     * POST /models/admin — creates the model and its initial pricing together.
     * Pricing is required here (not optional) — a model with no active pricing
     * row silently bills at a flat fallback rate (see CostTrackingMiddleware::ratesFor()),
     * which is exactly the trap this is meant to avoid.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateModel($request, isCreate: true);

        $model = DB::transaction(function () use ($data) {
            $model = AiModel::create([
                'provider'          => $data['provider'],
                'name'              => $data['name'],
                'model_id'          => $data['model_id'],
                'type'              => $data['type'],
                'description'       => $data['description'] ?? null,
                'context_window'    => $data['context_window'] ?? null,
                'max_output_tokens' => $data['max_output_tokens'] ?? null,
                'capabilities'      => $data['capabilities'] ?? [],
                'is_active'         => $data['is_active'] ?? true,
            ]);

            $this->createPricing($model, $data);

            return $model;
        });

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'model.created', 'ai_model', $model->id,
            null, $this->format($model->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['model' => $this->format($model->fresh())], 201);
    }

    /**
     * PATCH /models/admin/{id} — edits model fields directly. If pricing
     * fields are included, the current active pricing row is closed
     * (effective_until = now) and a new one inserted, rather than mutated in
     * place — preserves what rate was actually active for any historical
     * usage_logs row while new requests bill at the new rate immediately.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $model = AiModel::find($id);

        if (! $model) {
            return response()->json(['message' => 'Model not found.', 'error' => 'not_found'], 404);
        }

        $data = $this->validateModel($request, isCreate: false);
        $old  = $this->format($model);

        // $data only contains keys actually present in the request (every
        // field is validated with 'sometimes') — safe to pass straight
        // through, including an intentional null (e.g. clearing description).
        $modelFields = array_intersect_key($data, array_flip([
            'name', 'description', 'context_window', 'max_output_tokens', 'capabilities', 'is_active',
        ]));

        DB::transaction(function () use ($model, $modelFields, $data) {
            if ($modelFields) {
                $model->update($modelFields);
            }

            if (isset($data['pricing_type'])) {
                $model->activePricing()?->update(['is_active' => false, 'effective_until' => now()]);
                $this->createPricing($model, $data);
            }
        });

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'model.updated', 'ai_model', $model->id,
            $old, $this->format($model->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['model' => $this->format($model->fresh())]);
    }

    /** PATCH /models/admin/{id}/deactivate */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, false);
    }

    /** PATCH /models/admin/{id}/activate */
    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, true);
    }

    private function setActive(Request $request, string $id, bool $active): JsonResponse
    {
        $model = AiModel::find($id);

        if (! $model) {
            return response()->json(['message' => 'Model not found.', 'error' => 'not_found'], 404);
        }

        $old = $this->format($model);
        $model->update(['is_active' => $active]);

        app(AuditLogClient::class)->log(
            $this->adminId($request), $active ? 'model.activated' : 'model.deactivated', 'ai_model', $model->id,
            $old, $this->format($model->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['model' => $this->format($model->fresh())]);
    }

    private function createPricing(AiModel $model, array $data): void
    {
        ModelPricing::create([
            'model_id'                => $model->id,
            'pricing_type'            => $data['pricing_type'],
            'input_rate_per_million'  => $data['input_rate_per_million']  ?? null,
            'output_rate_per_million' => $data['output_rate_per_million'] ?? null,
            'flat_rate_per_unit'      => $data['flat_rate_per_unit']      ?? null,
            'currency'                => $data['currency'] ?? 'USD',
            'effective_from'          => now(),
            'is_active'               => true,
        ]);
    }

    private function validateModel(Request $request, bool $isCreate): array
    {
        $modelIdRule = $isCreate
            ? Rule::unique('ai_models', 'model_id')->where('provider', $request->input('provider'))
            : null;

        return $request->validate([
            'provider'                 => [$isCreate ? 'required' : 'sometimes', 'string', 'max:50'],
            'name'                     => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'model_id'                 => array_filter([$isCreate ? 'required' : 'prohibited', 'string', 'max:100', $modelIdRule]),
            'type'                     => [$isCreate ? 'required' : 'sometimes', Rule::in(['text', 'image_generation', 'audio_tts', 'audio_stt', 'embedding'])],
            'description'              => 'nullable|string',
            'context_window'           => 'nullable|integer|min:1',
            'max_output_tokens'        => 'nullable|integer|min:1',
            'capabilities'             => 'nullable|array',
            'is_active'                => 'sometimes|boolean',
            // Pricing — required together at creation; optional as a group on update.
            'pricing_type'             => [$isCreate ? 'required' : 'sometimes', Rule::in(self::PRICING_TYPES)],
            'input_rate_per_million'   => 'required_if:pricing_type,token_based|nullable|numeric|min:0',
            'output_rate_per_million'  => 'required_if:pricing_type,token_based|nullable|numeric|min:0',
            'flat_rate_per_unit'       => 'required_unless:pricing_type,token_based|nullable|numeric|min:0',
            'currency'                 => 'nullable|string|size:3',
        ]);
    }

    private function format(AiModel $model): array
    {
        $pricing = $model->activePricing();

        return [
            'id'                => $model->id,
            'provider'          => $model->provider,
            'name'              => $model->name,
            'model_id'          => $model->model_id,
            'type'              => $model->type,
            'description'       => $model->description,
            'context_window'    => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'capabilities'      => $model->capabilities,
            'is_active'         => $model->is_active,
            'created_at'        => $model->created_at,
            'pricing'           => $pricing ? [
                'pricing_type'             => $pricing->pricing_type,
                'input_rate_per_million'   => $pricing->input_rate_per_million,
                'output_rate_per_million'  => $pricing->output_rate_per_million,
                'flat_rate_per_unit'       => $pricing->flat_rate_per_unit,
                'currency'                 => $pricing->currency,
            ] : null,
        ];
    }
}
