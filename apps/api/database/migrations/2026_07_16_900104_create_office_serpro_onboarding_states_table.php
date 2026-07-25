<?php

use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Models\OfficeSerproOnboardingState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de onboarding SERPRO por office+ambiente.
 * Backfill: incomplete para todos os offices (produção).
 * Compatível com o stub 900401 (que no-ops se a tabela já existir).
 *
 * @see OfficeSerproOnboardingStatus
 * @see OfficeSerproOnboardingState
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_serpro_onboarding_states')) {
            Schema::create('office_serpro_onboarding_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
                $table->string('environment', 32);
                $table->string('status', 32)->default(OfficeSerproOnboardingStatus::Incomplete->value);
                $table->string('idempotency_key', 64)->nullable();
                $table->string('last_step', 64)->nullable();
                $table->string('actionable_code', 64)->nullable();
                $table->string('actionable_message', 500)->nullable();
                $table->string('technical_code', 64)->nullable();
                $table->string('technical_message', 500)->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('provisioning_started_at')->nullable();
                $table->timestamp('authorized_at')->nullable();
                $table->timestamp('last_transition_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['office_id', 'environment'], 'office_serpro_onboarding_office_env_uq');
                $table->index(['status', 'environment'], 'office_serpro_onboarding_status_env_idx');
            });
        }

        $this->backfillIncompleteForOffices();
    }

    public function down(): void
    {
        Schema::dropIfExists('office_serpro_onboarding_states');
    }

    private function backfillIncompleteForOffices(): void
    {
        $now = now();
        $environment = SerproEnvironment::Production->value;
        $status = OfficeSerproOnboardingStatus::Incomplete->value;

        $offices = DB::table('offices')->select('id')->orderBy('id')->get();

        foreach ($offices as $office) {
            $exists = DB::table('office_serpro_onboarding_states')
                ->where('office_id', $office->id)
                ->where('environment', $environment)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('office_serpro_onboarding_states')->insert([
                'office_id' => $office->id,
                'environment' => $environment,
                'status' => $status,
                'idempotency_key' => null,
                'last_step' => null,
                'actionable_code' => null,
                'actionable_message' => null,
                'technical_code' => null,
                'technical_message' => null,
                'correlation_id' => null,
                'ready_at' => null,
                'provisioning_started_at' => null,
                'authorized_at' => null,
                'last_transition_at' => $now,
                'metadata' => json_encode([
                    'seeded_by' => 'migration:2026_07_16_900104',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
