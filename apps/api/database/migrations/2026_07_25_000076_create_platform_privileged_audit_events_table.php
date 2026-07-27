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
        Schema::create('platform_privileged_audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('actor_user_id');
            $table->bigInteger('tenant_id');
            $table->string('action', 80);
            $table->string('target_type', 80)->nullable();
            $table->bigInteger('target_id')->nullable();
            $table->string('result', 20)->default('SUCCESS');
            $table->string('request_id', 64)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['tenant_id', 'action', 'created_at'], 'platform_privileged_audit_events_tenant_id_action__a394b854bc');
            $table->index(['target_type', 'target_id']);
            $table->foreign(['actor_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_privileged_audit_events');
    }
};
