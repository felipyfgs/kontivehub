<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política mensal de execução automática por office + monitor (dia 1–28).
 * Default determinístico via MonitorScheduleDayHasher quando is_custom=false.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_monitor_schedule_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('monitor_key', 40);
            $table->unsignedTinyInteger('day_of_month');
            $table->boolean('is_custom')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'monitor_key'], 'omsp_office_monitor_uq');
            $table->index(['day_of_month', 'monitor_key'], 'omsp_day_monitor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_monitor_schedule_policies');
    }
};
