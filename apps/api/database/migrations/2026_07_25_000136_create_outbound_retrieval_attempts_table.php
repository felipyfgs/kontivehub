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
        Schema::create('outbound_retrieval_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('outbound_retrieval_request_id');
            $table->bigInteger('outbound_capture_profile_id');
            $table->bigInteger('outbound_number_state_id')->nullable();
            $table->string('access_key', 50);
            $table->string('correlation_id', 64);
            $table->smallInteger('attempt_number');
            $table->string('result', 40);
            $table->string('failure_reason', 60)->nullable();
            $table->string('transport_outcome', 40)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->string('parser_version', 20)->nullable();
            $table->integer('get_latency_ms')->nullable();
            $table->integer('post_latency_ms')->nullable();
            $table->integer('total_latency_ms')->nullable();
            $table->string('sanitized_detail', 500)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->string('model', 4)->nullable();
            $table->string('origin', 40)->nullable();
            $table->string('cohort_id', 64)->nullable();
            $table->smallInteger('exchanges_reserved')->nullable();
            $table->smallInteger('exchanges_consumed')->nullable();
            $table->string('reservation_id', 64)->nullable();

            $table->index(['cohort_id', 'created_at']);
            $table->index(['tenant_id', 'origin', 'result']);
            $table->unique(['outbound_retrieval_request_id', 'attempt_number'], 'outbound_retrieval_attempts_outbound_retrieval_req_25da71124b');
            $table->index(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'correlation_id']);
            $table->index(['tenant_id', 'result', 'created_at']);
            $table->foreign(['outbound_retrieval_request_id'], 'outbound_retrieval_attempts_outbound_retrieval_req_cacd77c971')->references(['id'])->on('outbound_retrieval_requests')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_capture_profile_id'])->references(['id'])->on('outbound_capture_profiles')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_number_state_id'])->references(['id'])->on('outbound_number_states')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_retrieval_attempts');
    }
};
