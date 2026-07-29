<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces config/admin_roles.php (deleted alongside this migration) — roles
 * are now real, admin-editable objects instead of a fixed list in a config
 * file. admin_users.role stays a plain string column (no migration needed
 * there — existing values already match these seeded names) but gains a real
 * FK to roles.name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 100)->unique();
            $table->jsonb('permissions')->default('[]');
            $table->timestamps();
        });

        DB::table('roles')->insert([
            [
                'id'          => DB::raw('uuid_generate_v4()'),
                'name'        => 'platform_admin',
                'permissions' => json_encode(['*']),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'id'          => DB::raw('uuid_generate_v4()'),
                'name'        => 'finance_admin',
                'permissions' => json_encode([
                    'payments.view', 'payments.refund',
                    'wallet.view', 'wallet.adjust',
                    'subscriptions.view', 'users.view', 'dashboard.view',
                ]),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'id'          => DB::raw('uuid_generate_v4()'),
                'name'        => 'support',
                'permissions' => json_encode([
                    'users.view', 'users.suspend', 'subscriptions.view',
                    'wallet.view', 'payments.view', 'dashboard.view',
                ]),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        // admin_users.role can now only ever reference a real role. Existing
        // rows already have string values matching one of the three seeded
        // above, so no backfill needed before adding this constraint.
        Schema::table('admin_users', function (Blueprint $table) {
            $table->foreign('role')->references('name')->on('roles')->restrictOnDelete();
        });

        // permissions is superseded by roles.permissions (resolved live via
        // AdminUser::role()) — see AdminUser model.
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->jsonb('permissions')->default('{}');
        });

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropForeign(['role']);
        });

        Schema::dropIfExists('roles');
    }
};
