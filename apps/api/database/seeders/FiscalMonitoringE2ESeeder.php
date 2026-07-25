<?php

namespace Database\Seeders;

use App\Enums\OfficeRole;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TaxRegimeCode;
use App\Enums\Work\ProcessOrigin;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficeSubscription;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\User;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Seeder;
use LogicException;

/** Fixtures determinísticas e exclusivamente locais para o gate de navegador. */
class FiscalMonitoringE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('FiscalMonitoringE2ESeeder exige APP_ENV=testing.');
        }

        $primaryOffice = Office::query()->updateOrCreate(
            ['slug' => 'contador'],
            ['name' => 'Escritório Contábil Demo', 'is_active' => true],
        );
        $this->ensureSubscription($primaryOffice);

        $users = [];
        foreach ([
            'admin@example.com' => ['name' => 'Admin E2E', 'role' => OfficeRole::Admin],
            'operador@example.com' => ['name' => 'Operador E2E', 'role' => OfficeRole::Operator],
            'viewer@example.com' => ['name' => 'Viewer E2E', 'role' => OfficeRole::Viewer],
        ] as $email => $definition) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $definition['name'],
                    'password' => 'password',
                    'is_active' => true,
                    'password_change_required' => false,
                ],
            );
            $user->forceFill([
                'email_verified_at' => now(),
                'selected_office_id' => $primaryOffice->id,
            ])->saveQuietly();
            OfficeMembership::query()->updateOrCreate(
                ['office_id' => $primaryOffice->id, 'user_id' => $user->id],
                ['role' => $definition['role'], 'is_active' => true],
            );
            $users[$email] = $user;
        }

        $this->call(FiscalMonitoringDemoSeeder::class);

        $secondOffice = Office::query()->updateOrCreate(
            ['slug' => 'e2e-second-office'],
            ['name' => 'Escritório E2E Secundário', 'is_active' => true],
        );
        $this->ensureSubscription($secondOffice);

        foreach ([
            'admin@example.com' => OfficeRole::Admin,
            'operador@example.com' => OfficeRole::Operator,
            'viewer@example.com' => OfficeRole::Viewer,
        ] as $email => $role) {
            $user = $users[$email];
            OfficeMembership::query()->updateOrCreate(
                ['office_id' => $secondOffice->id, 'user_id' => $user->id],
                ['role' => $role, 'is_active' => true],
            );
        }

        $this->seedCriticalJourneyFixtures($primaryOffice, '91919191', 'Primário');
        $this->seedCriticalJourneyFixtures($secondOffice, '92929292', 'Secundário');
    }

    private function ensureSubscription(Office $office): void
    {
        OfficeSubscription::query()->updateOrCreate(
            ['office_id' => $office->id],
            [
                'plan' => SubscriptionPlan::Professional,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->startOfDay(),
                'current_period_starts_at' => now()->startOfMonth(),
                'current_period_ends_at' => now()->endOfMonth(),
                'monthly_api_quota' => 10_000,
                'max_clients' => 150,
                'max_users' => 25,
            ],
        );
    }

    private function seedCriticalJourneyFixtures(Office $office, string $rootCnpj, string $label): void
    {
        $client = Client::query()->updateOrCreate(
            ['office_id' => $office->id, 'root_cnpj' => $rootCnpj],
            [
                'legal_name' => "Cliente E2E {$label}",
                'display_name' => "E2E {$label}",
                'tax_regime' => TaxRegimeCode::SimplesNacional,
                'notes' => 'Fixture determinística do grafo de testabilidade.',
                'is_active' => true,
                'registration_source' => RegistrationSource::Manual,
            ],
        );

        Establishment::query()->updateOrCreate(
            ['cnpj' => EstablishmentFactory::cnpjWithRoot($rootCnpj)],
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'trade_name' => "E2E {$label}",
                'is_matrix' => true,
                'is_active' => true,
                'capture_enabled' => false,
                'registration_status' => RegistrationStatus::Unknown,
                'registration_source' => RegistrationSource::Manual,
            ],
        );

        $process = OperationalProcess::query()->updateOrCreate(
            ['office_id' => $office->id, 'title' => "Processo E2E {$label}"],
            [
                'client_id' => $client->id,
                'origin' => ProcessOrigin::Manual,
                'competence' => '2026-07',
                'due_date' => '2026-07-31',
                'subject_to_fine' => false,
                'status' => ProcessStatus::AFazer,
                'lock_version' => 1,
            ],
        );

        OperationalTask::query()->updateOrCreate(
            ['operational_process_id' => $process->id, 'sort_order' => 1],
            [
                'office_id' => $office->id,
                'title' => "Tarefa E2E {$label}",
                'status' => TaskStatus::AFazer,
                'due_date' => '2026-07-30',
                'is_required' => true,
                'is_critical' => false,
                'requires_evidence' => false,
                'lock_version' => 1,
            ],
        );
    }
}
