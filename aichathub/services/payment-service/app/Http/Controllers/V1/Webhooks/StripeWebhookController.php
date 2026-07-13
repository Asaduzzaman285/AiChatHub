<?php

namespace App\Http\Controllers\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhookJob;
use App\Models\WebhookEvent;
use App\Services\StripeGateway;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(private StripeGateway $stripe) {}

    public function handle(Request $request): \Illuminate\Http\Response
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        // 1. Verify signature — reject anything that doesn't verify
        $event = $this->stripe->verifyWebhook($payload, $signature);
        if (! $event) {
            return response('Invalid signature', 400);
        }

        // 2. Idempotency — silently skip if already processed
        $existing = WebhookEvent::where('gateway', 'stripe')
            ->where('gateway_reference', $event->id)
            ->first();

        if ($existing) {
            return response('Already processed', 200);
        }

        // 3. Store the event (marks as processing)
        WebhookEvent::create([
            'gateway'           => 'stripe',
            'event_type'        => $event->type,
            'gateway_reference' => $event->id,
            'status'            => 'pending',
            'payload'           => json_decode($payload, true),
        ]);

        // 4. Dispatch to queue — never block the webhook response
        ProcessStripeWebhookJob::dispatch($event->id);

        return response('OK', 200);
    }
}
