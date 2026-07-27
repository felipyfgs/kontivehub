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
        Schema::create('mit_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('competence_id')->nullable();
            $table->string('period_key', 20);
            $table->string('encerramento_status', 30)->default('UNKNOWN');
            $table->string('situacao_status', 30)->default('UNKNOWN');
            $table->string('dctfweb_transmission_status', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('PARTIAL');
            $table->timestampTz('encerrado_at')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->bigInteger('current_snapshot_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'period_key']);
            $table->index(['tenant_id', 'encerramento_status']);
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
        Schema::dropIfExists('mit_assessments');
    }
};
