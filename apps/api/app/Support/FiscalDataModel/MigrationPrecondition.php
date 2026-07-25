<?php

namespace App\Support\FiscalDataModel;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Pré-condições explícitas para migrations desta change.
 * Falha com diagnóstico — não usar hasTable/hasColumn silencioso.
 */
final class MigrationPrecondition
{
    public static function tableExists(string $table, string $context = ''): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException(self::message(
                "Tabela obrigatória ausente: {$table}",
                $context,
            ));
        }
    }

    public static function tableMissing(string $table, string $context = ''): void
    {
        if (Schema::hasTable($table)) {
            throw new RuntimeException(self::message(
                "Tabela já existe (esperado ausente): {$table}",
                $context,
            ));
        }
    }

    public static function columnExists(string $table, string $column, string $context = ''): void
    {
        self::tableExists($table, $context);

        if (! Schema::hasColumn($table, $column)) {
            throw new RuntimeException(self::message(
                "Coluna obrigatória ausente: {$table}.{$column}",
                $context,
            ));
        }
    }

    public static function columnMissing(string $table, string $column, string $context = ''): void
    {
        self::tableExists($table, $context);

        if (Schema::hasColumn($table, $column)) {
            throw new RuntimeException(self::message(
                "Coluna já existe (esperado ausente): {$table}.{$column}",
                $context,
            ));
        }
    }

    /**
     * @param  list<string>  $tables
     */
    public static function tablesExist(array $tables, string $context = ''): void
    {
        foreach ($tables as $table) {
            self::tableExists($table, $context);
        }
    }

    private static function message(string $detail, string $context): string
    {
        $prefix = 'Pré-condição de migration falhou';
        if ($context !== '') {
            $prefix .= " [{$context}]";
        }

        return "{$prefix}: {$detail}. Corrija o schema ou a ordem das migrations; não ignore com hasTable/hasColumn silencioso.";
    }
}
