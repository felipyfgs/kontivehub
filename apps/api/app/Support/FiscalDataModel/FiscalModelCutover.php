<?php

namespace App\Support\FiscalDataModel;

use InvalidArgumentException;

/**
 * Adapters de corte por agregado: leitura/escrita canônica e shadow.
 * Rollback lógico = desligar read_canonical (kill_switch força legado).
 */
final class FiscalModelCutover
{
    public static function isKillSwitchActive(): bool
    {
        return (bool) config('fiscal_data_model.kill_switch', false);
    }

    public static function assertKnown(string $aggregate): void
    {
        if (! FiscalModelAggregates::isKnown($aggregate)) {
            throw new InvalidArgumentException("Agregado fiscal desconhecido: {$aggregate}");
        }
    }

    public static function writesCanonical(string $aggregate, ?int $officeId = null): bool
    {
        self::assertKnown($aggregate);

        // Kill switch reverte leitura; escritas canônicas aditivas continuam
        // para não perder evidência nova (rollback lógico).

        if (! (bool) config("fiscal_data_model.aggregates.{$aggregate}.write_canonical", true)) {
            return false;
        }

        // Escrita: allowlist vazia = todos os offices (default seguro para dual-write).
        return self::isOfficeAllowed($aggregate, $officeId, defaultWhenEmptyAllowlist: true);
    }

    public static function readsCanonical(string $aggregate, ?int $officeId = null): bool
    {
        self::assertKnown($aggregate);

        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config("fiscal_data_model.aggregates.{$aggregate}.read_canonical", false)) {
            return false;
        }

        // Corte de leitura: allowlist vazia exige allow_all_offices (coorte explícita).
        return self::isOfficeAllowed($aggregate, $officeId, defaultWhenEmptyAllowlist: false);
    }

    public static function shadowVerify(string $aggregate, ?int $officeId = null): bool
    {
        self::assertKnown($aggregate);

        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config("fiscal_data_model.aggregates.{$aggregate}.shadow_verify", false)) {
            return false;
        }

        return self::isOfficeAllowed($aggregate, $officeId, defaultWhenEmptyAllowlist: false);
    }

    public static function isOfficeAllowed(
        string $aggregate,
        ?int $officeId,
        bool $defaultWhenEmptyAllowlist = false,
    ): bool {
        self::assertKnown($aggregate);

        if ($officeId === null) {
            return true;
        }

        if ((bool) config("fiscal_data_model.aggregates.{$aggregate}.allow_all_offices", false)) {
            return true;
        }

        /** @var list<int> $allowlist */
        $allowlist = config("fiscal_data_model.aggregates.{$aggregate}.office_allowlist", []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return $defaultWhenEmptyAllowlist;
        }

        return in_array($officeId, array_map('intval', $allowlist), true);
    }

    /**
     * Leitura efetiva: 'canonical' | 'legacy'.
     */
    public static function readAuthority(string $aggregate, ?int $officeId = null): string
    {
        return self::readsCanonical($aggregate, $officeId) ? 'canonical' : 'legacy';
    }

    public static function jobPayloadVersion(): int
    {
        return max(1, (int) config('fiscal_data_model.job_payload_version', 1));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function versionJobPayload(array $payload): array
    {
        $payload['fiscal_model_payload_version'] = self::jobPayloadVersion();

        return $payload;
    }

    public static function jobPayloadIsCurrent(?int $version): bool
    {
        if ($version === null) {
            return false;
        }

        return $version === self::jobPayloadVersion();
    }
}
