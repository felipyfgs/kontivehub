<?php

namespace Tests\Unit\Jobs\Serpro;

use App\Contracts\SecureObjectStore;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Serpro\SignTermoWithManagedA1Job;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\OfficeCredentialResolver;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\TermoXmlSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SignTermoWithCanonicalA1Test extends TestCase
{
    use RefreshDatabase;

    public function test_sign_job_falls_back_to_canonical_resolver_when_author_pfx_missing(): void
    {
        $office = Office::factory()->create();
        $draftSha = hash('sha256', '<termo/>');
        $authorIdentity = '11222333000181';

        $store = app(SecureObjectStore::class);
        $draftAad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial->value,
            'kind' => 'draft',
            'sha256' => $draftSha,
            'author_identity' => $authorIdentity,
        ]);
        $draftId = $store->put('<termo/>', $draftAad);

        $auth = OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::PendingTerm,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => $authorIdentity,
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'author_pfx_vault_object_id' => null,
            'metadata' => [
                'termo_draft_vault_object_id' => $draftId,
                'termo_draft_sha256' => $draftSha,
            ],
        ]);

        $job = new SignTermoWithManagedA1Job(
            (int) $office->id,
            SerproEnvironment::Trial->value,
            (int) $auth->id,
        );

        try {
            $job->handle(
                $store,
                app(TermoXmlSigner::class),
                app(OfficeSerproAuthorizationService::class),
                app(OfficeCredentialResolver::class),
                app(AuditLogger::class),
            );
            $this->fail('Esperava RuntimeException por A1 canônico ausente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'Credencial A1 do escritório ausente',
                $e->getMessage(),
            );
        }
    }
}
