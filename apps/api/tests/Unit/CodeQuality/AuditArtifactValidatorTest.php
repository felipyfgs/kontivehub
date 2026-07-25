<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\ArtifactAreaReconciler;
use Tools\CodeQuality\AuditArtifactValidator;
use Tools\CodeQuality\LedgerReconciler;

class AuditArtifactValidatorTest extends TestCase
{
    private AuditArtifactValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AuditArtifactValidator;
    }

    #[Test]
    public function valid_reviewed_artifacts_are_accepted(): void
    {
        $symbol = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $inventory = $this->inventory([$symbol]);
        $ledger = $this->ledger($inventory, [[
            'symbolId' => $symbol['id'],
            'status' => 'reviewed-with-findings',
            'categories' => ['functional-correctness'],
            'findingIds' => ['CQ-0001'],
            'note' => 'Fluxo revisado contra o contrato.',
            'reviewBatch' => 'backend-contracts-01',
        ]]);
        $findings = $this->findings($inventory, [$this->finding($symbol)]);

        $this->assertSame([], $this->validator->validate($inventory, $ledger, $findings));
    }

    #[Test]
    public function new_symbol_is_reconciled_as_pending(): void
    {
        $oldInventory = $this->inventory([]);
        $oldLedger = $this->ledger($oldInventory, []);
        $newSymbol = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $newInventory = $this->inventory([$newSymbol]);

        $result = (new LedgerReconciler)->reconcile($oldInventory, $oldLedger, $newInventory);

        $this->assertSame('pending', $result['ledger']['entries'][0]['status']);
        $this->assertSame([], $result['idMap']);
        $this->assertSame([], $result['orphanedSymbolIds']);
    }

    #[Test]
    public function orphan_and_missing_ledger_entries_are_reported(): void
    {
        $symbol = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $orphan = $this->symbol('apps/api/app/Example.php::Example::old@8', 8);
        $inventory = $this->inventory([$symbol]);
        $ledger = $this->ledger($inventory, [$this->pendingEntry($orphan['id'])]);

        $codes = $this->codes($this->validator->validate(
            $inventory,
            $ledger,
            $this->findings($inventory, []),
        ));

        $this->assertContains('ledger.missing-symbol', $codes);
        $this->assertContains('ledger.orphan-symbol', $codes);
    }

    #[Test]
    public function ambiguous_fingerprint_collision_returns_to_pending(): void
    {
        $oldFirst = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $oldSecond = $this->symbol('apps/api/app/Example.php::Example::run@9', 9);
        $newSymbol = $this->symbol('apps/api/app/Example.php::Example::run@14', 14);
        $oldInventory = $this->inventory([$oldFirst, $oldSecond]);
        $newInventory = $this->inventory([$newSymbol]);
        $oldLedger = $this->ledger($oldInventory, [
            $this->pendingEntry($oldFirst['id']),
            $this->pendingEntry($oldSecond['id']),
        ]);

        $result = (new LedgerReconciler)->reconcile($oldInventory, $oldLedger, $newInventory);

        $this->assertSame('pending', $result['ledger']['entries'][0]['status']);
        $this->assertSame([$oldFirst['id'], $oldSecond['id']], $result['collisions'][0]['candidateSymbolIds']);
        $this->assertSame([$oldFirst['id'], $oldSecond['id']], $result['orphanedSymbolIds']);
    }

    #[Test]
    public function unchanged_symbol_moved_by_lines_keeps_review_state(): void
    {
        $old = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $new = $this->symbol('apps/api/app/Example.php::Example::run@15', 15);
        $oldInventory = $this->inventory([$old]);
        $newInventory = $this->inventory([$new]);
        $oldEntry = [
            'symbolId' => $old['id'],
            'status' => 'reviewed-no-finding',
            'categories' => ['functional-correctness'],
            'findingIds' => [],
            'note' => 'Contrato revisado.',
            'reviewBatch' => 'backend-contracts-01',
        ];

        $result = (new LedgerReconciler)->reconcile(
            $oldInventory,
            $this->ledger($oldInventory, [$oldEntry]),
            $newInventory,
        );

        $this->assertSame('reviewed-no-finding', $result['ledger']['entries'][0]['status']);
        $this->assertSame([$old['id'] => $new['id']], $result['idMap']);
    }

    #[Test]
    public function area_reconciliation_remaps_finding_evidence_when_reviewed_symbol_moves(): void
    {
        $old = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $new = $this->symbol('apps/api/app/Example.php::Example::run@15', 15);
        $oldInventory = $this->inventory([$old]);
        $newInventory = $this->inventory([$new]);
        $ledger = $this->ledger($oldInventory, [[
            'symbolId' => $old['id'],
            'status' => 'reviewed-with-findings',
            'categories' => ['functional-correctness'],
            'findingIds' => ['CQ-0001'],
            'note' => 'Fluxo revisado.',
            'reviewBatch' => 'backend-contracts-01',
        ]]);

        $result = (new ArtifactAreaReconciler)->reconcile(
            $oldInventory,
            $ledger,
            $this->findings($oldInventory, [$this->finding($old)]),
            $newInventory,
        );

        $this->assertSame($newInventory['digest'], $result['findings']['inventoryDigest']);
        $this->assertSame($new['id'], $result['findings']['items'][0]['evidence'][0]['symbolId']);
        $this->assertSame('reviewed-with-findings', $result['ledger']['entries'][0]['status']);
    }

    #[Test]
    public function area_reconciliation_returns_changed_symbol_to_pending(): void
    {
        $old = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $new = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $new['fingerprint'] = str_repeat('c', 64);
        $oldInventory = $this->inventory([$old]);
        $newInventory = $this->inventory([$new]);
        $newInventory['digest'] = $this->validator->expectedInventoryDigest($newInventory);

        $result = (new ArtifactAreaReconciler)->reconcile(
            $oldInventory,
            $this->ledger($oldInventory, [[
                'symbolId' => $old['id'],
                'status' => 'reviewed-no-finding',
                'categories' => ['functional-correctness'],
                'findingIds' => [],
                'note' => 'Fluxo revisado.',
                'reviewBatch' => 'backend-contracts-01',
            ]]),
            $this->findings($oldInventory, []),
            $newInventory,
        );

        $this->assertSame('pending', $result['ledger']['entries'][0]['status']);
        $this->assertSame([$old['id']], $result['orphanedSymbolIds']);
    }

    #[Test]
    public function promotion_without_explicit_review_batch_is_rejected(): void
    {
        $symbol = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $inventory = $this->inventory([$symbol]);
        $previous = $this->ledger($inventory, [$this->pendingEntry($symbol['id'])]);
        $promoted = $this->ledger($inventory, [[
            'symbolId' => $symbol['id'],
            'status' => 'reviewed-no-finding',
            'categories' => ['functional-correctness'],
            'findingIds' => [],
            'note' => 'Promoção automática indevida.',
            'reviewBatch' => null,
        ]]);

        $codes = $this->codes($this->validator->validate(
            $inventory,
            $promoted,
            $this->findings($inventory, []),
            $previous,
        ));

        $this->assertContains('ledger.promotion-review-batch', $codes);
    }

    #[Test]
    public function closed_categories_and_finding_references_are_enforced(): void
    {
        $symbol = $this->symbol('apps/api/app/Example.php::Example::run@5', 5);
        $inventory = $this->inventory([$symbol]);
        $ledger = $this->ledger($inventory, [[
            'symbolId' => $symbol['id'],
            'status' => 'reviewed-with-findings',
            'categories' => ['categoria-inventada'],
            'findingIds' => ['CQ-9999'],
            'note' => 'Entrada inválida para o teste.',
            'reviewBatch' => 'invalid-01',
        ]]);

        $codes = $this->codes($this->validator->validate(
            $inventory,
            $ledger,
            $this->findings($inventory, []),
        ));

        $this->assertContains('ledger.category', $codes);
        $this->assertContains('ledger.finding-missing', $codes);
    }

    /** @return array<string, mixed> */
    private function symbol(string $id, int $line): array
    {
        return [
            'id' => $id,
            'path' => 'apps/api/app/Example.php',
            'qualifiedName' => 'Example::run',
            'displayName' => 'run',
            'parentId' => null,
            'kind' => 'method',
            'language' => 'php',
            'startLine' => $line,
            'endLine' => $line + 2,
            'parameters' => [],
            'metrics' => [
                'lines' => 3,
                'branches' => 0,
                'complexity' => 1,
                'maxDepth' => 0,
                'parameterCount' => 0,
                'importFanOut' => 0,
                'tokenCount' => 5,
            ],
            'fingerprint' => str_repeat('a', 64),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $symbols
     * @return array<string, mixed>
     */
    private function inventory(array $symbols): array
    {
        $file = [
            'path' => 'apps/api/app/Example.php',
            'app' => 'api',
            'category' => 'application',
            'language' => 'php',
            'bytes' => 200,
            'lines' => 40,
            'sha256' => str_repeat('b', 64),
            'executable' => true,
            'symbolCount' => count($symbols),
            'parseErrors' => [],
        ];
        $inventory = [
            'schemaVersion' => 1,
            'scope' => [
                'command' => 'git ls-files --cached --others --exclude-standard apps/api apps/web',
                'roots' => ['apps/api', 'apps/web'],
                'excludedByGitIgnore' => true,
            ],
            'digest' => '',
            'summary' => [
                'files' => 1,
                'symbols' => count($symbols),
                'executableFiles' => 1,
                'parseErrors' => 0,
                'byApp' => ['api' => 1],
                'byCategory' => ['application' => 1],
                'byLanguage' => ['php' => 1],
                'bySymbolKind' => $symbols === [] ? [] : ['method' => count($symbols)],
            ],
            'files' => [$file],
            'symbols' => $symbols,
        ];
        $inventory['digest'] = $this->validator->expectedInventoryDigest($inventory);

        return $inventory;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function ledger(array $inventory, array $entries): array
    {
        return [
            'schemaVersion' => 1,
            'inventoryDigest' => $inventory['digest'],
            'entries' => $entries,
        ];
    }

    /** @return array<string, mixed> */
    private function pendingEntry(string $symbolId): array
    {
        return [
            'symbolId' => $symbolId,
            'status' => 'pending',
            'categories' => [],
            'findingIds' => [],
            'note' => 'Aguardando revisão.',
            'reviewBatch' => null,
        ];
    }

    /** @param array<string, mixed> $symbol @return array<string, mixed> */
    private function finding(array $symbol): array
    {
        return [
            'id' => 'CQ-0001',
            'severity' => 'P2',
            'category' => 'functional-defect',
            'title' => 'Exemplo reproduzível',
            'evidence' => [[
                'path' => $symbol['path'],
                'line' => $symbol['startLine'],
                'endLine' => $symbol['endLine'],
                'symbolId' => $symbol['id'],
                'description' => 'Evidência mínima para o teste do validador.',
            ]],
            'impact' => 'Contrato poderia divergir.',
            'recommendation' => 'Corrigir em change derivada.',
            'expectedTests' => ['Teste Unit de regressão.'],
            'status' => 'open',
            'change' => null,
            'resolution' => null,
            'ui' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function findings(array $inventory, array $items): array
    {
        return [
            'schemaVersion' => 1,
            'inventoryDigest' => $inventory['digest'],
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     * @return list<string>
     */
    private function codes(array $errors): array
    {
        return array_column($errors, 'code');
    }
}
