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
        Schema::create('tenant_autxml_enrollments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_fiscal_identity_id');
            $table->bigInteger('establishment_id');
            $table->string('status', 32)->default('PENDING');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['establishment_id', 'status']);
            $table->unique(['tenant_fiscal_identity_id', 'establishment_id'], 'tenant_autxml_enrollments_tenant_fiscal_identity_i_92d65e2fcf');
            $table->index(['tenant_id', 'status']);
            $table->foreign(['confirmed_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_fiscal_identity_id'])->references(['id'])->on('tenant_fiscal_identities')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_autxml_enrollments');
    }
};
