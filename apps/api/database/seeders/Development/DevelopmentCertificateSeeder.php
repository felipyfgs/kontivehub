<?php

namespace Database\Seeders\Development;

use App\Contracts\PfxReaderInterface;
use App\Enums\CredentialStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Certificates\TenantCredentialService;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use LogicException;
use RuntimeException;
use Throwable;

final class DevelopmentCertificateSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('DevelopmentCertificateSeeder permitido somente em local.');
        }

        $certificates = $this->loadCertificates();
        $activated = 0;
        $unchanged = 0;

        foreach ([
            DevelopmentSeeder::PLATFORM_SLUG => DevelopmentSeeder::PLATFORM_CNPJ,
            DevelopmentSeeder::TENANT_SLUG => DevelopmentSeeder::TENANT_CNPJ,
        ] as $slug => $cnpj) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
            [$didActivate, $didRemain] = $this->activateTenantCertificate(
                $tenant,
                $certificates[$cnpj],
            );
            $activated += $didActivate;
            $unchanged += $didRemain;
        }

        $tenant = Tenant::query()->where('slug', DevelopmentSeeder::TENANT_SLUG)->firstOrFail();
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('root_cnpj', '30288513')
            ->firstOrFail();
        [$didActivate, $didRemain] = $this->activateClientCertificate(
            $tenant,
            $client,
            $certificates[DevelopmentSeeder::CLIENT_CNPJ],
        );
        $activated += $didActivate;
        $unchanged += $didRemain;

        app(CurrentTenant::class)->clear();
        $this->command?->info(
            "DevelopmentCertificateSeeder concluído: activated={$activated} unchanged={$unchanged}.",
        );
    }

    /**
     * @return array<string, array{binary: string, password: string, fingerprint: string}>
     */
    private function loadCertificates(): array
    {
        $root = realpath((string) config('development_data.path'));
        if ($root === false || ! is_dir($root) || is_link((string) config('development_data.path'))) {
            throw new RuntimeException('DEVELOPMENT_CERTIFICATE_DATASET_INVALID');
        }

        $paths = [];
        $secretFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('DEVELOPMENT_CERTIFICATE_LINK_FORBIDDEN');
            }
            if ($file->isFile() && mb_strtolower($file->getExtension()) === 'pfx') {
                $paths[] = $file->getRealPath();
            } elseif ($file->isFile() && mb_strtolower($file->getExtension()) === 'txt') {
                $secretFiles[] = $file->getRealPath();
            }
        }
        if (count($secretFiles) !== 1 || $secretFiles[0] === false) {
            throw new RuntimeException('DEVELOPMENT_CERTIFICATE_SECRET_INVALID');
        }
        $password = trim((string) file_get_contents($secretFiles[0]));
        if ($password === '' || str_contains($password, "\n") || strlen($password) > 4096) {
            throw new RuntimeException('DEVELOPMENT_CERTIFICATE_SECRET_INVALID');
        }

        sort($paths, SORT_STRING);
        if (count($paths) !== 3 || in_array(false, $paths, true)) {
            throw new RuntimeException('DEVELOPMENT_CERTIFICATE_SET_INVALID');
        }

        $expected = [
            DevelopmentSeeder::PLATFORM_CNPJ,
            DevelopmentSeeder::TENANT_CNPJ,
            DevelopmentSeeder::CLIENT_CNPJ,
        ];
        $result = [];
        foreach ($paths as $path) {
            $binary = file_get_contents($path);
            if ($binary === false || $binary === '' || strlen($binary) > 10 * 1024 * 1024) {
                throw new RuntimeException('DEVELOPMENT_CERTIFICATE_INVALID');
            }

            try {
                $metadata = app(PfxReaderInterface::class)->read($binary, $password);
            } catch (Throwable) {
                throw new RuntimeException('DEVELOPMENT_CERTIFICATE_INVALID');
            }

            $cnpj = $metadata['cnpj'];
            if (! in_array($cnpj, $expected, true) || isset($result[$cnpj])) {
                throw new RuntimeException('DEVELOPMENT_CERTIFICATE_IDENTITY_INVALID');
            }
            $result[$cnpj] = [
                'binary' => $binary,
                'password' => $password,
                'fingerprint' => $metadata['fingerprint_sha256'],
            ];
        }

        if (count($result) !== count($expected)) {
            throw new RuntimeException('DEVELOPMENT_CERTIFICATE_SET_INVALID');
        }

        return $result;
    }

    /**
     * @param  array{binary: string, password: string, fingerprint: string}  $certificate
     * @return array{0: int, 1: int}
     */
    private function activateTenantCertificate(Tenant $tenant, array $certificate): array
    {
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->bindSystem($tenant);

        $exists = TenantCredential::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', CredentialStatus::Active)
            ->where('fingerprint_sha256', $certificate['fingerprint'])
            ->exists();
        if ($exists) {
            $currentTenant->clear();

            return [0, 1];
        }

        app(TenantCredentialService::class)->activate(
            $certificate['binary'],
            $certificate['password'],
        );
        $currentTenant->clear();

        return [1, 0];
    }

    /**
     * @param  array{binary: string, password: string, fingerprint: string}  $certificate
     * @return array{0: int, 1: int}
     */
    private function activateClientCertificate(
        Tenant $tenant,
        Client $client,
        array $certificate,
    ): array {
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->bindSystem($tenant);

        $exists = ClientCredential::query()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('status', CredentialStatus::Active)
            ->where('fingerprint_sha256', $certificate['fingerprint'])
            ->exists();
        if ($exists) {
            $currentTenant->clear();

            return [0, 1];
        }

        app(CredentialService::class)->activate(
            $client,
            $certificate['binary'],
            $certificate['password'],
        );
        $currentTenant->clear();

        return [1, 0];
    }
}
