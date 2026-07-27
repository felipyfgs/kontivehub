<?php

namespace App\Jobs\Serpro;

use App\Contracts\SecureObjectStore;
use App\Enums\AuthorCertificateMode;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproEnvironment;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialResolver;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Integra\TermoXmlSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Assina Termo com o certificado vinculado à finalidade SERPRO_TERM_SIGNING.
 * Assinatura integralmente em memória; não grava PFX/PEM em disco.
 */
final class SignTermoWithManagedCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $environment,
        public readonly int $authorizationId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        // Fila fiscal com supervisor Horizon (config/horizon.php + serpro.queues.fiscal).
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function handle(
        SecureObjectStore $store,
        TermoXmlSigner $signer,
        TenantSerproAuthorizationService $authorizations,
        TenantCredentialResolver $credentials,
        AuditLogger $audit,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $env = SerproEnvironment::from(strtoupper($this->environment));
        $auth = TenantSerproAuthorization::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->authorizationId)
            ->firstOrFail();

        if ($auth->certificate_mode !== AuthorCertificateMode::ManagedCertificate) {
            throw new RuntimeException('O certificado gerenciado não está configurado.');
        }
        $meta = is_array($auth->metadata) ? $auth->metadata : [];
        $draftId = $meta['termo_draft_vault_object_id'] ?? null;
        $draftSha = $meta['termo_draft_sha256'] ?? null;
        if (! is_string($draftId) || $draftId === '' || ! is_string($draftSha) || $draftSha === '') {
            throw new RuntimeException('Draft do Termo ausente; gere o draft antes de assinar.');
        }

        $draftAad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'tenant_id' => $tenant->id,
            'environment' => $env->value,
            'kind' => 'draft',
            'sha256' => $draftSha,
            'author_identity' => $auth->author_identity,
        ]);
        $unsignedXml = $store->get($draftId, $draftAad);

        [$pfxBinary, $password] = $this->materializePfx($credentials, $tenant);

        try {
            $signedXml = $signer->signWithPfx($unsignedXml, $pfxBinary, $password);
            unset($pfxBinary, $password, $unsignedXml);

            $authorizations->uploadTermo($tenant, $env, $signedXml, $this->actorUserId);
            unset($signedXml);

            $audit->record('serpro.authorization.termo_managed_certificate_sign', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'authorization_id' => $auth->id,
            ], $this->actorUserId, $tenant->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.termo_managed_certificate_sign', 'FAILED', $auth, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $tenant->id);
            throw $e;
        } finally {
            unset($pfxBinary, $password, $unsignedXml);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function materializePfx(
        TenantCredentialResolver $credentials,
        Tenant $tenant,
    ): array {
        $resolved = $credentials->resolveForSerproTermSigning((int) $tenant->id);
        $material = $resolved['material'];
        $pfxBinary = $material['pfx'] ?? null;
        $password = $material['password'] ?? null;
        if (! is_string($pfxBinary) || $pfxBinary === '' || ! is_string($password)) {
            throw new RuntimeException('Material certificado incompleto para assinatura do Termo.');
        }

        return [$pfxBinary, $password];
    }
}
