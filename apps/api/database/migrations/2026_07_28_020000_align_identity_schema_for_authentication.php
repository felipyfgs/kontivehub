<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADDED_COLUMNS_TABLE = 'identity_schema_compatibility_columns';

    private const PERMISSION_PROFILE_COMPOSITE_UNIQUE = 'identity_schema_permission_profiles_id_tenant_unique';

    private const PERMISSION_PROFILE_COMPOSITE_UNIQUE_MARKER = '@index:identity_schema_permission_profiles_id_tenant_unique';

    private const TENANT_MEMBERSHIP_PERMISSION_PROFILE_FOREIGN = 'identity_schema_membership_permission_profile_fk';

    public $withinTransaction = true;

    public function up(): void
    {
        $this->assertNoPermissiveHybridConflicts();
        $this->alignUsers();
        $this->alignTenants();
        $this->alignTenantMemberships();
        $this->alignPlatformMemberships();
        $this->alignPlatformSettings();
    }

    public function down(): void
    {
        $this->dropRecordedCompatibilityColumns();
    }

    private function alignUsers(): void
    {
        if (! $this->usesStatus('users')) {
            return;
        }

        $missing = $this->missingColumns('users', [
            'is_active',
            'selected_tenant_id',
            'password_change_required',
        ]);

        if ($missing !== []) {
            $addSelectedTenantForeign = in_array('selected_tenant_id', $missing, true)
                && Schema::hasTable('tenants');

            Schema::table('users', function (Blueprint $table) use ($missing): void {
                if (in_array('is_active', $missing, true)) {
                    $table->boolean('is_active')->default(false);
                }
                if (in_array('selected_tenant_id', $missing, true)) {
                    $table->bigInteger('selected_tenant_id')->nullable()->index();
                }
                if (in_array('password_change_required', $missing, true)) {
                    $table->boolean('password_change_required')->default(false);
                }
            });

            if ($addSelectedTenantForeign) {
                Schema::table('users', function (Blueprint $table): void {
                    $table->foreign('selected_tenant_id')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                });
            }

            $this->recordAddedColumns('users', $missing);
        }

        if (in_array('is_active', $missing, true)) {
            DB::table('users')->update([
                'is_active' => DB::raw("COALESCE(UPPER(status), '') = 'ACTIVE'"),
            ]);
        }
    }

    private function alignTenants(): void
    {
        if (! $this->usesStatus('tenants')) {
            return;
        }

        $missing = $this->missingColumns('tenants', [
            'is_active',
            'deadline_timezone',
            'timezone',
            'serpro_segregation_class',
            'lifecycle_status',
            'communication_enabled',
        ]);

        if ($missing !== []) {
            Schema::table('tenants', function (Blueprint $table) use ($missing): void {
                if (in_array('is_active', $missing, true)) {
                    $table->boolean('is_active')->default(false);
                }
                if (in_array('deadline_timezone', $missing, true)) {
                    $table->string('deadline_timezone', 64)->nullable();
                }
                if (in_array('timezone', $missing, true)) {
                    $table->string('timezone', 64)->default('America/Sao_Paulo');
                }
                if (in_array('serpro_segregation_class', $missing, true)) {
                    $table->string('serpro_segregation_class', 40)->nullable();
                }
                if (in_array('lifecycle_status', $missing, true)) {
                    $table->string('lifecycle_status', 32)->default('DEPROVISIONED')->index();
                }
                if (in_array('communication_enabled', $missing, true)) {
                    $table->boolean('communication_enabled')->default(false);
                }
            });

            $this->recordAddedColumns('tenants', $missing);
        }

        $updates = [];
        if (in_array('is_active', $missing, true)) {
            $updates['is_active'] = DB::raw("COALESCE(UPPER(status), '') = 'ACTIVE'");
        }
        if (in_array('lifecycle_status', $missing, true)) {
            $updates['lifecycle_status'] = DB::raw(<<<'SQL'
                CASE UPPER(COALESCE(status, ''))
                    WHEN 'ACTIVE' THEN 'ACTIVE'
                    WHEN 'SUSPENDED' THEN 'SUSPENDED'
                    ELSE 'DEPROVISIONED'
                END
                SQL);
        }
        if ($updates !== []) {
            DB::table('tenants')->update($updates);
        }
    }

    private function alignTenantMemberships(): void
    {
        if (! $this->usesStatus('tenant_memberships')) {
            return;
        }

        $missing = $this->missingColumns('tenant_memberships', [
            'is_active',
            'permission_profile_id',
            'authorization_version',
            'work_department_id',
        ]);

        if ($missing !== []) {
            $addPermissionProfileForeign = in_array('permission_profile_id', $missing, true)
                && Schema::hasTable('tenant_permission_profiles')
                && Schema::hasColumns('tenant_permission_profiles', ['id', 'tenant_id']);
            $addWorkDepartmentForeign = in_array('work_department_id', $missing, true)
                && Schema::hasTable('work_departments');
            $addedPermissionProfileCompositeUnique = false;

            if ($addPermissionProfileForeign
                && ! Schema::hasIndex('tenant_permission_profiles', ['id', 'tenant_id'], 'unique')
            ) {
                Schema::table('tenant_permission_profiles', function (Blueprint $table): void {
                    $table->unique(
                        ['id', 'tenant_id'],
                        self::PERMISSION_PROFILE_COMPOSITE_UNIQUE,
                    );
                });
                $addedPermissionProfileCompositeUnique = true;
            }

            Schema::table('tenant_memberships', function (Blueprint $table) use (
                $missing,
                $addWorkDepartmentForeign,
            ): void {
                if (in_array('is_active', $missing, true)) {
                    $table->boolean('is_active')->default(false);
                    $table->index(['user_id', 'is_active']);
                }
                if (in_array('permission_profile_id', $missing, true)) {
                    $table->bigInteger('permission_profile_id')->nullable()->index();
                }
                if (in_array('authorization_version', $missing, true)) {
                    $table->integer('authorization_version')->default(1);
                    $table->index(['tenant_id', 'authorization_version']);
                }
                if (in_array('work_department_id', $missing, true)) {
                    $table->bigInteger('work_department_id')->nullable();
                }
                if ($addWorkDepartmentForeign) {
                    $table->foreign('work_department_id')
                        ->references('id')
                        ->on('work_departments')
                        ->nullOnDelete();
                }
            });

            if ($addPermissionProfileForeign) {
                DB::statement(sprintf(
                    <<<'SQL'
                        ALTER TABLE tenant_memberships
                        ADD CONSTRAINT %s
                        FOREIGN KEY (permission_profile_id, tenant_id)
                        REFERENCES tenant_permission_profiles (id, tenant_id)
                        ON UPDATE NO ACTION
                        ON DELETE SET NULL (permission_profile_id)
                        SQL,
                    self::TENANT_MEMBERSHIP_PERMISSION_PROFILE_FOREIGN,
                ));
            }

            $this->recordAddedColumns('tenant_memberships', $missing);
            if ($addedPermissionProfileCompositeUnique) {
                $this->recordAddedColumns('tenant_permission_profiles', [
                    self::PERMISSION_PROFILE_COMPOSITE_UNIQUE_MARKER,
                ]);
            }
        }

        if (in_array('is_active', $missing, true)) {
            $tenantUserHasCanonicalProfile = 'FALSE';
            $canonicalRole = 'FALSE';
            if (Schema::hasColumn('tenant_memberships', 'role')) {
                $canonicalRole = <<<'SQL'
                    (
                        (role = 'tenant_admin' AND permission_profile_id IS NULL)
                        OR (
                            role = 'tenant_user'
                            AND permission_profile_id IS NOT NULL
                            AND %s
                        )
                    )
                    SQL;
            }
            if (Schema::hasTable('tenant_permission_profiles')
                && Schema::hasColumns('tenant_permission_profiles', ['id', 'tenant_id', 'is_active'])
            ) {
                $tenantUserHasCanonicalProfile = <<<'SQL'
                    EXISTS (
                        SELECT 1
                        FROM tenant_permission_profiles
                        WHERE tenant_permission_profiles.id = tenant_memberships.permission_profile_id
                          AND tenant_permission_profiles.tenant_id = tenant_memberships.tenant_id
                          AND tenant_permission_profiles.is_active = TRUE
                    )
                    SQL;
            }

            DB::table('tenant_memberships')->update([
                'is_active' => DB::raw(sprintf(
                    <<<'SQL'
                        COALESCE(UPPER(status), '') = 'ACTIVE'
                        AND %s
                        SQL,
                    sprintf($canonicalRole, $tenantUserHasCanonicalProfile),
                )),
            ]);
        }
    }

    private function alignPlatformMemberships(): void
    {
        if (! $this->usesStatus('platform_memberships')) {
            return;
        }

        $missing = $this->missingColumns('platform_memberships', [
            'is_active',
            'default_tenant_id',
        ]);
        $addDefaultTenantForeign = false;

        if ($missing !== []) {
            $addDefaultTenantForeign = in_array('default_tenant_id', $missing, true)
                && Schema::hasTable('tenants')
                && Schema::hasColumn('tenants', 'id');

            Schema::table('platform_memberships', function (Blueprint $table) use ($missing): void {
                if (in_array('is_active', $missing, true)) {
                    $table->boolean('is_active')->default(false);
                    $table->index(['user_id', 'is_active']);
                }
                if (in_array('default_tenant_id', $missing, true)) {
                    $table->bigInteger('default_tenant_id')->nullable();
                }
            });

            $this->recordAddedColumns('platform_memberships', $missing);
        }

        $updates = [];
        if (in_array('is_active', $missing, true)) {
            $canonicalRole = $this->canonicalPlatformRoleSql();
            $updates['is_active'] = DB::raw(
                "COALESCE(UPPER(status), '') = 'ACTIVE' AND {$canonicalRole}",
            );
        }
        if (in_array('default_tenant_id', $missing, true)) {
            $updates['default_tenant_id'] = DB::raw($this->defaultTenantBackfillSql(
                $addDefaultTenantForeign,
            ));
        }
        if ($updates !== []) {
            DB::table('platform_memberships')->update($updates);
        }

        if ($addDefaultTenantForeign) {
            Schema::table('platform_memberships', function (Blueprint $table): void {
                $table->foreign('default_tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            });
        }
    }

    private function alignPlatformSettings(): void
    {
        if (! $this->usesStatus('platform_settings')) {
            return;
        }

        $missing = $this->missingColumns('platform_settings', [
            'organization_name',
            'onboarding_completed_at',
            'onboarded_by_user_id',
            'primary_tenant_id',
        ]);

        if ($missing !== []) {
            $addOnboardedByForeign = in_array('onboarded_by_user_id', $missing, true)
                && Schema::hasTable('users');
            $addPrimaryTenantForeign = in_array('primary_tenant_id', $missing, true)
                && Schema::hasTable('tenants');

            Schema::table('platform_settings', function (Blueprint $table) use (
                $missing,
                $addOnboardedByForeign,
                $addPrimaryTenantForeign,
            ): void {
                if (in_array('organization_name', $missing, true)) {
                    $table->string('organization_name')->nullable();
                }
                if (in_array('onboarding_completed_at', $missing, true)) {
                    $table->timestampTz('onboarding_completed_at')->nullable();
                }
                if (in_array('onboarded_by_user_id', $missing, true)) {
                    $table->bigInteger('onboarded_by_user_id')->nullable();
                }
                if (in_array('primary_tenant_id', $missing, true)) {
                    $table->bigInteger('primary_tenant_id')->nullable();
                }
                if ($addOnboardedByForeign) {
                    $table->foreign('onboarded_by_user_id')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }
                if ($addPrimaryTenantForeign) {
                    $table->foreign('primary_tenant_id')
                        ->references('id')
                        ->on('tenants')
                        ->restrictOnDelete();
                }
            });

            $this->recordAddedColumns('platform_settings', $missing);
        }

        $updates = [];
        if (in_array('organization_name', $missing, true)
            && Schema::hasColumn('platform_settings', 'name')
        ) {
            $updates['organization_name'] = DB::raw('name');
        }
        if (in_array('onboarding_completed_at', $missing, true)
            && Schema::hasColumn('platform_settings', 'created_at')
        ) {
            $updates['onboarding_completed_at'] = DB::raw('created_at');
        }
        if ($updates !== []) {
            DB::table('platform_settings')->update($updates);
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function missingColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @param  list<string>  $columns
     */
    private function recordAddedColumns(string $table, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        if (! Schema::hasTable(self::ADDED_COLUMNS_TABLE)) {
            Schema::create(self::ADDED_COLUMNS_TABLE, function (Blueprint $blueprint): void {
                $blueprint->string('table_name', 63);
                $blueprint->string('column_name', 63);
                $blueprint->primary(['table_name', 'column_name']);
            });
        }

        DB::table(self::ADDED_COLUMNS_TABLE)->insertOrIgnore(array_map(
            static fn (string $column): array => [
                'table_name' => $table,
                'column_name' => $column,
            ],
            $columns,
        ));
    }

    private function dropRecordedCompatibilityColumns(): void
    {
        if (! Schema::hasTable(self::ADDED_COLUMNS_TABLE)) {
            return;
        }

        $columnsByTable = DB::table(self::ADDED_COLUMNS_TABLE)
            ->get(['table_name', 'column_name'])
            ->groupBy('table_name');

        foreach ([
            'platform_settings',
            'platform_memberships',
            'tenant_memberships',
            'tenants',
            'users',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = $columnsByTable->get($table, collect())
                ->pluck('column_name')
                ->filter(static fn (mixed $column): bool => is_string($column))
                ->filter(static fn (string $column): bool => Schema::hasColumn($table, $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $this->dropRecordedForeignKeys($table, $columns);
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
            }
        }

        $permissionProfileEntries = $columnsByTable
            ->get('tenant_permission_profiles', collect())
            ->pluck('column_name');
        if ($permissionProfileEntries->contains(self::PERMISSION_PROFILE_COMPOSITE_UNIQUE_MARKER)
            && Schema::hasTable('tenant_permission_profiles')
            && Schema::hasIndex('tenant_permission_profiles', self::PERMISSION_PROFILE_COMPOSITE_UNIQUE)
        ) {
            Schema::table('tenant_permission_profiles', function (Blueprint $table): void {
                $table->dropUnique(self::PERMISSION_PROFILE_COMPOSITE_UNIQUE);
            });
        }

        Schema::drop(self::ADDED_COLUMNS_TABLE);
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropRecordedForeignKeys(string $table, array $columns): void
    {
        $constraints = match ($table) {
            'users' => [
                'selected_tenant_id' => ['users_selected_tenant_id_foreign'],
            ],
            'tenant_memberships' => [
                'permission_profile_id' => [self::TENANT_MEMBERSHIP_PERMISSION_PROFILE_FOREIGN],
                'work_department_id' => ['tenant_memberships_work_department_id_foreign'],
            ],
            'platform_memberships' => [
                'default_tenant_id' => ['platform_memberships_default_tenant_id_foreign'],
            ],
            'platform_settings' => [
                'onboarded_by_user_id' => ['platform_settings_onboarded_by_user_id_foreign'],
                'primary_tenant_id' => ['platform_settings_primary_tenant_id_foreign'],
            ],
            default => [],
        };

        foreach ($constraints as $column => $foreignKeys) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            foreach ($foreignKeys as $foreignKey) {
                if (Schema::hasForeignKey($table, $foreignKey)) {
                    Schema::table(
                        $table,
                        fn (Blueprint $blueprint) => $blueprint->dropForeign($foreignKey),
                    );
                }
            }
        }
    }

    private function usesStatus(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'status');
    }

    private function assertNoPermissiveHybridConflicts(): void
    {
        foreach (['users', 'tenants'] as $table) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumns($table, ['status', 'is_active'])
                && DB::table($table)
                    ->where('is_active', true)
                    ->whereRaw("COALESCE(UPPER(status), '') <> 'ACTIVE'")
                    ->exists()
            ) {
                throw new RuntimeException(
                    "Conflito permissivo entre status e is_active em {$table}.",
                );
            }
        }

        if (
            Schema::hasTable('tenant_memberships')
            && Schema::hasColumns('tenant_memberships', ['status', 'is_active'])
            && DB::table('tenant_memberships')
                ->where('is_active', true)
                ->whereRaw(
                    "NOT (COALESCE(UPPER(status), '') = 'ACTIVE'"
                    .' AND '.$this->canonicalTenantMembershipSql().')',
                )
                ->exists()
        ) {
            throw new RuntimeException(
                'Conflito permissivo entre status e is_active em tenant_memberships.',
            );
        }

        if (
            Schema::hasTable('platform_memberships')
            && Schema::hasColumns('platform_memberships', ['status', 'is_active'])
            && DB::table('platform_memberships')
                ->where('is_active', true)
                ->whereRaw(
                    "NOT (COALESCE(UPPER(status), '') = 'ACTIVE'"
                    .' AND '.$this->canonicalPlatformRoleSql().')',
                )
                ->exists()
        ) {
            throw new RuntimeException(
                'Conflito permissivo entre status e is_active em platform_memberships.',
            );
        }
    }

    private function canonicalTenantMembershipSql(): string
    {
        if (! Schema::hasColumn('tenant_memberships', 'role')) {
            return 'FALSE';
        }

        $admin = Schema::hasColumn('tenant_memberships', 'permission_profile_id')
            ? "(role = 'tenant_admin' AND permission_profile_id IS NULL)"
            : "role = 'tenant_admin'";
        $tenantUser = 'FALSE';
        if (
            Schema::hasColumns('tenant_memberships', ['tenant_id', 'permission_profile_id'])
            && Schema::hasTable('tenant_permission_profiles')
            && Schema::hasColumns('tenant_permission_profiles', ['id', 'tenant_id', 'is_active'])
        ) {
            $tenantUser = <<<'SQL'
                (
                    role = 'tenant_user'
                    AND permission_profile_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM tenant_permission_profiles
                        WHERE tenant_permission_profiles.id = tenant_memberships.permission_profile_id
                          AND tenant_permission_profiles.tenant_id = tenant_memberships.tenant_id
                          AND tenant_permission_profiles.is_active = TRUE
                    )
                )
                SQL;
        }

        return "({$admin} OR {$tenantUser})";
    }

    private function canonicalPlatformRoleSql(): string
    {
        return Schema::hasColumn('platform_memberships', 'role')
            ? "role = 'platform_admin'"
            : 'FALSE';
    }

    private function defaultTenantBackfillSql(bool $hasTenantForeign): string
    {
        if (
            ! $hasTenantForeign
            || ! Schema::hasColumns('platform_memberships', ['tenant_id', 'user_id'])
            || ! Schema::hasTable('users')
            || ! Schema::hasColumns('users', ['id', 'is_active'])
            || ! Schema::hasColumns('tenants', ['id', 'is_active', 'lifecycle_status'])
        ) {
            return 'NULL';
        }

        return sprintf(
            <<<'SQL'
                CASE
                    WHEN COALESCE(UPPER(status), '') = 'ACTIVE'
                     AND %s
                     AND EXISTS (
                         SELECT 1
                         FROM users
                         WHERE users.id = platform_memberships.user_id
                           AND users.is_active = TRUE
                     )
                     AND EXISTS (
                         SELECT 1
                         FROM tenants
                         WHERE tenants.id = platform_memberships.tenant_id
                           AND tenants.is_active = TRUE
                           AND tenants.lifecycle_status = 'ACTIVE'
                     )
                    THEN platform_memberships.tenant_id
                    ELSE NULL
                END
                SQL,
            $this->canonicalPlatformRoleSql(),
        );
    }
};
