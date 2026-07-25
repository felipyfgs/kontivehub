<?php

namespace App\Jobs\Serpro;

use App\Contracts\SecureObjectStore;
use App\Enums\AuthorCertificateMode;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\OfficeCredentialResolver;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\TermoXmlSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Assina Termo com A1 gerenciado (consentimento versionado + ADMIN + 2FA na API).
 * Assinatura integralmente em memória; não grava PFX/PEM em disco.
 * Preferência: author_pfx legado; fallback: A1 canônico / SERPRO_TERM_SIGNING.
 */
final class SignTermoWithManagedA1Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $officeId,
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
        OfficeSerproAuthorizationService $authorizations,
        OfficeCredentialResolver $credentials,
        AuditLogger $audit,
    ): void {
        $office = Office::query()->findOrFail($this->officeId);
        $env = SerproEnvironment::from(strtoupper($this->environment));
        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $this->officeId)
            ->whereKey($this->authorizationId)
            ->firstOrFail();

        if ($auth->certificate_mode !== AuthorCertificateMode::ManagedA1) {
            throw new RuntimeException('Modo de certificado não é A1 gerenciado.');
        }
        if (! $auth->managed_a1_consent) {
            throw new RuntimeException('Consentimento A1 gerenciado ausente.');
        }

        $meta = is_array($auth->metadata) ? $auth->metadata : [];
        $draftId = $meta['termo_draft_vault_object_id'] ?? null;
        $draftSha = $meta['termo_draft_sha256'] ?? null;
        if (! is_string($draftId) || $draftId === '' || ! is_string($draftSha) || $draftSha === '') {
            throw new RuntimeException('Draft do Termo ausente; gere o draft antes de assinar.');
        }

        $draftAad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'office_id' => $office->id,
            'environment' => $env->value,
            'kind' => 'draft',
            'sha256' => $draftSha,
            'author_identity' => $auth->author_identity,
        ]);
        $unsignedXml = $store->get($draftId, $draftAad);

        [$pfxBinary, $password] = $this->materializePfx($store, $credentials, $office, $env, $auth);

        try {
            $signedXml = $signer->signWithPfx($unsignedXml, $pfxBinary, $password);
            unset($pfxBinary, $password, $unsignedXml);

            $authorizations->uploadTermo($office, $env, $signedXml, $this->actorUserId);
            unset($signedXml);

            $audit->record('serpro.authorization.termo_managed_a1_sign', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'authorization_id' => $auth->id,
            ], $this->actorUserId, $office->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.termo_managed_a1_sign', 'FAILED', $auth, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $office->id);
            throw $e;
        } finally {
            unset($pfxBinary, $password, $unsignedXml);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function materializePfx(
        SecureObjectStore $store,
        OfficeCredentialResolver $credentials,
        Office $office,
        SerproEnvironment $env,
        OfficeSerproAuthorization $auth,
    ): array {
        if ($auth->author_pfx_vault_object_id !== null) {
            $pfxAad = SecureObjectPurpose::SerproAuthorPfx->aadBase([
                'office_id' => $office->id,
                'environment' => $env->value,
                'fingerprint' => $auth->author_fingerprint_sha256,
                'author_identity' => $auth->author_identity,
            ]);
            $pfxPayload = $store->get($auth->author_pfx_vault_object_id, $pfxAad);
            /** @var array{pfx?: string, password?: string} $decoded */
            $decoded = json_decode($pfxPayload, true, 512, JSON_THROW_ON_ERROR);
            unset($pfxPayload);
            $pfxB64 = $decoded['pfx'] ?? null;
            $password = $decoded['password'] ?? null;
            unset($decoded);

            if (! is_string($pfxB64) || $pfxB64 === '' || ! is_string($password)) {
                throw new RuntimeException('Material A1 incompleto no vault.');
            }

            $pfxBinary = base64_decode($pfxB64, true);
            unset($pfxB64);
            if ($pfxBinary === false || $pfxBinary === '') {
                throw new RuntimeException('PFX A1 inválido no vault.');
            }

            return [$pfxBinary, $password];
        }

        $resolved = $credentials->resolveForSerproTermSigning((int) $office->id);
        $material = $resolved['material'];
        $pfxBinary = $material['pfx'] ?? null;
        $password = $material['password'] ?? null;
        if (! is_string($pfxBinary) || $pfxBinary === '' || ! is_string($password)) {
            throw new RuntimeException('Material A1 canônico incompleto para assinatura do Termo.');
        }

        return [$pfxBinary, $password];
    }
}
