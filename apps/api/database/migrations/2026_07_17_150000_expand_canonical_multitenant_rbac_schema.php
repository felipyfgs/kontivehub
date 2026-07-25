<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1 expand — schema aditivo do RBAC multi-tenant canônico + tenant principal.
 *
 * Forward-only: não edita migrations antigas; não remove colunas `role` legadas;
 * não remove o índice parcial singleton `platform_memberships_one_platform_admin`;
 * não usa SoftDeletes em perfis (is_active).
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_permission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('authorization_version')->default(1);
            $table->timestamps();

            $table->unique(['office_id', 'key'], 'tpp_office_key_unique');
            $table->unique(['office_id', 'name'], 'tpp_office_name_unique');
            // Suporte a FK composta membership→profile do mesmo office (PostgreSQL/SQLite).
            $table->unique(['id', 'office_id'], 'tpp_id_office_unique');
            $table->index(['office_id', 'is_active'], 'tpp_office_active_idx');
        });

        Schema::create('tenant_permission_profile_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('permission_profile_id')
                ->constrained('tenant_permission_profiles')
                ->cascadeOnDelete();
            $table->string('permission_key', 80);
            $table->timestamps();

            $table->unique(
                ['permission_profile_id', 'permission_key'],
                'tppp_profile_key_unique'
            );
            $table->index('permission_key', 'tppp_permission_key_idx');
        });

        Schema::table('office_user', function (Blueprint $table): void {
            $table->string('tenant_role', 32)->nullable()->after('role');
            $table->unsignedBigInteger('permission_profile_id')->nullable()->after('tenant_role');
            $table->unsignedInteger('authorization_version')->default(1)->after('permission_profile_id');

            $table->index(['office_id', 'tenant_role'], 'office_user_office_tenant_role_idx');
            $table->index('permission_profile_id', 'office_user_permission_profile_idx');
            $table->index(
                ['office_id', 'authorization_version'],
                'office_user_office_auth_version_idx'
            );
        });

        // FK simples + composta (mesmo office). Em falha de portabilidade o guard de domínio
        // em service continua obrigatório (ver tasks 2.2 / 5.x).
        Schema::table('office_user', function (Blueprint $table): void {
            $table->foreign('permission_profile_id', 'office_user_permission_profile_fk')
                ->references('id')
                ->on('tenant_permission_profiles')
                ->nullOnDelete();

            $table->foreign(
                ['permission_profile_id', 'office_id'],
                'office_user_profile_office_composite_fk'
            )
                ->references(['id', 'office_id'])
                ->on('tenant_permission_profiles')
                ->nullOnDelete();
        });

        Schema::table('platform_memberships', function (Blueprint $table): void {
            $table->string('platform_role', 32)->nullable()->after('role');
            $table->index('platform_role', 'platform_memberships_platform_role_idx');
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->foreignId('primary_office_id')
                ->nullable()
                ->after('onboarded_by_user_id')
                ->constrained('offices')
                ->restrictOnDelete();
        });

        // lifecycle_status já é string livre; SUSPENDED|DEPROVISIONED passam a ser
        // valores canônicos no enum OfficeLifecycleStatus (sem CHECK destrutivo).
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('platform_settings', 'primary_office_id')) {
                $table->dropConstrainedForeignId('primary_office_id');
            }
        });

        Schema::table('platform_memberships', function (Blueprint $table): void {
            if (Schema::hasColumn('platform_memberships', 'platform_role')) {
                $table->dropIndex('platform_memberships_platform_role_idx');
                $table->dropColumn('platform_role');
            }
        });

        Schema::table('office_user', function (Blueprint $table): void {
            if (Schema::hasColumn('office_user', 'permission_profile_id')) {
                $table->dropForeign('office_user_profile_office_composite_fk');
                $table->dropForeign('office_user_permission_profile_fk');
            }
            $drops = array_values(array_filter([
                Schema::hasColumn('office_user', 'tenant_role') ? 'tenant_role' : null,
                Schema::hasColumn('office_user', 'permission_profile_id') ? 'permission_profile_id' : null,
                Schema::hasColumn('office_user', 'authorization_version') ? 'authorization_version' : null,
            ]));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        Schema::dropIfExists('tenant_permission_profile_permissions');
        Schema::dropIfExists('tenant_permission_profiles');
    }
};
