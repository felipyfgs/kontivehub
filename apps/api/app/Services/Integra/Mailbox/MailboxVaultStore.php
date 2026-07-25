<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use RuntimeException;

/**
 * Cofre de corpo/anexo de Caixa Postal — AAD com purpose + office_id + sha256.
 */
final class MailboxVaultStore
{
    public function __construct(
        private readonly SecureObjectStore $vault,
    ) {}

    /**
     * @return array{office_id:int,sha256:string,purpose:string}
     */
    public static function bodyAad(int $officeId, string $sha256): array
    {
        return SecureObjectPurpose::MailboxMessageBody->aadBase([
            'office_id' => $officeId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{office_id:int,sha256:string,purpose:string}
     */
    public static function attachmentAad(int $officeId, string $sha256): array
    {
        return SecureObjectPurpose::MailboxAttachment->aadBase([
            'office_id' => $officeId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * @return array{vault_object_id:string,sha256:string,byte_size:int}
     */
    public function putBody(int $officeId, string $bytes): array
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
        $objectId = $this->vault->put($bytes, self::bodyAad($officeId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'sha256' => $sha256,
            'byte_size' => $size,
        ];
    }

    /**
     * @return array{vault_object_id:string,sha256:string,byte_size:int}
     */
    public function putAttachment(int $officeId, string $bytes): array
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
        $objectId = $this->vault->put($bytes, self::attachmentAad($officeId, $sha256));

        return [
            'vault_object_id' => $objectId,
            'sha256' => $sha256,
            'byte_size' => $size,
        ];
    }

    public function getBody(int $officeId, string $objectId, string $sha256): string
    {
        return $this->vault->get($objectId, self::bodyAad($officeId, $sha256));
    }

    public function getAttachment(int $officeId, string $objectId, string $sha256): string
    {
        return $this->vault->get($objectId, self::attachmentAad($officeId, $sha256));
    }
}
