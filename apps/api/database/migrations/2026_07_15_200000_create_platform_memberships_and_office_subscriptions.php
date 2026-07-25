<?php

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de controle SaaS:
 * - platform_memberships: global (SEM office_id)
 * - office_subscriptions: tenant-scoped (COM office_id)
 *
 * 2.2: escritórios existentes recebem assinatura ACTIVE determinística.
 * Não altera memberships office_user nem dados fiscais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('office_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 40);
            $table->string('status', 32);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->unsignedInteger('monthly_api_quota')->nullable();
            $table->unsignedInteger('max_clients')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->json('limits')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Uma assinatura corrente por escritório (histórico via auditoria no MVP).
            $table->unique('office_id');
            $table->index(['status']);
        });

        // 2.2 — backfill determinístico: todo office existente → ACTIVE / PROFESSIONAL
        $now = now();
        $plan = SubscriptionPlan::Professional;
        $limits = $plan->defaultLimits();

        $offices = DB::table('offices')->select('id')->orderBy('id')->get();

        foreach ($offices as $office) {
            DB::table('office_subscriptions')->insert([
                'office_id' => $office->id,
                'plan' => $plan->value,
                'status' => SubscriptionStatus::Active->value,
                'trial_ends_at' => null,
                'starts_at' => $now,
                'ends_at' => null,
                'current_period_starts_at' => $now->copy()->startOfMonth(),
                'current_period_ends_at' => $now->copy()->endOfMonth(),
                'monthly_api_quota' => $limits['monthly_api_quota'],
                'max_clients' => $limits['max_clients'],
                'max_users' => $limits['max_users'],
                'limits' => json_encode([
                    'monthly_api_quota' => $limits['monthly_api_quota'],
                    'max_clients' => $limits['max_clients'],
                    'max_users' => $limits['max_users'],
                    'seeded_by' => 'migration:2026_07_15_200000',
                ], JSON_THROW_ON_ERROR),
                'notes' => 'Assinatura ACTIVE criada deterministicamente na migração SaaS.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('office_subscriptions');
        Schema::dropIfExists('platform_memberships');
    }
};
