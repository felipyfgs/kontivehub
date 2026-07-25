<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidência OAuth versionada, limites quantitativos por ambiente/Office e
 * metadados de gates (responsável) para configuração global SERPRO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_credential_connection_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serpro_credential_version_id')
                ->constrained('serpro_credential_versions')
                ->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('fingerprint_sha256', 64);
            $table->boolean('success')->default(false);
            $table->timestampTz('tested_at');
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('sanitized_message', 500)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->boolean('invalidated')->default(false);
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 200)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['serpro_credential_version_id', 'success', 'expires_at'],
                'serpro_conn_ev_ver_ok_exp_idx'
            );
            $table->index(['environment', 'fingerprint_sha256'], 'serpro_conn_ev_env_fp_idx');
        });

        Schema::create('serpro_quantity_usage_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20);
            $table->unsignedTinyInteger('cycle_start_day')->default(1);
            $table->unsignedTinyInteger('alert_percent')->default(80);
            $table->unsignedBigInteger('global_limit_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('environment', 'serpro_qty_limit_env_uq');
        });

        Schema::create('serpro_office_quantity_usage_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->unsignedBigInteger('limit_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'environment'], 'serpro_office_qty_limit_uq');
            $table->index(['environment', 'is_active'], 'serpro_office_qty_env_act_idx');
        });

        if (Schema::hasTable('serpro_external_gates')) {
            Schema::table('serpro_external_gates', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_external_gates', 'responsible_name')) {
                    $table->string('responsible_name', 200)->nullable()->after('answer_summary');
                }
                if (! Schema::hasColumn('serpro_external_gates', 'reference_date')) {
                    $table->date('reference_date')->nullable()->after('responsible_name');
                }
                if (! Schema::hasColumn('serpro_external_gates', 'environment')) {
                    $table->string('environment', 20)->default('PRODUCTION')->after('kind');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_external_gates')) {
            Schema::table('serpro_external_gates', function (Blueprint $table): void {
                if (Schema::hasColumn('serpro_external_gates', 'responsible_name')) {
                    $table->dropColumn('responsible_name');
                }
                if (Schema::hasColumn('serpro_external_gates', 'reference_date')) {
                    $table->dropColumn('reference_date');
                }
                if (Schema::hasColumn('serpro_external_gates', 'environment')) {
                    $table->dropColumn('environment');
                }
            });
        }

        Schema::dropIfExists('serpro_office_quantity_usage_limits');
        Schema::dropIfExists('serpro_quantity_usage_limits');
        Schema::dropIfExists('serpro_credential_connection_evidences');
    }
};
