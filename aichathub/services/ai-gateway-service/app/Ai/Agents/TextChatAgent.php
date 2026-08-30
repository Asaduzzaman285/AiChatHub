<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\CostTrackingMiddleware;
use App\Ai\Middleware\UsageLoggingMiddleware;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

// No #[Temperature(...)] here on purpose — confirmed live that Anthropic's newest
// Claude 5-generation models (claude-sonnet-5, likely claude-opus-5 too) reject the
// request outright with 400 "`temperature` is deprecated for this model" the instant
// this attribute is present, regardless of value. Every provider we use is fine with
// temperature simply being omitted (falls back to that provider's own default), so
// dropping it here is the safe fix for all 8 providers, not a per-model workaround.
#[MaxTokens(4096)]
class TextChatAgent implements Agent, Conversational, HasMiddleware, HasTools, HasProviderOptions
{
    use Promptable;

    public function __construct(
        private string $userId,
        private string $sessionId,
        private array  $history = [],
        private ?string $systemPrompt = null,
        // Both default false and both re-derived server-side by the caller from the
        // model's real capabilities before construction — see ChatController::stream().
        // Never trust these as passed straight from client input; some providers throw
        // (not silently ignore) an unsupported tool, and the Deep Think provider-option
        // is only a verified-safe payload for Anthropic.
        private bool $webSearchEnabled = false,
        private bool $deepThinkEnabled = false,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful, accurate, and concise AI assistant.';
    }

    public function messages(): iterable
    {
        return array_map(
            fn ($msg) => new Message($msg['role'], $msg['content']),
            $this->history
        );
    }

    public function middleware(): array
    {
        return [
            new CostTrackingMiddleware($this->userId),
            new UsageLoggingMiddleware($this->userId, $this->sessionId),
        ];
    }

    public function tools(): iterable
    {
        return $this->webSearchEnabled ? [new WebSearch()] : [];
    }

    // Anthropic-specific extended-thinking payload — laravel/ai has no first-class
    // "reasoning" attribute; this generic escape hatch is the only verified mechanism
    // (Gateway/Anthropic/Concerns/BuildsTextRequests.php reads a raw `thinking` key).
    // budget_tokens is a reasonable starting value, not independently tuned yet.
    // Re-checks $provider itself (not just the caller's already-gated flag) — belt and
    // suspenders, since this payload is only verified meaningful for Anthropic and this
    // method has no other reason to ever fire for a different provider.
    public function providerOptions(Lab|string $provider): array
    {
        $isAnthropic = ($provider instanceof Lab ? $provider->value : $provider) === Lab::Anthropic->value;

        return $this->deepThinkEnabled && $isAnthropic
            ? ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]
            : [];
    }
}
