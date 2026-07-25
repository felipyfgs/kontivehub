<?php

namespace App\Services\Fiscal\Guides;

use App\Models\TaxGuideDownloadToken;
use App\Models\TaxGuideVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use Carbon\CarbonImmutable;
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
    public function issueToken(TaxGuideVersion $version, User $user, int $officeId): array
    {
        if ((int) $version->office_id !== $officeId) {
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
            'office_id' => $officeId,
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
            officeId: $officeId,
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
    public function consumeToken(string $plainToken, int $officeId, ?User $user = null): array
    {
        $hash = hash('sha256', $plainToken);

        $token = TaxGuideDownloadToken::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('token_hash', $hash)
            ->first();

        if ($token === null || ! $token->isUsable()) {
            throw GuideException::notFound('Token de download inválido ou expirado.');
        }

        $version = TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($token->tax_guide_version_id)
            ->first();

        if ($version === null) {
            throw GuideException::notFound();
        }

        $bytes = $this->storage->readDocumentAuthorized($version, $officeId);

        $token->used_at = CarbonImmutable::now();
        $token->save();

        $this->audit->record(
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
            officeId: $officeId,
        );

        $filename = 'guia-'.$version->tax_guide_id.'-v'.$version->version_number.'.pdf';

        return [
            'bytes' => $bytes,
            'content_type' => $version->content_type ?? 'application/pdf',
            'filename' => $filename,
            'sha256' => $version->content_sha256 ?? hash('sha256', $bytes),
            'version' => $version,
        ];
    }
}
