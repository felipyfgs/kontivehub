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
        Schema::create('tax_guide_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tax_guide_id')->unique();
            $table->integer('version_number');
            $table->boolean('is_current')->default(false);
            $table->string('emission_status', 30)->default('PENDING');
            $table->bigInteger('replaces_version_id')->nullable();
            $table->bigInteger('superseded_by_version_id')->nullable();
            $table->string('identifier_code', 120)->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('vault_object_id', 26)->nullable();
            $table->string('content_type', 80)->nullable();
            $table->bigInteger('byte_size')->default(0);
            $table->string('idempotency_key', 160);
            $table->string('correlation_id', 64)->nullable();
            $table->bigInteger('usage_reservation_id')->nullable();
            $table->string('remote_protocol', 160)->nullable();
            $table->string('risk_level', 20)->default('HIGH');
            $table->jsonb('confirmation_summary')->nullable();
            $table->bigInteger('confirmed_by_user_id')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->bigInteger('issued_by')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('reconcile_after')->nullable();
            $table->smallInteger('reconcile_attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'tax_guide_id', 'is_current']);
            $table->unique(['tenant_id', 'tax_guide_id', 'version_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'emission_status', 'reconcile_after'], 'tax_guide_versions_tenant_id_emission_status_recon_ee8679f5fc');
            $table->foreign(['confirmed_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['issued_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_guide_versions');
    }
};
