<?php

namespace App\Ai\Tools\Concerns;

use App\Models\AiModel;
use App\Services\ChatServiceClient;
use App\Services\GeneratedAttachmentTracker;
use App\Services\WalletClientService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared by every generation tool (image, spreadsheet, presentation, PDF) — each one
 * follows the identical shape: look up its own flat-per-unit price, reserve the
 * wallet, do the actual generation, store the result as a real attachment, then
 * settle the reservation one way or the other. Pulled out here once GenerateImageTool
 * and the three document tools all needed the exact same sequence, rather than
 * repeating it four times with only the price lookup and error strings differing.
 */
trait ChargesForGeneratedFile
{
    /**
     * @param  \Closure(): string  $generate  Returns the raw file bytes, or throws.
     */
    private function chargeAndStore(
        string $userId,
        string $pricingModelId,
        string $filename,
        string $mimeType,
        \Closure $generate,
        GeneratedAttachmentTracker $tracker,
        string $unavailableMessage,
        string $generationFailedMessage,
        string $successMessage,
    ): string {
        $model = AiModel::where('model_id', $pricingModelId)->where('is_active', true)->first();
        $pricing = $model?->activePricing();

        if (! $model || ! $pricing || ! in_array($pricing->pricing_type, ['flat_per_image', 'flat_per_file'], true)) {
            // Silent before this — a tool call that immediately bounces off "unavailable"
            // left no trail at all to tell a missing catalog row apart from a missing
            // pricing row apart from a real provider failure, all of which read
            // identically to the user ("generation failed"). Confirmed live: exactly
            // this ambiguity, no log line anywhere, when a real user's "generate a
            // cartoon" attempt failed with no way to tell why after the fact.
            Log::warning('Generation tool unavailable — no active model/pricing', [
                'pricing_model_id' => $pricingModelId,
                'user' => $userId,
                'model_found' => (bool) $model,
                'pricing_found' => (bool) $pricing,
                'pricing_type' => $pricing?->pricing_type,
            ]);
            return $unavailableMessage;
        }

        $price = (float) $pricing->flat_rate_per_unit;
        $referenceId = (string) Str::uuid();

        $wallet = app(WalletClientService::class);
        $reserved = $wallet->reserve($userId, $price, $referenceId);

        if ($reserved === null) {
            return "Couldn't reach the wallet service to charge for this. Please try again.";
        }
        if ($reserved === false) {
            return 'Insufficient wallet balance for this. Please top up your wallet and try again.';
        }

        try {
            $bytes = $generate();
        } catch (\Throwable $e) {
            Log::error('Generation failed', [
                'pricing_model_id' => $pricingModelId,
                'user' => $userId,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $wallet->refund($userId, $price, $price, 'Generation failed', $referenceId);
            return $generationFailedMessage;
        }

        $attachmentId = app(ChatServiceClient::class)->createAttachmentFromBytes($userId, $filename, $mimeType, $bytes);

        if (! $attachmentId) {
            Log::error('Generated file could not be saved to chat-service', [
                'pricing_model_id' => $pricingModelId,
                'user' => $userId,
            ]);
            $wallet->refund($userId, $price, $price, 'Generated file could not be saved', $referenceId);
            return "The file generated but couldn't be saved. Please try again.";
        }

        $wallet->deduct($userId, $price, $price, "Generation ({$pricingModelId})", $referenceId);
        $tracker->add($attachmentId);

        return $successMessage;
    }
}
