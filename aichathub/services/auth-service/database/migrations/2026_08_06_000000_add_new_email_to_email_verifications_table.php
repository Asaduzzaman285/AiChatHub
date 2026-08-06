<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Powers the email-change flow: a row with new_email set means "confirm this
 * is your new email" rather than the original meaning ("verify your
 * registration email"), which stays new_email = null. See
 * EmailChangeController and EmailVerificationController::verify().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->string('new_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->dropColumn('new_email');
        });
    }
};
