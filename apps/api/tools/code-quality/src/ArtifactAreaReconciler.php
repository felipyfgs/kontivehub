<?php

namespace Tools\CodeQuality;

final class ArtifactAreaReconciler
{
    public function __construct(
        private readonly LedgerReconciler $ledgers = new LedgerReconciler,
        private readonly AuditArtifactValidator $validator = new AuditArtifactValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $oldInventory
     * @param  array<string, mixed>  $oldLedger
     * @param  array<string, mixed>  $oldFindings
     * @param  array<string, mixed>  $newInventory
     * @return array{
     *     ledger: array<string, mixed>,
     *     findings: array<string, mixed>,
     *     idMap: array<string, string>,
     *     orphanedSymbolIds: list<string>,
     *     collisions: list<array{newSymbolId: string, candidateSymbolIds: list<string>}>
     * }
     */
    public function reconcile(
        array $oldInventory,
        array $oldLedger,
        array $oldFindings,
        array $newInventory,
    ): array {
        $result = $this->ledgers->reconcile($oldInventory, $oldLedger, $newInventory);
        $findings = $this->remapFindings($oldFindings, $result['idMap'], (string) $newInventory['digest']);

        $this->validator->assertValid($newInventory, $result['ledger'], $findings);

        return [
            'ledger' => $result['ledger'],
            'findings' => $findings,
            'idMap' => $result['idMap'],
            'orphanedSymbolIds' => $result['orphanedSymbolIds'],
            'collisions' => $result['collisions'],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @param  array<string, string>  $idMap
     * @return array<string, mixed>
     */
    private function remapFindings(array $findings, array $idMap, string $inventoryDigest): array
    {
        $findings['inventoryDigest'] = $inventoryDigest;
        if (! isset($findings['items']) || ! is_array($findings['items'])) {
            return $findings;
        }
        foreach ($findings['items'] as &$finding) {
            if (! is_array($finding)) {
                continue;
            }
            if (! isset($finding['evidence']) || ! is_array($finding['evidence'])) {
                continue;
            }
            foreach ($finding['evidence'] as &$evidence) {
                if (! is_array($evidence)) {
                    continue;
                }
                $symbolId = $evidence['symbolId'] ?? null;
                if (is_string($symbolId) && isset($idMap[$symbolId])) {
                    $evidence['symbolId'] = $idMap[$symbolId];
                }
            }
            unset($evidence);
        }
        unset($finding);

        return $findings;
    }
}
