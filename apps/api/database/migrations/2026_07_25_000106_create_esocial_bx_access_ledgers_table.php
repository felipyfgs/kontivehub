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
        Schema::create('esocial_bx_access_ledgers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('employer_hash', 64);
            $table->string('environment', 20);
            $table->string('operation', 40);
            $table->date('access_date');
            $table->string('status', 24)->default('RESERVED');
            $table->smallInteger('http_status')->nullable();
            $table->string('official_code', 8)->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['employer_hash', 'environment', 'access_date'], 'esocial_bx_access_ledgers_employer_hash_environmen_23185610e6');
            $table->index(['tenant_id', 'client_id', 'created_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esocial_bx_access_ledgers');
    }
};
