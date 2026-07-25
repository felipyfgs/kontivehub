<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segurança/ops SERPRO: hash encadeado de auditoria, controles runtime
 * persistentes (kill switch/rollout) e trilha de retenção/offboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_logs', 'chain_seq')) {
                $table->unsignedBigInteger('chain_seq')->nullable()->after('id');
            }
            if (! Schema::hasColumn('audit_logs', 'prev_hash')) {
                $table->string('prev_hash', 64)->nullable()->after('correlation_id');
            }
            if (! Schema::hasColumn('audit_logs', 'entry_hash')) {
                $table->string('entry_hash', 64)->nullable()->after('prev_hash');
            }
            $table->index('chain_seq');
            $table->index('entry_hash');
        });

        Schema::create('serpro_runtime_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('control_key', 80)->unique();
            $table->string('control_type', 40);
            $table->boolean('active')->default(false);
            $table->string('source', 40)->default('runtime');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['control_type', 'active']);
        });

        Schema::create('serpro_retention_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40);
            $table->string('status', 32)->default('PENDING');
            $table->string('trigger', 40)->default('OFFBOARDING');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('eligible_purge_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'category', 'status']);
            $table->index(['status', 'eligible_purge_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_retention_jobs');
        Schema::dropIfExists('serpro_runtime_controls');

        Schema::table('audit_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('audit_logs', 'entry_hash')) {
                $table->dropIndex(['entry_hash']);
                $table->dropColumn('entry_hash');
            }
            if (Schema::hasColumn('audit_logs', 'prev_hash')) {
                $table->dropColumn('prev_hash');
            }
            if (Schema::hasColumn('audit_logs', 'chain_seq')) {
                $table->dropIndex(['chain_seq']);
                $table->dropColumn('chain_seq');
            }
        });
    }
};
