<?php

namespace Database\Seeders;

use App\Enums\OfficeRole;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\OfficeInstitutionalProfile;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use App\Support\CurrentOffice;
use Illuminate\Database\Seeder;
use LogicException;
use RuntimeException;

/**
 * Massa de **piloto** a partir de `.local/dados/` (não versionado) — CNPJs/usuários reais de teste.
 *
 * Entrada pública: {@see PilotSeeder} (`php artisan db:seed --class=PilotSeeder`).
 * **Não** é chamado pelo {@see DatabaseSeeder} (seed de desenvolvimento só com exemplos).
 *
 * - Office contador: G A SILVA + usuário Gustavo
 * - Office plataforma: FELIPE MEI + usuário Felipe (PLATFORM_ADMIN)
 * - Carteira contador: só AUTO CENTER (`.local/dados/contador/cliene`)
 *
 * Logins (senha `password`):
 * - felipe@example.com → plataforma + aba Admin/SERPRO + membership piloto no contador
 * - gustavo@example.com → contador (tenant_admin)
 *
 * Idempotente. Só local/testing, salvo go-live piloto com `PILOT_SEED_ALLOW_PRODUCTION=true`.
 */
class LocalSerproSmokeSeeder extends Seeder
{
    public const MARKER = '[local-serpro-smoke]';

    public const PILOT_PASSWORD = 'password';

    /** Escritório contábil (autor do pedido). */
    public const CONTADOR_OFFICE_SLUG = 'contador';

    public const CONTADOR_OFFICE_NAME = 'G A SILVA ASSESSORIA CONTABIL';

    public const CONTADOR_CNPJ = '48123272000105';

    public const CONTADOR_EMAIL = 'contato@gasilva.local';

    public const CONTADOR_PHONE = '11999990000';

    /** Usuário piloto do contador (Gustavo). */
    public const GUSTAVO_NAME = 'Gustavo Silva';

    public const GUSTAVO_EMAIL = 'gustavo@example.com';

    /** Contribuinte MEI de canário (.local/dados/plataforma). */
    public const PLATAFORMA_CNPJ = '65396736000176';

    public const PLATAFORMA_LEGAL_NAME = 'FELIPE GALVAO DE SOUZA';

    public const PLATAFORMA_TRADE_NAME = 'FELIPE GALVAO DE SOUZA';

    public const PLATAFORMA_EMAIL = 'felipe.galvao@local.dev';

    public const PLATAFORMA_PHONE = '11988887777';

    /** Usuário piloto da plataforma (Felipe). */
    public const FELIPE_NAME = 'Felipe Galvao de Souza';

    public const FELIPE_EMAIL = 'felipe@example.com';

    /** Cliente LTDA na carteira do contador (.local/dados/contador/cliene). */
    public const AUTO_CENTER_CNPJ = '30288513000100';

    public const AUTO_CENTER_LEGAL_NAME = 'AUTO CENTER TECH AUTOMOTIVO LTDA';

    public const AUTO_CENTER_TRADE_NAME = 'AUTO CENTER TECH AUTOMOTIVO';

    public function run(): void
    {
        $productionAllowed = filter_var(env('PILOT_SEED_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOL);

        if (! app()->environment(['local', 'testing']) && ! $productionAllowed) {
            throw new LogicException('LocalSerproSmokeSeeder só pode rodar em local/testing.');
        }

        $contador = Office::query()->updateOrCreate(
            ['slug' => self::CONTADOR_OFFICE_SLUG],
            ['name' => self::CONTADOR_OFFICE_NAME, 'is_active' => true],
        );

        $plataforma = Office::query()->updateOrCreate(
            ['slug' => PlatformAdminDemoSeeder::OFFICE_SLUG],
            ['name' => self::PLATAFORMA_LEGAL_NAME, 'is_active' => true],
        );

        // Office slug=plataforma → nome e perfil = Felipe MEI (.local/dados/plataforma).
        $this->seedInstitutionalProfile($plataforma, [
            'cnpj' => self::PLATAFORMA_CNPJ,
            'legal_name' => self::PLATAFORMA_LEGAL_NAME,
            'institutional_email' => self::PLATAFORMA_EMAIL,
            'institutional_phone' => self::PLATAFORMA_PHONE,
        ]);

        // Office contador → G A SILVA (autor Integra).
        $this->seedInstitutionalProfile($contador, [
            'cnpj' => self::CONTADOR_CNPJ,
            'legal_name' => self::CONTADOR_OFFICE_NAME,
            'institutional_email' => self::CONTADOR_EMAIL,
            'institutional_phone' => self::CONTADOR_PHONE,
        ]);

        // Carteira do contador: só AUTO CENTER (Felipe = office plataforma, não cliente).
        $this->seedAutoCenterClient($contador);
        $this->retireMeiClientFromContador($contador);

        // Dois perfis reais de piloto (tenant_admin cada um no próprio office).
        $felipe = $this->seedPilotUser(
            office: $plataforma,
            email: self::FELIPE_EMAIL,
            name: self::FELIPE_NAME,
        );
        $this->seedPilotUser(
            office: $contador,
            email: self::GUSTAVO_EMAIL,
            name: self::GUSTAVO_NAME,
        );

        // Felipe = Proprietário (PLATFORM_ADMIN) para ver aba Admin / SERPRO.
        $this->ensureFelipeIsPlatformOwner($felipe, $plataforma);
        // Piloto sem privilégio global: Felipe também troca para o contador via membership real.
        $this->grantPilotContadorAccess($felipe, $contador);
    }

    /**
     * @param  array{
     *   cnpj: string,
     *   legal_name: string,
     *   institutional_email: string,
     *   institutional_phone: string
     * }  $data
     */
    private function seedInstitutionalProfile(Office $office, array $data): void
    {
        OfficeInstitutionalProfile::query()->updateOrCreate(
            ['office_id' => $office->id],
            [
                'cnpj' => $data['cnpj'],
                'legal_name' => $data['legal_name'],
                'institutional_email' => $data['institutional_email'],
                'institutional_phone' => $data['institutional_phone'],
            ],
        );

        // Nome do office = razão social (lista admin e seletor).
        if ($office->name !== $data['legal_name']) {
            $office->forceFill(['name' => $data['legal_name']])->save();
        }
    }

    /**
     * Remove Felipe da carteira do contador (soft-delete) se existir de seeds antigos.
     * O CNPJ MEI continua no office `plataforma` (perfil institucional).
     */
    private function retireMeiClientFromContador(Office $office): void
    {
        $root = substr(self::PLATAFORMA_CNPJ, 0, 8);

        $clients = Client::query()
            ->where('office_id', $office->id)
            ->where('root_cnpj', $root)
            ->get();

        foreach ($clients as $client) {
            Establishment::query()
                ->where('client_id', $client->id)
                ->each(static fn (Establishment $e) => $e->delete());
            $client->delete();
        }
    }

    /**
     * AUTO CENTER (.local/dados/contador/cliene) — campos estruturais de LTDA automotiva.
     * Endereço/QSA/capital completos via “Atualizar cadastro RFB” ou docs locais (não inventar).
     */
    private function seedAutoCenterClient(Office $office): void
    {
        $root = substr(self::AUTO_CENTER_CNPJ, 0, 8);

        $client = Client::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'root_cnpj' => $root,
            ],
            [
                'legal_name' => self::AUTO_CENTER_LEGAL_NAME,
                'display_name' => self::AUTO_CENTER_TRADE_NAME,
                'tax_regime' => TaxRegimeCode::SimplesNacional->value,
                'legal_nature_code' => '2062',
                'legal_nature_name' => 'Sociedade Empresária Limitada',
                'company_size_code' => '03',
                'company_size_name' => 'Empresa de Pequeno Porte',
                'responsible_qualification_code' => '49',
                'responsible_qualification_name' => 'Sócio-Administrador',
                'notes' => self::MARKER.' Cliente LTDA (.local/dados/contador/cliene). CNPJ '
                    .self::AUTO_CENTER_CNPJ.'.',
                'is_active' => true,
                'registration_source' => RegistrationSource::Manual,
            ],
        );

        Establishment::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'cnpj' => self::AUTO_CENTER_CNPJ,
            ],
            [
                'trade_name' => self::AUTO_CENTER_TRADE_NAME,
                'is_matrix' => true,
                'is_active' => true,
                'capture_enabled' => true,
                'registration_status' => RegistrationStatus::Active,
                'registration_source' => RegistrationSource::Manual,
                'main_cnae_code' => '4520001',
                'main_cnae_name' => 'Serviços de manutenção e reparação mecânica de veículos automotores',
                'secondary_cnaes' => [
                    [
                        'code' => '4530703',
                        'name' => 'Comércio a varejo de peças e acessórios novos para veículos automotores',
                    ],
                ],
                'simples_optant' => true,
                'mei_optant' => false,
                'address_country' => 'BR',
            ],
        );
    }

    /**
     * Usuário tenant_admin ativo, só no office informado (perfil real de piloto).
     * Felipe ainda recebe PLATFORM_ADMIN em {@see ensureFelipeIsPlatformOwner}.
     */
    private function seedPilotUser(Office $office, string $email, string $name): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PILOT_PASSWORD,
                'email_verified_at' => now(),
                'is_active' => true,
                'password_change_required' => false,
                'selected_office_id' => $office->id,
            ],
        );

        // Reativa se um seed anterior tiver desligado a conta.
        if (! $user->is_active || $user->selected_office_id !== $office->id || $user->name !== $name) {
            $user->forceFill([
                'name' => $name,
                'is_active' => true,
                'password_change_required' => false,
                'selected_office_id' => $office->id,
            ])->save();
        }

        OfficeMembership::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'user_id' => $user->id,
            ],
            [
                'role' => OfficeRole::Admin,
                'tenant_role' => TenantRole::TenantAdmin->value,
                'permission_profile_id' => null,
                'is_active' => true,
                'authorization_version' => 1,
            ],
        );

        // Remove vínculos em outros offices (piloto isolado: 1 user = 1 office).
        OfficeMembership::query()
            ->where('user_id', $user->id)
            ->where('office_id', '!=', $office->id)
            ->delete();

        return $user->fresh() ?? $user;
    }

    /**
     * Felipe é o Proprietário singleton: aba Admin + console SERPRO + office plataforma.
     * Transfere o vínculo de plataforma@example.com (fixture técnica) se ainda existir.
     */
    private function ensureFelipeIsPlatformOwner(User $felipe, Office $plataforma): void
    {
        /** @var PlatformOwnerService $owners */
        $owners = app(PlatformOwnerService::class);

        $current = $owners->findMembership();

        try {
            if ($current === null) {
                $owners->createOwner($felipe, isActive: true, defaultOfficeId: $plataforma->id);
            } elseif ((int) $current->user_id !== (int) $felipe->id) {
                $previousId = (int) $current->user_id;
                $owners->transferTo($felipe);
                User::query()->whereKey($previousId)->update(['is_active' => false]);
            }
        } catch (PlatformOwnerException $e) {
            throw new RuntimeException(
                'Falha ao promover Felipe a PLATFORM_ADMIN no seed piloto: '.$e->getMessage(),
                0,
                $e,
            );
        }

        $pm = $owners->findMembership();
        if ($pm === null || (int) $pm->user_id !== (int) $felipe->id) {
            throw new RuntimeException('Felipe não ficou como PLATFORM_ADMIN após o seed piloto.');
        }

        $pm->forceFill([
            'is_active' => true,
            'default_office_id' => $plataforma->id,
        ])->save();

        $felipe->forceFill([
            'is_active' => true,
            'selected_office_id' => $plataforma->id,
            'password_change_required' => false,
        ])->save();

        // Membership tenant no office plataforma (perfil /conta/escritorio = dados do MEI).
        OfficeMembership::query()->updateOrCreate(
            [
                'office_id' => $plataforma->id,
                'user_id' => $felipe->id,
            ],
            [
                'role' => OfficeRole::Admin,
                'tenant_role' => TenantRole::TenantAdmin->value,
                'permission_profile_id' => null,
                'is_active' => true,
                'authorization_version' => 1,
            ],
        );

        // Fixture antiga plataforma@example.com: desativa se não for o titular.
        User::query()
            ->where('email', PlatformAdminDemoSeeder::EMAIL)
            ->whereKeyNot($felipe->id)
            ->update(['is_active' => false]);

        $currentOffice = app(CurrentOffice::class);
        $currentOffice->forgetPlatformSelection($felipe);
        $currentOffice->rememberPlatformSelection($felipe, (int) $plataforma->id);
    }

    private function grantPilotContadorAccess(User $felipe, Office $contador): void
    {
        OfficeMembership::query()->updateOrCreate(
            [
                'office_id' => $contador->id,
                'user_id' => $felipe->id,
            ],
            [
                'role' => OfficeRole::Admin,
                'tenant_role' => TenantRole::TenantAdmin->value,
                'permission_profile_id' => null,
                'is_active' => true,
                'authorization_version' => 1,
            ],
        );
    }
}
