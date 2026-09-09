<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which frontend (app.alveta.ai vs staging.alveta.ai) a registration
 * actually came from, so EmailVerificationController::verify() can redirect
 * back to that same origin once the link is clicked — potentially days later,
 * via a plain email link with no Origin header of its own to read. Without
 * this, verify() always redirected to the single static FRONTEND_URL,
 * regardless of where the user actually signed up (confirmed live: a
 * staging.alveta.ai registration verified and landed the user on
 * app.alveta.ai instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->string('origin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
