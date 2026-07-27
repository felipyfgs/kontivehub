<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use RuntimeException;

/**
 * Cofre de corpo/anexo de Caixa Postal — AAD com purpose + tenant_id + sha256.
 */
final class MailboxVaultStore
{
    public function __construct(
        private readonly SecureObjectStore $vault,
    ) {}

    /**
     * @return array{tenant_id:int,sha256:string,purpose:string}
     */
    public static function bodyAad(int $tenantId, string $sha256): array
    {
        return SecureObjectPurpose::MailboxMessageBody->aadBase([
            'tenant_id' => $tenantId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{tenant_id:int,sha256:string,purpose:string}
     */
    public static function attachmentAad(int $tenantId, string $sha256): array
    {
        return SecureObjectPurpose::MailboxAttachment->aadBase([
            'tenant_id' => $tenantId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{vault_object_id:string,sha256:string,byte_size:int}
     */
    public function putBody(int $tenantId, string $bytes): array
    {
        $max = (int) config('fiscal_monitoring.mailbox.max_body_bytes', 2_097_152);
        $size = strlen($bytes);
        if ($size === 0) {
            throw new RuntimeException('Corpo de mensagem vazio não é armazenado.');
        }
        if ($size > $max) {
            throw new RuntimeException("Corpo excede limite de {$max} bytes.");
        }

        $sha256 = hash('sha256', $bytes);
        $objectId = $this->vault->put($bytes, self::bodyAad($tenantId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'sha256' => $sha256,
            'byte_size' => $size,
        ];
    }

    /**
     * @return array{vault_object_id:string,sha256:string,byte_size:int}
     */
    public function putAttachment(int $tenantId, string $bytes): array
    {
        $max = (int) config('fiscal_monitoring.mailbox.max_attachment_bytes', 10_485_760);
        $size = strlen($bytes);
        if ($size === 0) {
            throw new RuntimeException('Anexo vazio não é armazenado.');
        }
        if ($size > $max) {
            throw new RuntimeException("Anexo excede limite de {$max} bytes.");
        }

        $sha256 = hash('sha256', $bytes);
        $objectId = $this->vault->put($bytes, self::attachmentAad($tenantId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'sha256' => $sha256,
            'byte_size' => $size,
        ];
    }

    public function getBody(int $tenantId, string $objectId, string $sha256): string
    {
        return $this->vault->get($objectId, self::bodyAad($tenantId, $sha256));
    }

    public function getAttachment(int $tenantId, string $objectId, string $sha256): string
    {
        return $this->vault->get($objectId, self::attachmentAad($tenantId, $sha256));
    }
}
