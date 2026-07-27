<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Models\PlatformMembership;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapTenantCommand extends Command
{
    protected $signature = 'app:bootstrap-tenant
        {--name= : Nome do escritório}
        {--slug= : Slug do escritório}
        {--admin-name= : Nome do administrador}
        {--admin-email= : E-mail do administrador}';

    protected $description = 'Cria o primeiro escritório e conta dual (Proprietário PLATFORM_ADMIN + Tenant ADMIN)';

    public function handle(PlatformOwnerService $owners): int
    {
        if (Tenant::query()->exists()
            || PlatformMembership::query()->exists()
            || User::query()->exists()
            || PlatformSetting::query()->exists()) {
            $this->error('Instalação já possui Tenant, usuário, proprietário ou onboarding. Bootstrap recusado.');

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
                if (Tenant::query()->exists()
                    || PlatformMembership::query()->exists()
                    || User::query()->exists()
                    || PlatformSetting::query()->exists()) {
                    throw PlatformOwnerException::alreadyExists(
                        'Instalação já possui Tenant, usuário, proprietário ou onboarding.',
                    );
                }

                $tenant = Tenant::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'is_active' => true,
                ]);

                $user = User::query()->create([
                    'name' => $adminName,
                    'email' => Str::lower($adminEmail),
                    'password' => Hash::make($adminPassword),
                    'is_active' => true,
                    'selected_tenant_id' => $tenant->id,
                ]);

                // Membership real de Tenant ADMIN
                $tenant->users()->attach($user->id, [
                    'role' => TenantRole::TenantAdmin->value,
                    'is_active' => true,
                ]);

                // Proprietário singleton com Tenant padrão = primeiro Tenant
                $owners->createOwner($user, isActive: true, defaultTenantId: $tenant->id);

                // Assinatura ACTIVE para o tenant (sem isso, mutações HTTP retornam 403 MISSING).
                $plan = SubscriptionPlan::Professional;
                $limits = $plan->defaultLimits();
                $now = now();
                TenantSubscription::query()->create([
                    'tenant_id' => $tenant->id,
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

        $this->info('Escritório, conta dual (Proprietário + Tenant ADMIN) e assinatura ACTIVE criados.');

        return self::SUCCESS;
    }
}
