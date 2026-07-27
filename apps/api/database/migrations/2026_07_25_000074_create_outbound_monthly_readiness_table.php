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
        Schema::create('outbound_monthly_readiness', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('competence', 7);
            $table->string('status', 32)->default('NOT_READY');
            $table->integer('known_total')->default(0);
            $table->integer('captured_total')->default(0);
            $table->integer('pending_total')->default(0);
            $table->bigInteger('export_id')->nullable();
            $table->string('manifest_vault_object_id', 26)->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('confirmation_notes')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'competence']);
            $table->foreign(['confirmed_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['export_id'])->references(['id'])->on('document_exports')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_monthly_readiness');
    }
};
