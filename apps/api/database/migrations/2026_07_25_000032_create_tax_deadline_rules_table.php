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
        Schema::create('tax_deadline_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('calendar_version_id');
            $table->bigInteger('obligation_definition_id')->nullable();
            $table->string('period_granularity', 20)->default('MONTHLY');
            $table->smallInteger('due_day')->nullable();
            $table->smallInteger('due_month_offset')->default(1);
            $table->smallInteger('fixed_due_month')->nullable();
            $table->smallInteger('fixed_due_day')->nullable();
            $table->string('business_day_adjustment', 20)->default('NONE');
            $table->string('timezone', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['calendar_version_id', 'obligation_definition_id'], 'tax_deadline_rules_calendar_version_id_obligation__abdc20f08a');
            $table->foreign(['calendar_version_id'])->references(['id'])->on('tax_deadline_calendar_versions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['obligation_definition_id'])->references(['id'])->on('tax_obligation_definitions')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_deadline_rules');
    }
};
