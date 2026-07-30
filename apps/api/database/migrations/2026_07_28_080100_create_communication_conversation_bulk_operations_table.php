<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversation_bulk_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->bigInteger('tenant_id');
            $table->bigInteger('requested_by_user_id');
            $table->bigInteger('requested_by_membership_id')->nullable();
            $table->string('access_mode', 32);
            $table->string('idempotency_key', 80);
            $table->char('payload_digest', 64);
            $table->string('action', 32);
            $table->jsonb('params')->nullable();
            $table->string('status', 40)->default('QUEUED');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'idempotency_key'],
                'communication_bulk_ops_tenant_idempotency_uidx',
            );
            $table->index(
                ['tenant_id', 'status', 'created_at'],
                'communication_bulk_ops_tenant_status_created_idx',
            );
            $table->index(
                ['tenant_id', 'requested_by_user_id', 'created_at'],
                'communication_bulk_ops_tenant_actor_created_idx',
            );
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('requested_by_membership_id')
                ->references('id')
                ->on('tenant_memberships')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_bulk_operations');
    }
};
