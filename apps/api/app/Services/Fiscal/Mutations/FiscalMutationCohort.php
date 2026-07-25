<?php

namespace App\Services\Fiscal\Mutations;

/**
 * Liberação por solução/operação/coorte (13.7).
 * Defaults OFF — allowlist vazia não libera ninguém salvo allow_all_offices.
 */
final class FiscalMutationCohort
{
    public static function operationKey(string $solution, string $service, string $operation): string
    {
        return strtoupper($solution).'.'.strtoupper($service).'.'.strtoupper($operation);
    }

    public static function moduleForSolution(string $solutionCode): string
    {
        $map = config('fiscal_mutations.solution_modules', []);
        $key = strtoupper($solutionCode);

        return is_array($map) && isset($map[$key]) ? (string) $map[$key] : 'mutacoes';
    }

    public static function isOperationEnabled(string $solution, string $service, string $operation, int $officeId): bool
    {
        if (! (bool) config('fiscal_mutations.enabled', false)) {
            return false;
        }

        if ((bool) config('fiscal_mutations.kill_switch', false)) {
            return false;
        }

        $key = self::operationKey($solution, $service, $operation);
        /**
         * As chaves do catálogo contêm pontos (ex.: INTEGRA_MEI.PGMEI.GERAR_DAS).
         * O helper config() interpreta pontos como separadores de caminho, portanto
         * precisamos buscar a chave literal no mapa de operações.
         *
         * @var array<string, mixed> $operations
         */
        $operations = (array) config('fiscal_mutations.operations', []);
        $cfg = $operations[$key] ?? null;

        if (! is_array($cfg)) {
            // Operação sem coorte explícita permanece bloqueada
            return false;
        }

        if (! (bool) ($cfg['enabled'] ?? false)) {
            return false;
        }

        /** @var list<int>|mixed $allowlist */
        $allowlist = $cfg['office_allowlist'] ?? [];
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return (bool) ($cfg['allow_all_offices'] ?? false);
        }

        return in_array($officeId, $allowlist, true);
    }
}
