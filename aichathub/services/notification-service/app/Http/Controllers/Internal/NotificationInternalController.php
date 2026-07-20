<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationInternalController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * POST /internal/notifications/send
     * Generic entry point — every other service calls this instead of each owning
     * its own mail-sending logic, matching how billing/wallet internal endpoints
     * work elsewhere in this project.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'             => 'required|in:welcome,receipt,low_balance,renewal_failed',
            'user_id'          => 'required|uuid',
            'email'            => 'required|email',
            'data'             => 'required|array',
            'idempotency_key'  => 'nullable|string|max:255',
        ]);

        $sent = $this->notifications->send(
            $data['type'],
            $data['user_id'],
            $data['email'],
            $data['data'],
            $data['idempotency_key'] ?? null,
        );

        return response()->json(['success' => $sent], $sent ? 200 : 502);
    }
}
