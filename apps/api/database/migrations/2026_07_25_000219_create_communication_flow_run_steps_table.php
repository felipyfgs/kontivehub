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
        Schema::create('communication_flow_run_steps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('run_id');
            $table->string('node_id', 64);
            $table->string('node_type', 40);
            $table->string('status', 32)->default('pending');
            $table->timestampTz('entered_at')->nullable();
            $table->timestampTz('exited_at')->nullable();
            $table->jsonb('result_meta')->nullable();
            $table->timestampsTz();
            $table->integer('seq')->default(1);
            $table->string('effect_key', 160)->nullable();

            $table->unique(['run_id', 'effect_key']);
            $table->index(['run_id', 'entered_at']);
            $table->unique(['run_id', 'node_id', 'seq']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('communication_flow_runs')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_run_steps');
    }
};
