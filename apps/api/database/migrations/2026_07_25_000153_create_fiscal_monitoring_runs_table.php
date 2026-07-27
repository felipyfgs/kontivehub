<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_monitoring_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('fiscal_category_id')->nullable();
            $table->bigInteger('competence_id')->nullable();
            $table->bigInteger('schedule_id')->nullable();
            $table->bigInteger('last_update_event_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('trigger', 30);
            $table->string('idempotency_key', 160);
            $table->string('status', 32)->default('QUEUED');
            $table->string('result', 30)->nullable();
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->string('mutability', 20)->default('READ_ONLY');
            $table->integer('attempt')->default(1);
            $table->bigInteger('parent_run_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('progress_cursor', 64)->nullable();
            $table->jsonb('progress')->nullable();
            $table->integer('items_processed')->default(0);
            $table->integer('pages_processed')->default(0);
            $table->string('skip_reason', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->bigInteger('triggered_by')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('requeued_at')->nullable();
            $table->timestampsTz();
            $table->string('source_provenance', 20)->nullable()->index();
            $table->string('verification_state', 20)->nullable();
            $table->string('operation_key', 120)->nullable();

            $table->index(['tenant_id', 'client_id', 'system_code', 'service_code'], 'fiscal_monitoring_runs_tenant_id_client_id_system__62f528c0a7');
            $table->index(['tenant_id', 'competence_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'locked_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_category_id'])->references(['id'])->on('fiscal_categories')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['schedule_id'])->references(['id'])->on('fiscal_monitoring_schedules')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['triggered_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_monitoring_runs');
    }
};
