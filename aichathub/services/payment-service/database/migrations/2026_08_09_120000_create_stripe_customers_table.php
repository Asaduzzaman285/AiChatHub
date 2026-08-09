<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Stripe Customer per user, created lazily the first time they save a card.
 * Needed for off-session charges (auto-debit, subscription renewals) to go through
 * Stripe's proper off_session flow instead of a bare payment_method with no
 * Customer attached — real cards under SCA can reject the latter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_customers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->string('stripe_customer_id', 255)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_customers');
    }
};
