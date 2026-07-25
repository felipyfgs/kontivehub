<?php

namespace App\Services\Fiscal\Availability;

use App\Enums\FiscalMutability;
use App\Enums\FiscalOperationClass;

/** Classifica operações sem depender do provider ou de flags de implantação. */
final class FiscalOperationClassifier
{
    public static function forMonitoring(
        FiscalMutability $mutability,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
    ): FiscalOperationClass {
        if ($mutability->isMutating()) {
            return FiscalOperationClass::FiscalMutation;
        }

        return self::looksLikeDocumentGeneration("{$systemCode} {$serviceCode} {$operationCode}")
            ? FiscalOperationClass::DocumentGeneration
            : FiscalOperationClass::Read;
    }

    /** @param array<string, mixed> $coordinates */
    public static function forSerpro(string $operationKey, array $coordinates): FiscalOperationClass
    {
        if ((bool) ($coordinates['is_mutating'] ?? false)) {
            return FiscalOperationClass::FiscalMutation;
        }

        $material = implode(' ', [
            $operationKey,
            (string) ($coordinates['id_sistema'] ?? ''),
            (string) ($coordinates['id_servico'] ?? ''),
            (string) ($coordinates['operation_code'] ?? ''),
        ]);

        return self::looksLikeDocumentGeneration($material)
            ? FiscalOperationClass::DocumentGeneration
            : FiscalOperationClass::Read;
    }

    private static function looksLikeDocumentGeneration(string $material): bool
    {
        $normalized = strtolower(str_replace(['-', '_', '.'], ' ', $material));

        foreach ([
            'gerar das',
            'gerardas',
            'gerar darf',
            'gerardarf',
            'gerar guia',
            'gerarguia',
            'emitir das',
            'emitirdas',
            'emitir darf',
            'emitirdarf',
            'emitir guia',
            'emitirguia',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
