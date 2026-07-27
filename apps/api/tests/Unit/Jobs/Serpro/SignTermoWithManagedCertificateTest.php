<?php

namespace Tests\Unit\Jobs\Serpro;

use App\Contracts\SecureObjectStore;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Serpro\SignTermoWithManagedCertificateJob;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialResolver;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Integra\TermoXmlSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SignTermoWithManagedCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_job_requires_the_tenant_certificate_link(): void
    {
        $tenant = Tenant::factory()->create();
        $draftSha = hash('sha256', '<termo/>');
        $authorIdentity = '11222333000181';

        $store = app(SecureObjectStore::class);
        $draftAad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial->value,
            'kind' => 'draft',
            'sha256' => $draftSha,
            'author_identity' => $authorIdentity,
        ]);
        $draftId = $store->put('<termo/>', $draftAad);

        $auth = TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::PendingTerm,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => $authorIdentity,
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'metadata' => [
                'termo_draft_vault_object_id' => $draftId,
                'termo_draft_sha256' => $draftSha,
            ],
        ]);

        $job = new SignTermoWithManagedCertificateJob(
            (int) $tenant->id,
            SerproEnvironment::Trial->value,
            (int) $auth->id,
        );

        try {
            $job->handle(
                $store,
                app(TermoXmlSigner::class),
                app(TenantSerproAuthorizationService::class),
                app(TenantCredentialResolver::class),
                app(AuditLogger::class),
            );
            $this->fail('Esperava RuntimeException por certificado canônico ausente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'certificado do escritório ausente',
                $e->getMessage(),
            );
        }
    }
}
