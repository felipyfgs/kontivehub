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
        Schema::create('serpro_usage_incidents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 40);
            $table->string('severity', 20)->default('OPEN');
            $table->string('environment', 20)->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->string('cycle_code', 40)->nullable();
            $table->string('sanitized_summary', 500);
            $table->bigInteger('expected_micros')->nullable();
            $table->bigInteger('observed_micros')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'severity']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_incidents');
    }
};
