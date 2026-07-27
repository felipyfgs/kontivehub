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
        Schema::create('mailbox_monitoring_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 24)->default('ECONOMICO');
            $table->string('daily_time', 5)->default('00:30');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->smallInteger('reconciliation_days')->default(30);
            $table->smallInteger('auto_detail_limit')->default(0);
            $table->bigInteger('monthly_budget_micros')->nullable();
            $table->timestampTz('last_dispatched_at')->nullable();
            $table->timestampTz('next_due_at')->nullable();
            $table->timestampsTz();

            $table->index(['enabled', 'next_due_at']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_monitoring_settings');
    }
};
