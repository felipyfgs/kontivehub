<?php

namespace App\Services\Fiscal\Guides;

use App\Models\TaxGuideDownloadToken;
use App\Models\TaxGuideVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens de download temporários tenant-scoped — sem path de storage nem URL permanente.
 */
final class GuideDownloadService
{
    public function __construct(
        private readonly GuideStorageService $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{token:string,expires_at:string,version_id:int}
     */
    public function issueToken(TaxGuideVersion $version, User $user, int $tenantId): array
    {
        if ((int) $version->tenant_id !== $tenantId) {
            throw GuideException::notFound();
        }

        if (! $version->hasStoredDocument() || ! $version->emission_status->isUsableDocument()) {
            throw new GuideException(
                'Documento indisponível para download.',
                'document_unavailable',
                422,
            );
        }

        $ttl = (int) config('tax_guides.download.token_ttl_seconds', 120);
        $plain = Str::random(48);
        $hash = hash('sha256', $plain);

        TaxGuideDownloadToken::query()->create([
            'tenant_id' => $tenantId,
            'tax_guide_version_id' => $version->id,
            'user_id' => $user->id,
            'token_hash' => $hash,
            'expires_at' => CarbonImmutable::now()->addSeconds($ttl),
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record(
            action: 'tax_guide.download_token.issue',
            result: 'SUCCESS',
            subject: $version,
            context: [
                'tax_guide_id' => $version->tax_guide_id,
                'version_id' => $version->id,
                'ttl_seconds' => $ttl,
                // sem path, vault_object_id ou token em claro
            ],
            userId: $user->id,
            tenantId: $tenantId,
        );

        return [
            'token' => $plain,
            'expires_at' => CarbonImmutable::now()->addSeconds($ttl)->toIso8601String(),
            'version_id' => $version->id,
        ];
    }

    /**
     * Consome token e devolve bytes. Auditoria de entrega interna — NÃO altera pagamento.
     *
     * @return array{bytes:string,content_type:string,filename:string,sha256:string,version:TaxGuideVersion}
     */
    public function consumeToken(string $plainToken, int $tenantId, ?User $user = null): array
    {
        $hash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($hash, $tenantId, $user): array {
            $claimedAt = CarbonImmutable::now();
            $claimed = TaxGuideDownloadToken::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('token_hash', $hash)
                ->whereNull('used_at')
                ->where('expires_at', '>', $claimedAt)
                ->update(['used_at' => $claimedAt]);

            if ($claimed !== 1) {
                throw GuideException::notFound('Token de download inválido ou expirado.');
            }

            $token = TaxGuideDownloadToken::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('token_hash', $hash)
                ->first();

            if ($token === null) {
                throw GuideException::notFound();
            }

            $version = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($token->tax_guide_version_id)
                ->first();

            if ($version === null) {
                throw GuideException::notFound();
            }

            $bytes = $this->storage->readDocumentAuthorized($version, $tenantId);

            DB::afterCommit(fn () => $this->audit->record(
                action: 'tax_guide.download.deliver',
                result: 'SUCCESS',
                subject: $version,
                context: [
                    'tax_guide_id' => $version->tax_guide_id,
                    'version_id' => $version->id,
                    'byte_size' => $version->byte_size,
                    // pagamento NÃO alterado
                    'payment_unchanged' => true,
                ],
                userId: $user?->id ?? $token->user_id,
                tenantId: $tenantId,
            ));

            $filename = 'guia-'.$version->tax_guide_id.'-v'.$version->version_number.'.pdf';

            return [
                'bytes' => $bytes,
                'content_type' => $version->content_type ?? 'application/pdf',
                'filename' => $filename,
                'sha256' => $version->content_sha256 ?? hash('sha256', $bytes),
                'version' => $version,
            ];
        }, 3);
    }
}
