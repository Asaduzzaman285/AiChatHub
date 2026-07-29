<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /admin/dashboard — auth-service's own contribution to the admin dashboard. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'total_users'              => User::count(),
            'active_users'             => User::where('status', 'active')->count(),
            'suspended_users'          => User::where('status', 'suspended')->count(),
            'pending_verification'     => User::where('status', 'pending_verification')->count(),
            'new_registrations_7d'     => User::where('created_at', '>=', now()->subDays(7))->count(),
            'new_registrations_30d'    => User::where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }
}
