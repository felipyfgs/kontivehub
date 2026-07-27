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
        Schema::create('serpro_async_job_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('job_type', 80);
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id')->nullable();
            $table->string('environment', 20)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable()->index();
            $table->integer('attempt')->default(0);
            $table->string('cursor')->nullable();
            $table->integer('pages_done')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('flag_checked_at_dispatch')->default(false);
            $table->boolean('flag_checked_at_handle')->default(false);
            $table->jsonb('progress')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'job_type']);
            $table->index(['job_type', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_async_job_runs');
    }
};
