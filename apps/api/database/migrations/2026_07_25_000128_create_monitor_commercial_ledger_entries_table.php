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
        Schema::create('monitor_commercial_ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('monitor_key', 40);
            $table->string('origin', 20);
            $table->string('dispatch_state', 30);
            $table->smallInteger('quota_units')->default(0);
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            $table->string('period_key', 40);
            $table->string('idempotency_key', 120)->unique();
            $table->string('technical_correlation_id', 64)->nullable()->index();
            $table->bigInteger('technical_usage_entry_id')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('blocked_reason', 80)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'monitor_key', 'period_key'], 'monitor_commercial_ledger_entries_tenant_id_client_c930963e30');
            $table->index(['tenant_id', 'origin', 'dispatch_state'], 'monitor_commercial_ledger_entries_tenant_id_origin_de4b139f3a');
            $table->index(['tenant_id', 'period_key']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_commercial_ledger_entries');
    }
};
