<?php

namespace App\Services\Fiscal\Demo;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use RuntimeException;

/**
 * Persistência de corpos/anexos/guias demo exclusivamente via SecureObjectStore.
 * Nunca expõe vault_object_id em APIs públicas (fica só na coluna interna).
 */
final class DemoVaultWriter
{
    public function __construct(
        private readonly SecureObjectStore $vault,
        private readonly DemoContentFactory $content,
    ) {}

    /**
     * @return array{vault_object_id: string, content_sha256: string, byte_size: int, content_type: string}
     */
    public function put(
        int $officeId,
        string $bytes,
        SecureObjectPurpose $purpose,
        string $contentType = 'application/json',
        array $extraAad = [],
    ): array {
        if ($bytes === '') {
            throw new RuntimeException('Conteúdo demo vazio não é armazenado no cofre.');
        }

        // Garante marca d'água presente (defesa em profundidade).
        $watermark = $this->content->watermark();
        if (! str_contains($bytes, $watermark) && ! str_contains($bytes, 'DEMONSTRA')) {
            $bytes = $watermark."\n".$bytes;
        }

        $sha256 = hash('sha256', $bytes);
        $aad = $purpose->aadBase(array_merge([
            'office_id' => $officeId,
            'sha256' => $sha256,
            'demo' => true,
        ], $extraAad));

        $objectId = $this->vault->put($bytes, $aad);

        return [
            'vault_object_id' => $objectId,
            'content_sha256' => $sha256,
            'byte_size' => strlen($bytes),
            'content_type' => $contentType,
        ];
    }

    public function putEvidenceJson(int $officeId, string $logicalKey, array $payload = []): array
    {
        return $this->put(
            $officeId,
            $this->content->evidenceJson($logicalKey, $payload),
            SecureObjectPurpose::FiscalEvidence,
            'application/json',
        );
    }

    public function putMailboxBody(int $officeId, string $subject, string $logicalKey): array
    {
        return $this->put(
            $officeId,
            $this->content->mailboxBody($subject, $logicalKey),
            SecureObjectPurpose::MailboxMessageBody,
            'text/plain; charset=UTF-8',
        );
    }

    public function putMailboxAttachment(int $officeId, string $filename, string $logicalKey): array
    {
        return $this->put(
            $officeId,
            $this->content->attachmentBytes($filename, $logicalKey),
            SecureObjectPurpose::MailboxAttachment,
            'text/plain; charset=UTF-8',
        );
    }

    public function putGuideDocument(
        int $officeId,
        string $documentNumber,
        string $logicalKey,
        int $amountCents,
    ): array {
        return $this->put(
            $officeId,
            $this->content->guideDocumentBytes($documentNumber, $logicalKey, $amountCents),
            SecureObjectPurpose::TaxGuideDocument,
            'application/pdf',
        );
    }
}
