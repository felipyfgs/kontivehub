<?php

namespace Tools\CodeQuality;

use InvalidArgumentException;

class LedgerReconciler
{
    /**
     * @param  array<string, mixed>  $oldInventory
     * @param  array<string, mixed>  $oldLedger
     * @param  array<string, mixed>  $newInventory
     * @return array{
     *     ledger: array<string, mixed>,
     *     idMap: array<string, string>,
     *     orphanedSymbolIds: list<string>,
     *     collisions: list<array{newSymbolId: string, candidateSymbolIds: list<string>}>
     * }
     */
    public function reconcile(array $oldInventory, array $oldLedger, array $newInventory): array
    {
        $oldSymbols = $this->uniqueMap($oldInventory['symbols'] ?? [], 'id');
        $oldEntries = $this->uniqueMap($oldLedger['entries'] ?? [], 'symbolId');
        $newSymbols = $this->uniqueMap($newInventory['symbols'] ?? [], 'id');
        $usedOldIds = [];
        $idMap = [];
        $collisions = [];
        $entries = [];

        foreach ($newSymbols as $newId => $newSymbol) {
            $candidateIds = $this->candidates($newId, $newSymbol, $oldSymbols, $usedOldIds);
            if (count($candidateIds) === 1) {
                $oldId = $candidateIds[0];
                $usedOldIds[$oldId] = true;
                $idMap[$oldId] = $newId;
                $entry = $oldEntries[$oldId] ?? $this->pendingEntry($newId, 'Símbolo anterior sem entrada inequívoca no ledger.');
                $entry['symbolId'] = $newId;
                $entries[] = $entry;

                continue;
            }

            if (count($candidateIds) > 1) {
                $collisions[] = ['newSymbolId' => $newId, 'candidateSymbolIds' => $candidateIds];
            }
            $entries[] = $this->pendingEntry(
                $newId,
                $candidateIds === []
                    ? 'Símbolo novo ou alterado; revisão semântica necessária.'
                    : 'Correspondência ambígua; revisão semântica necessária.',
            );
        }

        $orphaned = array_values(array_diff(array_keys($oldSymbols), array_keys($usedOldIds)));
        sort($orphaned, SORT_STRING);
        ksort($idMap, SORT_STRING);

        return [
            'ledger' => [
                'schemaVersion' => 1,
                'inventoryDigest' => (string) ($newInventory['digest'] ?? ''),
                'entries' => $entries,
            ],
            'idMap' => $idMap,
            'orphanedSymbolIds' => $orphaned,
            'collisions' => $collisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $newSymbol
     * @param  array<string, array<string, mixed>>  $oldSymbols
     * @param  array<string, bool>  $usedOldIds
     * @return list<string>
     */
    private function candidates(string $newId, array $newSymbol, array $oldSymbols, array $usedOldIds): array
    {
        $exact = $oldSymbols[$newId] ?? null;
        if (is_array($exact) && ! isset($usedOldIds[$newId]) && $this->sameSemanticKey($exact, $newSymbol)) {
            return [$newId];
        }

        $candidates = [];
        foreach ($oldSymbols as $oldId => $oldSymbol) {
            if (! isset($usedOldIds[$oldId]) && $this->sameSemanticKey($oldSymbol, $newSymbol)) {
                $candidates[] = $oldId;
            }
        }
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameSemanticKey(array $left, array $right): bool
    {
        foreach (['path', 'qualifiedName', 'kind', 'language', 'fingerprint'] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function pendingEntry(string $symbolId, string $note): array
    {
        return [
            'symbolId' => $symbolId,
            'status' => 'pending',
            'categories' => [],
            'findingIds' => [],
            'note' => $note,
            'reviewBatch' => null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function uniqueMap(mixed $rows, string $key): array
    {
        if (! is_array($rows)) {
            throw new InvalidArgumentException("Lista {$key} inválida.");
        }

        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row[$key] ?? null) || $row[$key] === '') {
                throw new InvalidArgumentException("Entrada sem {$key}.");
            }
            if (isset($map[$row[$key]])) {
                throw new InvalidArgumentException("Colisão de {$key}: {$row[$key]}");
            }
            $map[$row[$key]] = $row;
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
