<?php

namespace App\Casts;

use App\Enums\FiscalSourceProvenance;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Compatibilidade de leitura para evidência histórica inválida.
 *
 * `SIMULATED` nunca volta a ser uma proveniência operacional: registros antigos
 * são expostos como UNVERIFIED e permanecem no banco somente para auditoria.
 *
 * @implements CastsAttributes<FiscalSourceProvenance|null, FiscalSourceProvenance|string|null>
 */
final class FiscalSourceProvenanceCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FiscalSourceProvenance
    {
        if ($value === null || $value === '') {
            return null;
        }

        return FiscalSourceProvenance::tryFrom((string) $value) ?? FiscalSourceProvenance::Unverified;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $provenance = $value instanceof FiscalSourceProvenance
            ? $value
            : FiscalSourceProvenance::tryFrom((string) $value);

        return ($provenance ?? FiscalSourceProvenance::Unverified)->value;
    }
}
