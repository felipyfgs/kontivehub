<?php

namespace App\Services\Vault;

use App\Contracts\SecureObjectStore;
use RuntimeException;
use Throwable;

/**
 * Leitura de XML fiscal no cofre com AAD canônico (office_id + sha256)
 * e fallbacks para objetos gravados com metadados extras (kind/purpose).
 */
final class DocumentVaultReader
{
    /**
     * AAD canônico de documento DF-e no vault (export, download, reprocess).
     *
     * @return array{office_id: int, sha256: string}
     */
    public static function documentAad(int $officeId, string $sha256): array
    {
        return [
            'office_id' => $officeId,
            'sha256' => $sha256,
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public static function aadCandidates(int $officeId, string $sha256): array
    {
        $base = self::documentAad($officeId, $sha256);

        return [
            $base,
            $base + ['kind' => 'ma_package'],
            $base + ['kind' => 'svrs_nfce_xml'],
            $base + ['kind' => 'svrs_nfe55_xml'],
            $base + ['kind' => 'outbound_seed'],
            $base + ['kind' => 'import-event'],
            $base + ['kind' => 'import-quarantine'],
            $base + ['purpose' => 'quarantine'],
        ];
    }

    /**
     * @throws RuntimeException se nenhum AAD abrir o envelope
     */
    public static function get(
        SecureObjectStore $store,
        string $objectId,
        int $officeId,
        string $sha256,
    ): string {
        $last = null;
        foreach (self::aadCandidates($officeId, $sha256) as $aad) {
            try {
                return $store->get($objectId, $aad);
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw new RuntimeException(
            'Falha ao descriptografar objeto do cofre (AAD/metadados ou adulteração).',
            0,
            $last
        );
    }
}
