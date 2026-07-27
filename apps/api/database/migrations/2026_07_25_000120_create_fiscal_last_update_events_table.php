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
        Schema::create('fiscal_last_update_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80)->nullable();
            $table->string('event_type', 80);
            $table->string('event_external_id', 160)->nullable();
            $table->string('event_hash', 64);
            $table->string('payload_digest', 64)->nullable();
            $table->string('status', 32)->default('RECEIVED');
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->bigInteger('directed_run_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'received_at']);
            $table->unique(['tenant_id', 'event_hash']);
            $table->index(['tenant_id', 'system_code', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_last_update_events');
    }
};
