<?php

use App\Enums\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlements comerciais de monitores SERPRO (separados de monthly_api_quota técnico).
 * - commercial_monitor_units: 5 / 7 / 10 por cliente+monitor+período
 * - negotiated_client_limit: override PLATFORM_ADMIN acima do máximo do plano (100/150/200)
 *
 * monthly_api_quota e UsageBudgetGate permanecem controles técnicos intactos.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_subscriptions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('commercial_monitor_units')->nullable()->after('monthly_api_quota');
            $table->unsignedInteger('negotiated_client_limit')->nullable()->after('max_clients');
        });

        // Backfill determinístico por plano; monthly_api_quota não é alterado.
        $rows = DB::table('office_subscriptions')->select(['id', 'plan'])->orderBy('id')->get();
        foreach ($rows as $row) {
            $plan = SubscriptionPlan::tryFrom((string) $row->plan) ?? SubscriptionPlan::Professional;
            DB::table('office_subscriptions')->where('id', $row->id)->update([
                'commercial_monitor_units' => $plan->commercialMonitorUnits(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('office_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['commercial_monitor_units', 'negotiated_client_limit']);
        });
    }
};
