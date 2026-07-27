<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Models\TaxGuideVersion;
use RuntimeException;

/**
 * Storage seguro de documentos de guia no cofre (AAD purpose + tenant_id + sha256).
 */
final class GuideStorageService
{
    public function __construct(
        private readonly SecureObjectStore $vault,
    ) {}

    /**
     * @return array{tenant_id:int,sha256:string,purpose:string}
     */
    public static function aad(int $tenantId, string $sha256): array
    {
        return SecureObjectPurpose::TaxGuideDocument->aadBase([
            'tenant_id' => $tenantId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{tenant_id:int,sha256:string,purpose:string}
     */
    public static function paymentAad(int $tenantId, string $sha256): array
    {
        return SecureObjectPurpose::TaxGuidePaymentEvidence->aadBase([
            'tenant_id' => $tenantId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{vault_object_id:string,content_sha256:string,byte_size:int,content_type:string}
     */
    public function storeDocument(int $tenantId, string $bytes, string $contentType): array
    {
        $max = (int) config('tax_guides.download.max_bytes', 5_242_880);
        $size = strlen($bytes);
        if ($size === 0) {
            throw new RuntimeException('Documento de guia vazio não é armazenado.');
        }
        if ($size > $max) {
            throw new RuntimeException("Documento de guia excede limite de {$max} bytes.");
        }

        $sha256 = hash('sha256', $bytes);
        $objectId = $this->vault->put($bytes, self::aad($tenantId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'content_sha256' => $sha256,
            'byte_size' => $size,
            'content_type' => $contentType,
        ];
    }

    /**
     * @return array{vault_object_id:string,content_sha256:string,byte_size:int,content_type:string}
     */
    public function storePaymentEvidence(int $tenantId, string $bytes, string $contentType): array
    {
        $max = (int) config('tax_guides.download.max_bytes', 5_242_880);
        $size = strlen($bytes);
        if ($size === 0) {
            throw new RuntimeException('Evidência de pagamento vazia.');
        }
        if ($size > $max) {
            throw new RuntimeException("Evidência de pagamento excede limite de {$max} bytes.");
        }

        $sha256 = hash('sha256', $bytes);
        $objectId = $this->vault->put($bytes, self::paymentAad($tenantId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'content_sha256' => $sha256,
            'byte_size' => $size,
            'content_type' => $contentType,
        ];
    }

    public function readDocumentAuthorized(TaxGuideVersion $version, int $tenantId): string
    {
        if ((int) $version->tenant_id !== $tenantId) {
            throw new RuntimeException('Guia não pertence ao escritório ativo.');
        }
        if ($version->vault_object_id === null || $version->content_sha256 === null) {
            throw new RuntimeException('Documento de guia indisponível.');
        }

        return $this->vault->get(
            $version->vault_object_id,
            self::aad($tenantId, $version->content_sha256),
        );
    }
}
