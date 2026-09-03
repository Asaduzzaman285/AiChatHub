<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

// No HasMiddleware — deliberately skips CostTrackingMiddleware. This is our own
// housekeeping (auto-titling a session), not something the user asked for or should
// see charged against their wallet; the token cost of a 3-6 word title is treated as
// platform overhead, same as it would be for any other internal/system call.
//
// MaxTokens raised from 30 — confirmed live via production logs that titling had been
// failing 100% of the time (every attempt since at least the day before this fix,
// zero successes), always with the same error: DeepSeekGateway::validateTextResponse()
// receiving null instead of an array. DeepSeek's models emit their own internal
// reasoning tokens before the actual answer; a 30-token cap left no room for any real
// title content once reasoning consumed the budget, which is the most likely cause of
// a malformed/empty response the vendor library couldn't parse. 80 tokens is still a
// small, cheap request — comfortably below TextChatAgent's 4096 — just no longer razor
// thin against a model that reasons before answering.
#[MaxTokens(80)]
class TitleGeneratorAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You generate short chat titles. Reply with ONLY the title text — no quotes, '
            . 'no punctuation at the end, no preamble or explanation. 3 to 6 words, plain '
            . 'language, summarizing what the conversation is actually about.';
    }
}
