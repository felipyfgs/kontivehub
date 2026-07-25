<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unicidade estrutural: no máximo uma PlatformMembership PLATFORM_ADMIN por instalação.
 * Pré-flight falha se houver duplicados (exige consolidate explícito).
 * Índice parcial compatível com PostgreSQL e SQLite.
 */
return new class extends Migration
{
    private const INDEX = 'platform_memberships_one_platform_admin';

    public function up(): void
    {
        if (! Schema::hasTable('platform_memberships')) {
            throw new RuntimeException(
                'platform_owner_unique: tabela platform_memberships ausente.',
            );
        }

        $count = (int) DB::table('platform_memberships')
            ->where('role', 'PLATFORM_ADMIN')
            ->count();

        if ($count > 1) {
            $sample = DB::table('platform_memberships')
                ->where('role', 'PLATFORM_ADMIN')
                ->orderBy('id')
                ->limit(10)
                ->get(['id', 'user_id'])
                ->map(fn ($r) => "id={$r->id}/user={$r->user_id}")
                ->implode('; ');

            throw new RuntimeException(
                'platform_owner_unique: há mais de um PLATFORM_ADMIN ('.$count.'). '
                .'Execute `php artisan app:platform-owner:consolidate --keep=<user-id>` '
                .'antes desta migration. Amostra: '.$sample,
            );
        }

        if ($this->indexExists()) {
            return;
        }

        // Partial unique: no máximo uma linha com role = PLATFORM_ADMIN.
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON platform_memberships (role)'
            ." WHERE role = 'PLATFORM_ADMIN'",
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_memberships')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            return;
        }

        // SQLite
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function indexExists(): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE indexname = ? LIMIT 1',
                [self::INDEX],
            );

            return $row !== null;
        }

        // SQLite: PRAGMA index_list
        $indexes = DB::select("PRAGMA index_list('platform_memberships')");
        foreach ($indexes as $idx) {
            $name = $idx->name ?? $idx->Name ?? null;
            if ($name === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
