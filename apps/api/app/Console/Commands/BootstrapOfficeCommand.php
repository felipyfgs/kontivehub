<?php

namespace App\Console\Commands;

use App\Enums\OfficeRole;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Office;
use App\Models\OfficeSubscription;
use App\Models\PlatformMembership;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapOfficeCommand extends Command
{
    protected $signature = 'app:bootstrap-office
        {--name= : Nome do escritório}
        {--slug= : Slug do escritório}
        {--admin-name= : Nome do administrador}
        {--admin-email= : E-mail do administrador}';

    protected $description = 'Cria o primeiro escritório e conta dual (Proprietário PLATFORM_ADMIN + Office ADMIN)';

    public function handle(PlatformOwnerService $owners): int
    {
        if (Office::query()->exists()
            || PlatformMembership::query()->exists()
            || User::query()->exists()
            || PlatformSetting::query()->exists()) {
            $this->error('Instalação já possui Office, usuário, proprietário ou onboarding. Bootstrap recusado.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Nome do escritório');
        $slug = $this->option('slug') ?: Str::slug((string) $name);
        $adminName = $this->option('admin-name') ?: $this->ask('Nome do administrador');
        $adminEmail = $this->option('admin-email') ?: $this->ask('E-mail do administrador');
        // Nunca aceite senha por argumento: argv pode aparecer no histórico e na lista de processos.
        $adminPassword = $this->secret('Senha do administrador');

        $validator = Validator::make([
            'name' => $name,
            'slug' => $slug,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $slug, $adminName, $adminEmail, $adminPassword, $owners): void {
                if (Office::query()->exists()
                    || PlatformMembership::query()->exists()
                    || User::query()->exists()
                    || PlatformSetting::query()->exists()) {
                    throw PlatformOwnerException::alreadyExists(
                        'Instalação já possui Office, usuário, proprietário ou onboarding.',
                    );
                }

                $office = Office::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'is_active' => true,
                ]);

                $user = User::query()->create([
                    'name' => $adminName,
                    'email' => Str::lower($adminEmail),
                    'password' => Hash::make($adminPassword),
                    'is_active' => true,
                    'selected_office_id' => $office->id,
                ]);

                // Membership real de Office ADMIN
                $office->users()->attach($user->id, [
                    'role' => OfficeRole::Admin->value,
                    'is_active' => true,
                ]);

                // Proprietário singleton com Office padrão = primeiro Office
                $owners->createOwner($user, isActive: true, defaultOfficeId: $office->id);

                // Assinatura ACTIVE para o tenant (sem isso, mutações HTTP retornam 403 MISSING).
                $plan = SubscriptionPlan::Professional;
                $limits = $plan->defaultLimits();
                $now = now();
                OfficeSubscription::query()->create([
                    'office_id' => $office->id,
                    'plan' => $plan,
                    'status' => SubscriptionStatus::Active,
                    'trial_ends_at' => null,
                    'starts_at' => $now,
                    'ends_at' => null,
                    'current_period_starts_at' => $now->copy()->startOfMonth(),
                    'current_period_ends_at' => $now->copy()->endOfMonth(),
                    'monthly_api_quota' => $limits['monthly_api_quota'],
                    'max_clients' => $limits['max_clients'],
                    'max_users' => $limits['max_users'],
                    'limits' => $limits,
                    'notes' => 'Assinatura ACTIVE criada no bootstrap inicial.',
                ]);
            });
        } catch (PlatformOwnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Escritório, conta dual (Proprietário + Office ADMIN) e assinatura ACTIVE criados.');

        return self::SUCCESS;
    }
}
