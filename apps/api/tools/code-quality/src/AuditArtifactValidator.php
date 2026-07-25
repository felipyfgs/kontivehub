<?php

namespace Tools\CodeQuality;

use RuntimeException;

class AuditArtifactValidator
{
    /** @var array<string, mixed> */
    private readonly array $definitions;

    /** @param array<string, mixed>|null $schema */
    public function __construct(?array $schema = null)
    {
        $schema ??= $this->loadSchema();
        $definitions = $schema['$defs'] ?? null;
        if (! is_array($definitions)) {
            throw new RuntimeException('Schema de auditoria sem $defs.');
        }
        $this->definitions = $definitions;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $findings
     * @param  array<string, mixed>|null  $previousLedger
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    public function validate(
        array $inventory,
        array $ledger,
        array $findings,
        ?array $previousLedger = null,
        bool $final = false,
    ): array {
        $errors = [];
        $files = $this->rows($inventory['files'] ?? null);
        $symbols = $this->rows($inventory['symbols'] ?? null);
        $entries = $this->rows($ledger['entries'] ?? null);
        $findingRows = $this->rows($findings['items'] ?? null);

        $this->validateInventory($inventory, $files, $symbols, $errors);
        $symbolMap = $this->uniqueMap($symbols, 'id', 'inventory.symbol-id-collision', $errors);
        $fileMap = $this->uniqueMap($files, 'path', 'inventory.file-path-collision', $errors);
        $findingMap = $this->uniqueMap($findingRows, 'id', 'findings.id-collision', $errors);
        $entryMap = $this->uniqueMap($entries, 'symbolId', 'ledger.symbol-id-collision', $errors);

        $digest = (string) ($inventory['digest'] ?? '');
        foreach (['ledger' => $ledger, 'findings' => $findings] as $artifact => $payload) {
            if (($payload['inventoryDigest'] ?? null) !== $digest) {
                $this->error($errors, "{$artifact}.inventory-digest", "{$artifact} não referencia o digest do inventário.");
            }
        }

        $this->validateLedger(
            $entries,
            $entryMap,
            $symbolMap,
            $findingMap,
            $previousLedger,
            $final,
            $errors,
        );
        $this->validateFindings($findingRows, $findingMap, $entryMap, $symbolMap, $fileMap, $final, $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $findings
     * @param  array<string, mixed>|null  $previousLedger
     */
    public function assertValid(
        array $inventory,
        array $ledger,
        array $findings,
        ?array $previousLedger = null,
        bool $final = false,
    ): void {
        $errors = $this->validate($inventory, $ledger, $findings, $previousLedger, $final);
        if ($errors !== []) {
            throw new RuntimeException(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /** @param array<string, mixed> $inventory */
    public function expectedInventoryDigest(array $inventory): string
    {
        $core = [
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'scope' => $inventory['scope'] ?? null,
            'summary' => $inventory['summary'] ?? null,
            'files' => $inventory['files'] ?? null,
            'symbols' => $inventory['symbols'] ?? null,
        ];

        return hash('sha256', json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $symbols
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     */
    private function validateInventory(array $inventory, array $files, array $symbols, array &$errors): void
    {
        if (($inventory['schemaVersion'] ?? null) !== 1) {
            $this->error($errors, 'inventory.schema-version', 'Versão do inventário inválida.');
        }
        if (($inventory['digest'] ?? null) !== $this->expectedInventoryDigest($inventory)) {
            $this->error($errors, 'inventory.digest', 'Digest do inventário não corresponde ao conteúdo.');
        }

        $summary = is_array($inventory['summary'] ?? null) ? $inventory['summary'] : [];
        if (($summary['files'] ?? null) !== count($files)) {
            $this->error($errors, 'inventory.file-count', 'Resumo de arquivos diverge da lista.');
        }
        if (($summary['symbols'] ?? null) !== count($symbols)) {
            $this->error($errors, 'inventory.symbol-count', 'Resumo de símbolos diverge da lista.');
        }

        $symbolsByPath = [];
        foreach ($symbols as $symbol) {
            $path = (string) ($symbol['path'] ?? '');
            $symbolsByPath[$path] = ($symbolsByPath[$path] ?? 0) + 1;
        }
        $knownPaths = array_fill_keys(array_map(fn (array $file): string => (string) ($file['path'] ?? ''), $files), true);
        foreach ($symbols as $symbol) {
            $path = (string) ($symbol['path'] ?? '');
            if (! isset($knownPaths[$path])) {
                $this->error($errors, 'inventory.symbol-file', 'Símbolo referencia arquivo ausente.', ['symbolId' => $symbol['id'] ?? null, 'path' => $path]);
            }
        }
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if (($file['symbolCount'] ?? null) !== ($symbolsByPath[$path] ?? 0)) {
                $this->error($errors, 'inventory.file-symbol-count', 'Contagem de símbolos do arquivo diverge.', ['path' => $path]);
            }
            if ($this->rows($file['parseErrors'] ?? null) !== []) {
                $this->error($errors, 'inventory.parse-error', 'Inventário contém erro de parse.', ['path' => $path]);
            }
        }

        $ids = array_fill_keys(array_map(fn (array $symbol): string => (string) ($symbol['id'] ?? ''), $symbols), true);
        foreach ($symbols as $symbol) {
            $parentId = $symbol['parentId'] ?? null;
            if (is_string($parentId) && $parentId !== '' && ! isset($ids[$parentId])) {
                $this->error($errors, 'inventory.symbol-parent', 'Símbolo referencia parentId ausente.', ['symbolId' => $symbol['id'] ?? null, 'parentId' => $parentId]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, array<string, mixed>>  $entryMap
     * @param  array<string, array<string, mixed>>  $symbolMap
     * @param  array<string, array<string, mixed>>  $findingMap
     * @param  array<string, mixed>|null  $previousLedger
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     */
    private function validateLedger(
        array $entries,
        array $entryMap,
        array $symbolMap,
        array $findingMap,
        ?array $previousLedger,
        bool $final,
        array &$errors,
    ): void {
        $statuses = $this->enum('reviewStatus');
        $categories = $this->enum('reviewCategory');
        $previousMap = $previousLedger === null
            ? []
            : $this->mapWithoutErrors($this->rows($previousLedger['entries'] ?? null), 'symbolId');

        foreach ($symbolMap as $symbolId => $_symbol) {
            if (! isset($entryMap[$symbolId])) {
                $this->error($errors, 'ledger.missing-symbol', 'Símbolo sem entrada no ledger.', ['symbolId' => $symbolId]);
            }
        }
        foreach ($entryMap as $symbolId => $_entry) {
            if (! isset($symbolMap[$symbolId])) {
                $this->error($errors, 'ledger.orphan-symbol', 'Entrada órfã no ledger.', ['symbolId' => $symbolId]);
            }
        }

        foreach ($entries as $entry) {
            $symbolId = (string) ($entry['symbolId'] ?? '');
            $status = (string) ($entry['status'] ?? '');
            $entryCategories = $this->strings($entry['categories'] ?? null);
            $findingIds = $this->strings($entry['findingIds'] ?? null);
            if (! in_array($status, $statuses, true)) {
                $this->error($errors, 'ledger.status', 'Estado de revisão fora do conjunto fechado.', ['symbolId' => $symbolId, 'status' => $status]);
            }
            foreach (array_diff($entryCategories, $categories) as $category) {
                $this->error($errors, 'ledger.category', 'Categoria de revisão inválida.', ['symbolId' => $symbolId, 'category' => $category]);
            }
            if (count($entryCategories) !== count(array_unique($entryCategories))) {
                $this->error($errors, 'ledger.category-duplicate', 'Categoria de revisão repetida.', ['symbolId' => $symbolId]);
            }
            if (count($findingIds) !== count(array_unique($findingIds))) {
                $this->error($errors, 'ledger.finding-duplicate', 'Finding repetido na entrada.', ['symbolId' => $symbolId]);
            }
            foreach ($findingIds as $findingId) {
                if (! isset($findingMap[$findingId])) {
                    $this->error($errors, 'ledger.finding-missing', 'Entrada referencia finding inexistente.', ['symbolId' => $symbolId, 'findingId' => $findingId]);
                }
            }

            if ($status === 'pending' && ($entryCategories !== [] || $findingIds !== [])) {
                $this->error($errors, 'ledger.pending-evidence', 'Símbolo pendente não pode carregar conclusão de revisão.', ['symbolId' => $symbolId]);
            }
            if ($status === 'reviewed-no-finding' && ($entryCategories === [] || $findingIds !== [])) {
                $this->error($errors, 'ledger.reviewed-no-finding', 'Revisão sem finding exige categorias e nenhuma referência.', ['symbolId' => $symbolId]);
            }
            if ($status === 'reviewed-with-findings' && ($entryCategories === [] || $findingIds === [])) {
                $this->error($errors, 'ledger.reviewed-with-findings', 'Revisão com findings exige categorias e referências.', ['symbolId' => $symbolId]);
            }
            if ($status === 'excluded-with-reason' && trim((string) ($entry['note'] ?? '')) === '') {
                $this->error($errors, 'ledger.exclusion-reason', 'Exclusão exige razão objetiva.', ['symbolId' => $symbolId]);
            }
            if ($final && $status === 'pending') {
                $this->error($errors, 'ledger.pending-final', 'Verificação final não aceita símbolo pendente.', ['symbolId' => $symbolId]);
            }

            $previous = $previousMap[$symbolId] ?? null;
            $previousStatus = is_array($previous) ? ($previous['status'] ?? null) : null;
            if (($previousStatus === 'pending' || ($previous === null && $previousLedger !== null)) && $status !== 'pending') {
                if (trim((string) ($entry['reviewBatch'] ?? '')) === '') {
                    $this->error($errors, 'ledger.promotion-review-batch', 'Promoção de revisão exige lote humano explícito.', ['symbolId' => $symbolId]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, array<string, mixed>>  $findingMap
     * @param  array<string, array<string, mixed>>  $entryMap
     * @param  array<string, array<string, mixed>>  $symbolMap
     * @param  array<string, array<string, mixed>>  $fileMap
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     */
    private function validateFindings(
        array $findings,
        array $findingMap,
        array $entryMap,
        array $symbolMap,
        array $fileMap,
        bool $final,
        array &$errors,
    ): void {
        $severities = ['P0', 'P1', 'P2', 'P3'];
        $categories = $this->enum('findingCategory');
        $statuses = $this->definitions['finding']['properties']['status']['enum'] ?? [];

        foreach ($findings as $finding) {
            $findingId = (string) ($finding['id'] ?? '');
            $severity = (string) ($finding['severity'] ?? '');
            $category = (string) ($finding['category'] ?? '');
            $status = (string) ($finding['status'] ?? '');
            if (! preg_match('/^CQ-[0-9]{4}$/', $findingId)) {
                $this->error($errors, 'findings.id', 'ID de finding inválido.', ['findingId' => $findingId]);
            }
            if (! in_array($severity, $severities, true)) {
                $this->error($errors, 'findings.severity', 'Severidade inválida.', ['findingId' => $findingId]);
            }
            if (! in_array($category, $categories, true)) {
                $this->error($errors, 'findings.category', 'Categoria de finding inválida.', ['findingId' => $findingId]);
            }
            if (! in_array($status, $statuses, true)) {
                $this->error($errors, 'findings.status', 'Estado de finding inválido.', ['findingId' => $findingId]);
            }
            $evidenceRows = $this->rows($finding['evidence'] ?? null);
            if ($evidenceRows === []) {
                $this->error($errors, 'findings.evidence', 'Finding exige evidência.', ['findingId' => $findingId]);
            }
            if ($this->strings($finding['expectedTests'] ?? null) === []) {
                $this->error($errors, 'findings.expected-tests', 'Finding exige teste esperado.', ['findingId' => $findingId]);
            }
            if ($category === 'ux-accessibility' && ! is_array($finding['ui'] ?? null)) {
                $this->error($errors, 'findings.ui-evidence', 'Finding de UI exige evidência do arquétipo.', ['findingId' => $findingId]);
            }
            if ($final && in_array($severity, ['P0', 'P1'], true) && $status === 'open' && trim((string) ($finding['change'] ?? '')) === '') {
                $this->error($errors, 'findings.critical-treatment', 'P0/P1 aberto exige change responsável.', ['findingId' => $findingId]);
            }

            foreach ($evidenceRows as $evidence) {
                $path = (string) ($evidence['path'] ?? '');
                $line = (int) ($evidence['line'] ?? 0);
                if (! isset($fileMap[$path])) {
                    $this->error($errors, 'findings.evidence-file', 'Evidência referencia arquivo ausente.', ['findingId' => $findingId, 'path' => $path]);
                } elseif ($line < 1 || $line > (int) ($fileMap[$path]['lines'] ?? 0)) {
                    $this->error($errors, 'findings.evidence-line', 'Linha da evidência está fora do arquivo.', ['findingId' => $findingId, 'path' => $path, 'line' => $line]);
                }

                $symbolId = $evidence['symbolId'] ?? null;
                if (! is_string($symbolId) || $symbolId === '') {
                    continue;
                }
                if (! isset($symbolMap[$symbolId])) {
                    $this->error($errors, 'findings.evidence-symbol', 'Evidência referencia símbolo ausente.', ['findingId' => $findingId, 'symbolId' => $symbolId]);

                    continue;
                }
                if (($symbolMap[$symbolId]['path'] ?? null) !== $path) {
                    $this->error($errors, 'findings.evidence-symbol-path', 'Path da evidência diverge do símbolo.', ['findingId' => $findingId, 'symbolId' => $symbolId]);
                }
                if (! in_array($findingId, $this->strings($entryMap[$symbolId]['findingIds'] ?? null), true)) {
                    $this->error($errors, 'findings.ledger-link', 'Símbolo da evidência não referencia o finding no ledger.', ['findingId' => $findingId, 'symbolId' => $symbolId]);
                }
            }
        }

        foreach ($entryMap as $symbolId => $entry) {
            foreach ($this->strings($entry['findingIds'] ?? null) as $findingId) {
                if (! isset($findingMap[$findingId])) {
                    continue;
                }
                $linked = array_filter(
                    $this->rows($findingMap[$findingId]['evidence'] ?? null),
                    fn (array $evidence): bool => ($evidence['symbolId'] ?? null) === $symbolId,
                );
                if ($linked === []) {
                    $this->error($errors, 'ledger.finding-evidence-link', 'Finding do ledger não contém evidência para o símbolo.', ['findingId' => $findingId, 'symbolId' => $symbolId]);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadSchema(): array
    {
        $path = dirname(__DIR__, 3).'/resources/code-quality/schema.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler {$path}");
        }
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($schema)) {
            throw new RuntimeException('Schema de auditoria inválido.');
        }

        return $schema;
    }

    /** @return list<string> */
    private function enum(string $definition): array
    {
        return $this->strings($this->definitions[$definition]['enum'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     * @return array<string, array<string, mixed>>
     */
    private function uniqueMap(array $rows, string $key, string $errorCode, array &$errors): array
    {
        $map = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? '');
            if ($value === '') {
                $this->error($errors, $errorCode, "Chave {$key} vazia.");
            } elseif (isset($map[$value])) {
                $this->error($errors, $errorCode, "Chave {$key} duplicada.", [$key => $value]);
            } else {
                $map[$value] = $row;
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function mapWithoutErrors(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? '');
            if ($value !== '' && ! isset($map[$value])) {
                $map[$value] = $row;
            }
        }

        return $map;
    }

    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     * @param  array<string, mixed>  $context
     */
    private function error(array &$errors, string $code, string $message, array $context = []): void
    {
        $errors[] = compact('code', 'message', 'context');
    }
}
