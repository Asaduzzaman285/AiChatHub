<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogInternalController extends Controller
{
    /**
     * POST /internal/audit-logs — every other service's admin-write endpoints
     * call this (via a per-service AuditLogClient) after a sensitive action
     * succeeds. auth-service writes its own admin actions directly to the
     * AuditLog model instead of looping back through HTTP to itself.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => 'nullable|uuid',
            'actor_type'    => 'nullable|string|max:30',
            'action'        => 'required|string|max:100',
            'resource_type' => 'required|string|max:50',
            'resource_id'   => 'nullable|uuid',
            'old_values'    => 'nullable|array',
            'new_values'    => 'nullable|array',
            'ip_address'    => 'nullable|string|max:45',
            'user_agent'    => 'nullable|string',
        ]);

        $log = AuditLog::create($data + ['actor_type' => $data['actor_type'] ?? 'admin']);

        return response()->json(['id' => $log->id], 201);
    }
}
