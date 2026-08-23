<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

// No HasMiddleware — deliberately skips CostTrackingMiddleware. This is our own
// housekeeping (auto-titling a session), not something the user asked for or should
// see charged against their wallet; the token cost of a 3-6 word title is treated as
// platform overhead, same as it would be for any other internal/system call.
#[MaxTokens(30)]
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
