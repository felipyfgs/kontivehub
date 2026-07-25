<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding: lifecycle de Office, password_change_required, account_activations e
 * idempotência de criação de Office pendente.
 *
 * @see openspec/changes/cadastrar-ativar-offices-usuarios
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('lifecycle_status', 32)
                ->default('ACTIVE')
                ->after('is_active');
            $table->index('lifecycle_status');
        });

        // Backfill: todos os offices existentes permanecem ACTIVE operacional.
        DB::table('offices')->whereNull('lifecycle_status')->orWhere('lifecycle_status', '')->update([
            'lifecycle_status' => 'ACTIVE',
        ]);
        DB::table('offices')->update(['lifecycle_status' => DB::raw("COALESCE(NULLIF(lifecycle_status, ''), 'ACTIVE')")]);

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_change_required')
                ->default(false)
                ->after('is_active');
        });

        Schema::create('account_activations', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 40);
            $table->string('method', 32);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('office_membership_id')->nullable();
            $table->foreignId('platform_membership_id')->nullable()->constrained('platform_memberships')->nullOnDelete();
            $table->string('email_normalized');
            $table->string('secret_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->index(['office_id', 'purpose']);
            $table->index(['purpose', 'consumed_at']);
            $table->index(['email_normalized', 'method']);
            $table->index('secret_hash');
            $table->index(['office_membership_id']);
        });

        // FK lógica para pivot office_user (não cascade rígido — memberships legadas).
        if (Schema::hasTable('office_user')) {
            Schema::table('account_activations', function (Blueprint $table) {
                $table->foreign('office_membership_id')
                    ->references('id')
                    ->on('office_user')
                    ->nullOnDelete();
            });
        }

        Schema::create('office_creation_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_creation_idempotency');
        Schema::dropIfExists('account_activations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_change_required');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_status']);
            $table->dropColumn('lifecycle_status');
        });
    }
};
