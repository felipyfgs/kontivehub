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
        Schema::create('client_communication_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dispatch_id');
            $table->string('status', 20);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->nullable();
            $table->string('source', 40)->default('INTERNAL');
            $table->string('provider_event_id')->nullable();
            $table->string('payload_digest', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'dispatch_id', 'occurred_at'], 'client_communication_events_tenant_id_dispatch_id__a0c63e282f');
            $table->unique(['tenant_id', 'provider_event_id']);
            $table->foreign(['dispatch_id'])->references(['id'])->on('client_communication_dispatches')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_communication_events');
    }
};
