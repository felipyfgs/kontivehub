<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class LegacyIdentitySchemaCompatibilityMigrationTest extends TestCase
{
    /** @var list<string> */
    private const LEGACY_TABLES = [
        'tenants',
        'users',
        'tenant_memberships',
        'platform_memberships',
        'platform_settings',
    ];

    private ?string $schemaName = null;

    private string $originalSearchPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSearchPath = (string) config('database.connections.pgsql.search_path', 'public');
        $this->schemaName = 'identity_compat_'.strtolower(Str::random(12));

        DB::statement('CREATE SCHEMA '.$this->schemaName);
        config()->set('database.connections.pgsql.search_path', $this->schemaName);
        DB::purge();
    }

    protected function tearDown(): void
    {
        if ($this->schemaName !== null) {
            DB::purge();
            config()->set('database.connections.pgsql.search_path', $this->originalSearchPath);
            DB::purge();
            DB::statement('DROP SCHEMA IF EXISTS '.$this->schemaName.' CASCADE');
        }

        parent::tearDown();
    }

    public function test_aligns_legacy_identity_fail_closed_and_rolls_back_only_added_columns(): void
    {
        $this->createLegacyIdentitySchema();
        $this->seedLegacyIdentityRows();
        $before = $this->schemaSnapshot(self::LEGACY_TABLES);

        $migration = $this->migration();
        $migration->up();

        self::assertTrue((bool) DB::table('users')->where('id', 1)->value('is_active'));
        self::assertFalse((bool) DB::table('users')->where('id', 2)->value('is_active'));
        self::assertTrue((bool) DB::table('tenants')->where('id', 1)->value('is_active'));
        self::assertSame('SUSPENDED', DB::table('tenants')->where('id', 2)->value('lifecycle_status'));
        self::assertTrue((bool) DB::table('tenant_memberships')->where('id', 1)->value('is_active'));
        self::assertFalse((bool) DB::table('tenant_memberships')->where('id', 2)->value('is_active'));
        self::assertTrue((bool) DB::table('platform_memberships')->where('id', 1)->value('is_active'));
        self::assertFalse((bool) DB::table('platform_memberships')->where('id', 2)->value('is_active'));
        self::assertSame(1, DB::table('platform_memberships')->where('id', 1)->value('default_tenant_id'));
        self::assertNull(DB::table('platform_memberships')->where('id', 2)->value('default_tenant_id'));
        self::assertSame(
            'KontiveHub legado',
            DB::table('platform_settings')->where('id', 1)->value('organization_name'),
        );

        self::assertTrue(Schema::hasIndex('tenant_memberships', ['user_id', 'is_active']));
        self::assertTrue(Schema::hasIndex('platform_memberships', ['user_id', 'is_active']));
        self::assertContains(
            'platform_memberships_default_tenant_id_foreign',
            array_column(Schema::getForeignKeys('platform_memberships'), 'name'),
        );

        $migration->down();

        self::assertFalse(Schema::hasTable('legacy_identity_compatibility_columns'));
        self::assertFalse(Schema::hasColumn('users', 'is_active'));
        self::assertFalse(Schema::hasColumn('tenants', 'lifecycle_status'));
        self::assertTrue(Schema::hasColumn('users', 'selected_tenant_id'));
        self::assertTrue(Schema::hasColumn('tenants', 'timezone'));
        self::assertSame(1, DB::table('users')->where('id', 1)->value('selected_tenant_id'));
        self::assertSame(
            'America/Fortaleza',
            DB::table('tenants')->where('id', 1)->value('timezone'),
        );
        self::assertSame($before, $this->schemaSnapshot(self::LEGACY_TABLES));
    }

    public function test_does_not_touch_current_tables_without_legacy_status(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active');
        });
        DB::table('users')->insert(['id' => 1, 'is_active' => true]);

        $this->migration()->up();

        self::assertTrue((bool) DB::table('users')->where('id', 1)->value('is_active'));
        self::assertFalse(Schema::hasTable('legacy_identity_compatibility_columns'));
    }

    public function test_hybrid_schema_preserves_restrictive_canonical_value(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->boolean('is_active');
        });
        DB::table('users')->insert([
            'id' => 1,
            'status' => 'ACTIVE',
            'is_active' => false,
        ]);

        $migration = $this->migration();
        $migration->up();

        self::assertFalse((bool) DB::table('users')->where('id', 1)->value('is_active'));

        $migration->down();

        self::assertFalse((bool) DB::table('users')->where('id', 1)->value('is_active'));
        self::assertSame(
            ['id', 'status', 'is_active'],
            array_column(Schema::getColumns('users'), 'name'),
        );
    }

    public function test_hybrid_schema_aborts_on_permissive_canonical_conflict(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->boolean('is_active');
        });
        DB::table('users')->insert([
            'id' => 1,
            'status' => 'LOCKED',
            'is_active' => true,
        ]);

        try {
            $this->migration()->up();
            self::fail('A migration deveria rejeitar o conflito permissivo.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Conflito permissivo', $exception->getMessage());
        }

        self::assertFalse(Schema::hasTable('legacy_identity_compatibility_columns'));
        self::assertSame(
            ['id', 'status', 'is_active'],
            array_column(Schema::getColumns('users'), 'name'),
        );
        self::assertTrue((bool) DB::table('users')->where('id', 1)->value('is_active'));
    }

    public function test_backfills_only_memberships_with_fully_canonical_roles_and_profiles(): void
    {
        Schema::create('tenant_permission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('is_active');
        });
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('permission_profile_id')->nullable();
        });

        DB::table('tenant_permission_profiles')->insert([
            ['id' => 10, 'tenant_id' => 1, 'is_active' => true],
            ['id' => 20, 'tenant_id' => 1, 'is_active' => false],
            ['id' => 30, 'tenant_id' => 2, 'is_active' => true],
        ]);
        DB::table('tenant_memberships')->insert([
            ['id' => 1, 'tenant_id' => 1, 'user_id' => 1, 'role' => 'tenant_admin', 'status' => 'ACTIVE', 'permission_profile_id' => null],
            ['id' => 2, 'tenant_id' => 1, 'user_id' => 2, 'role' => 'tenant_admin', 'status' => 'ACTIVE', 'permission_profile_id' => 10],
            ['id' => 3, 'tenant_id' => 1, 'user_id' => 3, 'role' => 'tenant_user', 'status' => 'ACTIVE', 'permission_profile_id' => 10],
            ['id' => 4, 'tenant_id' => 1, 'user_id' => 4, 'role' => 'tenant_user', 'status' => 'ACTIVE', 'permission_profile_id' => 20],
            ['id' => 5, 'tenant_id' => 1, 'user_id' => 5, 'role' => 'tenant_user', 'status' => 'ACTIVE', 'permission_profile_id' => 30],
            ['id' => 6, 'tenant_id' => 1, 'user_id' => 6, 'role' => 'tenant_user', 'status' => 'ACTIVE', 'permission_profile_id' => null],
            ['id' => 7, 'tenant_id' => 1, 'user_id' => 7, 'role' => 'legacy_owner', 'status' => 'ACTIVE', 'permission_profile_id' => null],
            ['id' => 8, 'tenant_id' => 1, 'user_id' => 8, 'role' => 'tenant_user', 'status' => 'SUSPENDED', 'permission_profile_id' => 10],
        ]);

        $migration = $this->migration();
        $migration->up();

        self::assertSame(
            [1 => true, 2 => false, 3 => true, 4 => false, 5 => false, 6 => false, 7 => false, 8 => false],
            DB::table('tenant_memberships')
                ->orderBy('id')
                ->pluck('is_active', 'id')
                ->map(static fn (mixed $active): bool => (bool) $active)
                ->all(),
        );

        $migration->down();

        self::assertTrue(Schema::hasColumn('tenant_memberships', 'permission_profile_id'));
        self::assertFalse(Schema::hasColumn('tenant_memberships', 'is_active'));
    }

    public function test_adds_and_removes_composite_permission_profile_integrity(): void
    {
        Schema::create('tenant_permission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('is_active');
        });
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
        });

        $migration = $this->migration();
        $migration->up();

        self::assertTrue(Schema::hasIndex(
            'tenant_permission_profiles',
            ['id', 'tenant_id'],
            'unique',
        ));
        self::assertTrue(Schema::hasForeignKey(
            'tenant_memberships',
            'legacy_identity_membership_permission_profile_fk',
        ));

        DB::table('tenant_permission_profiles')->insert([
            'id' => 10,
            'tenant_id' => 1,
            'is_active' => true,
        ]);
        DB::table('tenant_memberships')->insert([
            'id' => 1,
            'tenant_id' => 1,
            'user_id' => 1,
            'role' => 'tenant_user',
            'status' => 'ACTIVE',
            'permission_profile_id' => 10,
        ]);
        DB::table('tenant_permission_profiles')->where('id', 10)->delete();

        self::assertSame(
            ['tenant_id' => 1, 'permission_profile_id' => null],
            (array) DB::table('tenant_memberships')
                ->where('id', 1)
                ->first(['tenant_id', 'permission_profile_id']),
        );

        $migration->down();

        self::assertFalse(Schema::hasColumn('tenant_memberships', 'permission_profile_id'));
        self::assertFalse(Schema::hasIndex(
            'tenant_permission_profiles',
            'legacy_identity_permission_profiles_id_tenant_unique',
        ));
    }

    public function test_missing_permission_profile_contract_keeps_tenant_user_inactive(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('permission_profile_id')->nullable();
        });
        DB::table('tenant_memberships')->insert([
            'id' => 1,
            'tenant_id' => 1,
            'user_id' => 1,
            'role' => 'tenant_user',
            'status' => 'ACTIVE',
            'permission_profile_id' => 10,
        ]);

        $migration = $this->migration();
        $migration->up();

        self::assertFalse((bool) DB::table('tenant_memberships')->where('id', 1)->value('is_active'));
        self::assertFalse(Schema::hasForeignKey(
            'tenant_memberships',
            'legacy_identity_membership_permission_profile_fk',
        ));

        $migration->down();

        self::assertTrue(Schema::hasColumn('tenant_memberships', 'permission_profile_id'));
        self::assertFalse(Schema::hasColumn('tenant_memberships', 'is_active'));
    }

    public function test_missing_role_contract_keeps_membership_inactive(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 32);
        });
        DB::table('tenant_memberships')->insert([
            'id' => 1,
            'tenant_id' => 1,
            'user_id' => 1,
            'status' => 'ACTIVE',
        ]);

        $migration = $this->migration();
        $migration->up();

        self::assertFalse((bool) DB::table('tenant_memberships')->where('id', 1)->value('is_active'));

        $migration->down();

        self::assertFalse(Schema::hasColumn('tenant_memberships', 'is_active'));
    }

    public function test_invalid_legacy_default_tenant_is_not_copied_before_foreign_key(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('platform_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
        });
        DB::table('platform_memberships')->insert([
            'id' => 1,
            'user_id' => 1,
            'tenant_id' => 999,
            'role' => 'platform_admin',
            'status' => 'ACTIVE',
        ]);

        $migration = $this->migration();
        $migration->up();

        self::assertNull(
            DB::table('platform_memberships')->where('id', 1)->value('default_tenant_id'),
        );
        self::assertTrue(Schema::hasForeignKey(
            'platform_memberships',
            'platform_memberships_default_tenant_id_foreign',
        ));

        $migration->down();

        self::assertFalse(Schema::hasColumn('platform_memberships', 'default_tenant_id'));
    }

    public function test_transactional_failure_rolls_back_ddl_backfill_and_bookkeeping(): void
    {
        $this->createLegacyIdentitySchema();
        $this->seedLegacyIdentityRows();
        $before = $this->schemaSnapshot(self::LEGACY_TABLES);

        try {
            DB::transaction(function (): void {
                $this->migration()->up();

                throw new RuntimeException('Falha simulada após o backfill.');
            });
            self::fail('A transação da migration deveria falhar.');
        } catch (RuntimeException $exception) {
            self::assertSame('Falha simulada após o backfill.', $exception->getMessage());
        }

        self::assertFalse(Schema::hasTable('legacy_identity_compatibility_columns'));
        self::assertSame($before, $this->schemaSnapshot(self::LEGACY_TABLES));
    }

    public function test_platform_settings_tolerates_missing_optional_legacy_source_columns(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
        });
        DB::table('platform_settings')->insert(['id' => 1, 'status' => 'ACTIVE']);

        $migration = $this->migration();
        $migration->up();

        self::assertNull(DB::table('platform_settings')->where('id', 1)->value('organization_name'));
        self::assertNull(DB::table('platform_settings')->where('id', 1)->value('onboarding_completed_at'));

        $migration->down();

        self::assertSame(
            ['id', 'status'],
            array_column(Schema::getColumns('platform_settings'), 'name'),
        );
    }

    private function createLegacyIdentitySchema(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 32);
            $table->string('timezone', 64)->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->unsignedBigInteger('selected_tenant_id')->nullable();
        });
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
        });
        Schema::create('platform_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('role', 40)->nullable();
            $table->string('status', 32);
        });
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 32);
            $table->timestampTz('created_at')->nullable();
        });
    }

    private function seedLegacyIdentityRows(): void
    {
        DB::table('tenants')->insert([
            ['id' => 1, 'name' => 'Tenant ativo', 'status' => 'ACTIVE', 'timezone' => 'America/Fortaleza'],
            ['id' => 2, 'name' => 'Tenant suspenso', 'status' => 'SUSPENDED', 'timezone' => null],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'status' => 'ACTIVE', 'selected_tenant_id' => 1],
            ['id' => 2, 'status' => 'LOCKED', 'selected_tenant_id' => null],
        ]);
        DB::table('tenant_memberships')->insert([
            ['id' => 1, 'tenant_id' => 1, 'user_id' => 1, 'role' => 'tenant_admin', 'status' => 'ACTIVE'],
            ['id' => 2, 'tenant_id' => 1, 'user_id' => 2, 'role' => 'legacy_owner', 'status' => 'ACTIVE'],
        ]);
        DB::table('platform_memberships')->insert([
            ['id' => 1, 'user_id' => 1, 'tenant_id' => 1, 'role' => 'platform_admin', 'status' => 'ACTIVE'],
            ['id' => 2, 'user_id' => 2, 'tenant_id' => 2, 'role' => 'legacy_admin', 'status' => 'ACTIVE'],
        ]);
        DB::table('platform_settings')->insert([
            'id' => 1,
            'name' => 'KontiveHub legado',
            'status' => 'ACTIVE',
            'created_at' => '2026-07-27 12:00:00-03',
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_07_28_020000_align_legacy_identity_schema_for_authentication.php',
        );
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, array{
     *     columns: list<string>,
     *     indexes: list<array{name: string, columns: list<string>, unique: bool, primary: bool}>,
     *     foreign_keys: list<array{name: string|null, columns: list<string>, foreign_table: string, foreign_columns: list<string>}>,
     *     rows: list<array<string, mixed>>
     * }>
     */
    private function schemaSnapshot(array $tables): array
    {
        $snapshot = [];

        foreach ($tables as $table) {
            $indexes = array_map(
                static fn (array $index): array => [
                    'name' => $index['name'],
                    'columns' => $index['columns'],
                    'unique' => $index['unique'],
                    'primary' => $index['primary'],
                ],
                Schema::getIndexes($table),
            );
            $foreignKeys = array_map(
                static fn (array $foreignKey): array => [
                    'name' => $foreignKey['name'],
                    'columns' => $foreignKey['columns'],
                    'foreign_table' => $foreignKey['foreign_table'],
                    'foreign_columns' => $foreignKey['foreign_columns'],
                ],
                Schema::getForeignKeys($table),
            );
            usort($indexes, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
            usort(
                $foreignKeys,
                static fn (array $left, array $right): int => ($left['name'] ?? '') <=> ($right['name'] ?? ''),
            );

            $snapshot[$table] = [
                'columns' => array_column(Schema::getColumns($table), 'name'),
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
                'rows' => DB::table($table)
                    ->orderBy('id')
                    ->get()
                    ->map(static fn (object $row): array => (array) $row)
                    ->all(),
            ];
        }

        return $snapshot;
    }
}
