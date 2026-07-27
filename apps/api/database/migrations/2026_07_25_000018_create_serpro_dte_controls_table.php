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
        Schema::create('serpro_dte_controls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('operation_key', 120)->default('dte.consultar')->unique();
            $table->string('mode', 20)->default('DISABLED');
            $table->bigInteger('pilot_tenant_id')->nullable();
            $table->bigInteger('pilot_client_id')->nullable();
            $table->integer('limited_max_quantity')->nullable();
            $table->integer('limited_used_quantity')->default(0);
            $table->string('cycle_code', 40)->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->bigInteger('promoted_by_user_id')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->bigInteger('disabled_by_user_id')->nullable();
            $table->string('disable_reason', 500)->nullable();
            $table->smallInteger('alert_percent')->default(80);
            $table->boolean('alert_80_emitted')->default(false);
            $table->boolean('alert_100_emitted')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['mode', 'pilot_tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_dte_controls');
    }
};
