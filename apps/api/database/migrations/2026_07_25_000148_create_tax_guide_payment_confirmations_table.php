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
        Schema::create('tax_guide_payment_confirmations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tax_guide_id');
            $table->bigInteger('tax_guide_version_id')->nullable();
            $table->string('source', 80);
            $table->string('external_id', 160);
            $table->bigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->timestampTz('paid_at')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('vault_object_id', 26)->nullable();
            $table->string('content_type', 80)->nullable();
            $table->bigInteger('byte_size')->default(0);
            $table->string('evidence_digest', 64);
            $table->jsonb('metadata')->nullable();
            $table->bigInteger('recorded_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->string('payment_status_normalized', 30)->nullable();
            $table->string('official_raw_code', 80)->nullable();

            $table->unique(['tenant_id', 'evidence_digest'], 'tax_guide_payment_confirmations_tenant_id_evidence_897b39bdf3');
            $table->index(['tenant_id', 'tax_guide_id']);
            $table->unique(['tenant_id', 'source', 'external_id'], 'tax_guide_payment_confirmations_tenant_id_source_e_474eb48bdc');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['recorded_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tax_guide_id'])->references(['id'])->on('tax_guides')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tax_guide_version_id'])->references(['id'])->on('tax_guide_versions')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_guide_payment_confirmations');
    }
};
