<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\CostTrackingMiddleware;
use App\Ai\Middleware\UsageLoggingMiddleware;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

#[MaxTokens(4096)]
#[Temperature(0.7)]
class TextChatAgent implements Agent, Conversational, HasMiddleware
{
    use Promptable;

    public function __construct(
        private string $userId,
        private string $sessionId,
        private array  $history = [],
        private ?string $systemPrompt = null
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
}
