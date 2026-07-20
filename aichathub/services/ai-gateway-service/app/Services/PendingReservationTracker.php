<?php

namespace App\Services;

/**
 * Tracks whether the current request has an outstanding wallet reservation
 * that hasn't been settled (deduct()'d) yet.
 *
 * Exists because the actual provider HTTP call (OpenAI/Gemini/etc.) runs
 * inside a StreamedResponse's lazy generator, invoked well after
 * CostTrackingMiddleware's own try/catch has already exited normally — a
 * try/catch there cannot see that failure. Bound as a singleton per request;
 * checked by a register_shutdown_function() in bootstrap/app.php instead,
 * which fires no matter how the request ended.
 */
class PendingReservationTracker
{
    private ?string $userId = null;
    private float $amount = 0.0;

    public function mark(string $userId, float $amount): void
    {
        $this->userId = $userId;
        $this->amount = $amount;
    }

    public function clear(): void
    {
        $this->userId = null;
        $this->amount = 0.0;
    }

    public function pending(): ?array
    {
        return $this->userId ? ['user_id' => $this->userId, 'amount' => $this->amount] : null;
    }
}
