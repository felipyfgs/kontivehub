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
        Schema::create('dctfweb_declarations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('competence_id')->nullable();
            $table->string('period_key', 20);
            $table->string('declaration_type', 30)->default('ORIGINAL');
            $table->string('transmission_status', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('FULL');
            $table->string('receipt_number', 80)->nullable();
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampTz('official_at')->nullable();
            $table->integer('evidence_version')->default(0);
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->bigInteger('current_snapshot_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->string('category', 40)->default('GERAL_MENSAL');
            $table->string('declaration_state', 40)->default('UNVERIFIED');
            $table->boolean('no_movement')->nullable();
            $table->timestampTz('last_productive_consulted_at')->nullable();
            $table->boolean('calendar_verified')->default(false);
            $table->string('calendar_version_code', 60)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('state_reason', 80)->nullable();

            $table->unique(['tenant_id', 'client_id', 'category', 'period_key'], 'dctfweb_declarations_tenant_id_client_id_category__9273cc1ea0');
            $table->index(['tenant_id', 'client_id', 'situation']);
            $table->index(['tenant_id', 'transmission_status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dctfweb_declarations');
    }
};
