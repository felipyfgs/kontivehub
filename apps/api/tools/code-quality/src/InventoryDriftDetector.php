<?php

namespace Tools\CodeQuality;

class InventoryDriftDetector
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $current
     * @return array{
     *     missingFiles: list<string>,
     *     unexpectedFiles: list<string>,
     *     changedFiles: list<string>,
     *     missingSymbols: list<string>,
     *     unexpectedSymbols: list<string>,
     *     changedSymbols: list<string>,
     *     scopeChanged: bool
     * }
     */
    public function compare(array $expected, array $current): array
    {
        $expectedFiles = $this->map($expected['files'] ?? [], 'path');
        $currentFiles = $this->map($current['files'] ?? [], 'path');
        $expectedSymbols = $this->map($expected['symbols'] ?? [], 'id');
        $currentSymbols = $this->map($current['symbols'] ?? [], 'id');

        return [
            'missingFiles' => $this->missing($expectedFiles, $currentFiles),
            'unexpectedFiles' => $this->missing($currentFiles, $expectedFiles),
            'changedFiles' => $this->changed($expectedFiles, $currentFiles, ['sha256', 'category', 'language']),
            'missingSymbols' => $this->missing($expectedSymbols, $currentSymbols),
            'unexpectedSymbols' => $this->missing($currentSymbols, $expectedSymbols),
            'changedSymbols' => $this->changed($expectedSymbols, $currentSymbols, ['fingerprint', 'path', 'qualifiedName', 'kind']),
            'scopeChanged' => ($expected['scope'] ?? null) !== ($current['scope'] ?? null),
        ];
    }

    /** @param array<string, mixed> $drift */
    public function hasDrift(array $drift): bool
    {
        foreach ($drift as $value) {
            if ($value === true || (is_array($value) && $value !== [])) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $rows @return array<string, array<string, mixed>> */
    private function map(mixed $rows, string $key): array
    {
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row[$key] ?? null)) {
                $map[$row[$key]] = $row;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return list<string>
     */
    private function missing(array $left, array $right): array
    {
        $keys = array_values(array_diff(array_keys($left), array_keys($right)));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $current
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function changed(array $expected, array $current, array $fields): array
    {
        $changed = [];
        foreach (array_intersect(array_keys($expected), array_keys($current)) as $key) {
            foreach ($fields as $field) {
                if (($expected[$key][$field] ?? null) !== ($current[$key][$field] ?? null)) {
                    $changed[] = $key;
                    break;
                }
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }
}
