<?php

namespace App\Services\Vault;

use App\Contracts\SecureObjectStore;

/** Leitura de XML fiscal no cofre com AAD canônico (tenant_id + sha256). */
final class DocumentVaultReader
{
    /**
     * AAD canônico de documento DF-e no vault (export, download, reprocess).
     *
     * @return array{tenant_id: int, sha256: string}
     */
    public static function documentAad(int $tenantId, string $sha256): array
    {
        return [
            'tenant_id' => $tenantId,
            'sha256' => $sha256,
        ];
    }

    public static function get(
        SecureObjectStore $store,
        string $objectId,
        int $tenantId,
        string $sha256,
    ): string {
        return $store->get($objectId, self::documentAad($tenantId, $sha256));
    }
}
