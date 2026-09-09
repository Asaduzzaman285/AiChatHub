<?php

namespace App\Services;

/**
 * Smuggles a generated file's attachment id out of a Tool::handle() call and into
 * ChatController::stream()'s response-completion callback, which is the only place
 * that actually persists the assistant's message (and therefore the only place that
 * can link an attachment to it via appendMessage()'s attachment_ids parameter).
 *
 * The tool itself has no access to that callback — it's invoked deep inside
 * laravel/ai's own tool-calling loop, the same "can't see the outer context" problem
 * PendingReservationTracker already solves for wallet reservations. Same fix here:
 * a request-scoped singleton both sides can reach via the container.
 *
 * Bound with $app->scoped(), not singleton() — see PendingReservationTracker's own
 * comment for why: this runs under Octane, where a true singleton would leak across
 * requests sharing the same worker process.
 */
class GeneratedAttachmentTracker
{
    /** @var string[] */
    private array $attachmentIds = [];

    public function add(string $attachmentId): void
    {
        $this->attachmentIds[] = $attachmentId;
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->attachmentIds;
    }
}
