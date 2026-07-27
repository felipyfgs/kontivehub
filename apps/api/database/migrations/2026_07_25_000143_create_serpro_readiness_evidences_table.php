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
        Schema::create('serpro_readiness_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('serpro_readiness_run_id');
            $table->string('gate', 40);
            $table->string('scope', 20);
            $table->string('status', 32);
            $table->boolean('live_evidence')->default(false);
            $table->string('fingerprint', 64)->nullable();
            $table->string('document_revision', 80)->nullable();
            $table->string('sanitized_reason', 500)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('valid_until')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['gate', 'status']);
            $table->index(['serpro_readiness_run_id', 'gate']);
            $table->foreign(['serpro_readiness_run_id'])->references(['id'])->on('serpro_readiness_runs')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_readiness_evidences');
    }
};
