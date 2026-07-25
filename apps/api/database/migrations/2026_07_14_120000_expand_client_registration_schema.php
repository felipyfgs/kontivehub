<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPreMigrationInvariants();

        // --- clients ---
        if (Schema::hasColumn('clients', 'name') && ! Schema::hasColumn('clients', 'legal_name')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->renameColumn('name', 'legal_name');
            });
        }

        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'display_name')) {
                $table->string('display_name')->nullable()->after('legal_name');
            }
            if (! Schema::hasColumn('clients', 'legal_nature_code')) {
                $table->string('legal_nature_code', 16)->nullable()->after('root_cnpj');
            }
            if (! Schema::hasColumn('clients', 'legal_nature_name')) {
                $table->string('legal_nature_name')->nullable()->after('legal_nature_code');
            }
            if (! Schema::hasColumn('clients', 'company_size_code')) {
                $table->string('company_size_code', 16)->nullable()->after('legal_nature_name');
            }
            if (! Schema::hasColumn('clients', 'company_size_name')) {
                $table->string('company_size_name')->nullable()->after('company_size_code');
            }
            if (! Schema::hasColumn('clients', 'inactive_reason')) {
                $table->text('inactive_reason')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('clients', 'registration_source')) {
                $table->string('registration_source', 16)->default('LEGACY')->after('notes');
            }
            if (! Schema::hasColumn('clients', 'registration_refreshed_at')) {
                $table->timestamp('registration_refreshed_at')->nullable()->after('registration_source');
            }
        });

        // Índice de busca: renomear de name → legal_name quando necessário
        $this->dropIndexIfExists('clients', 'clients_office_id_name_index');
        if (! $this->indexExists('clients', 'clients_office_id_legal_name_index')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->index(['office_id', 'legal_name']);
            });
        }

        // --- establishments ---
        Schema::table('establishments', function (Blueprint $table): void {
            if (! Schema::hasColumn('establishments', 'registration_status')) {
                $table->string('registration_status', 16)->default('UNKNOWN')->after('is_active');
            }
            if (! Schema::hasColumn('establishments', 'registration_status_at')) {
                $table->date('registration_status_at')->nullable()->after('registration_status');
            }
            if (! Schema::hasColumn('establishments', 'registration_status_reason')) {
                $table->string('registration_status_reason')->nullable()->after('registration_status_at');
            }
            if (! Schema::hasColumn('establishments', 'activity_started_at')) {
                $table->date('activity_started_at')->nullable()->after('registration_status_reason');
            }
            if (! Schema::hasColumn('establishments', 'main_cnae_code')) {
                $table->string('main_cnae_code', 16)->nullable()->after('activity_started_at');
            }
            if (! Schema::hasColumn('establishments', 'main_cnae_name')) {
                $table->string('main_cnae_name')->nullable()->after('main_cnae_code');
            }
            if (! Schema::hasColumn('establishments', 'address_postal_code')) {
                $table->string('address_postal_code', 16)->nullable()->after('main_cnae_name');
            }
            if (! Schema::hasColumn('establishments', 'address_street_type')) {
                $table->string('address_street_type', 32)->nullable()->after('address_postal_code');
            }
            if (! Schema::hasColumn('establishments', 'address_street')) {
                $table->string('address_street')->nullable()->after('address_street_type');
            }
            if (! Schema::hasColumn('establishments', 'address_number')) {
                $table->string('address_number', 32)->nullable()->after('address_street');
            }
            if (! Schema::hasColumn('establishments', 'address_complement')) {
                $table->string('address_complement')->nullable()->after('address_number');
            }
            if (! Schema::hasColumn('establishments', 'address_district')) {
                $table->string('address_district')->nullable()->after('address_complement');
            }
            if (! Schema::hasColumn('establishments', 'address_city')) {
                $table->string('address_city')->nullable()->after('address_district');
            }
            if (! Schema::hasColumn('establishments', 'address_city_ibge_code')) {
                $table->string('address_city_ibge_code', 16)->nullable()->after('address_city');
            }
            if (! Schema::hasColumn('establishments', 'address_state')) {
                $table->string('address_state', 2)->nullable()->after('address_city_ibge_code');
            }
            if (! Schema::hasColumn('establishments', 'address_country')) {
                $table->string('address_country', 64)->nullable()->after('address_state');
            }
            if (! Schema::hasColumn('establishments', 'public_email')) {
                $table->string('public_email')->nullable()->after('address_country');
            }
            if (! Schema::hasColumn('establishments', 'public_phone')) {
                $table->string('public_phone', 32)->nullable()->after('public_email');
            }
            if (! Schema::hasColumn('establishments', 'capture_enabled')) {
                $table->boolean('capture_enabled')->default(true)->after('public_phone');
            }
            if (! Schema::hasColumn('establishments', 'registration_source')) {
                $table->string('registration_source', 16)->default('LEGACY')->after('capture_enabled');
            }
            if (! Schema::hasColumn('establishments', 'registration_refreshed_at')) {
                $table->timestamp('registration_refreshed_at')->nullable()->after('registration_source');
            }
        });

        // Backfill: captura segue o estado operacional vigente; registros atuais = LEGACY
        DB::table('establishments')->update([
            'capture_enabled' => DB::raw('is_active'),
        ]);
        DB::table('establishments')
            ->where(function ($q): void {
                $q->whereNull('registration_source')->orWhere('registration_source', '');
            })
            ->update(['registration_source' => 'LEGACY']);
        DB::table('establishments')
            ->where(function ($q): void {
                $q->whereNull('registration_status')->orWhere('registration_status', '');
            })
            ->update(['registration_status' => 'UNKNOWN']);
        DB::table('clients')
            ->where(function ($q): void {
                $q->whereNull('registration_source')->orWhere('registration_source', '');
            })
            ->update(['registration_source' => 'LEGACY']);

        // --- client_contacts ---
        if (! Schema::hasTable('client_contacts')) {
            Schema::create('client_contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('role')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 32)->nullable();
                $table->boolean('is_whatsapp')->default(false);
                $table->boolean('is_primary')->default(false);
                $table->boolean('receives_alerts')->default(false);
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['client_id', 'is_active']);
                $table->index(['office_id', 'client_id']);
            });
        }

        $this->createPartialUniqueIndexes();
    }

    public function down(): void
    {
        // Rollback seguro apenas antes de dados reais nos novos campos.
        $this->dropIndexIfExists('establishments', 'establishments_one_matrix_per_client');
        $this->dropIndexIfExists('client_contacts', 'client_contacts_one_primary_active_per_client');

        Schema::dropIfExists('client_contacts');

        Schema::table('establishments', function (Blueprint $table): void {
            $columns = [
                'registration_status', 'registration_status_at', 'registration_status_reason',
                'activity_started_at', 'main_cnae_code', 'main_cnae_name',
                'address_postal_code', 'address_street_type', 'address_street', 'address_number',
                'address_complement', 'address_district', 'address_city', 'address_city_ibge_code',
                'address_state', 'address_country', 'public_email', 'public_phone',
                'capture_enabled', 'registration_source', 'registration_refreshed_at',
            ];
            $existing = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn('establishments', $c)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        $this->dropIndexIfExists('clients', 'clients_office_id_legal_name_index');

        Schema::table('clients', function (Blueprint $table): void {
            $columns = [
                'display_name', 'legal_nature_code', 'legal_nature_name',
                'company_size_code', 'company_size_name', 'inactive_reason',
                'registration_source', 'registration_refreshed_at',
            ];
            $existing = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn('clients', $c)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        if (Schema::hasColumn('clients', 'legal_name') && ! Schema::hasColumn('clients', 'name')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->renameColumn('legal_name', 'name');
            });
        }

        if (! $this->indexExists('clients', 'clients_office_id_name_index') && Schema::hasColumn('clients', 'name')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->index(['office_id', 'name']);
            });
        }
    }

    private function assertPreMigrationInvariants(): void
    {
        $emptyNames = 0;
        if (Schema::hasColumn('clients', 'name')) {
            $emptyNames = DB::table('clients')
                ->whereNull('deleted_at')
                ->where(function ($q): void {
                    $q->whereNull('name')->orWhere('name', '');
                })
                ->count();
        } elseif (Schema::hasColumn('clients', 'legal_name')) {
            $emptyNames = DB::table('clients')
                ->whereNull('deleted_at')
                ->where(function ($q): void {
                    $q->whereNull('legal_name')->orWhere('legal_name', '');
                })
                ->count();
        }

        $duplicateRoots = DB::table('clients')
            ->whereNull('deleted_at')
            ->select(['office_id', 'root_cnpj', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'root_cnpj')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateCnpjs = DB::table('establishments')
            ->whereNull('deleted_at')
            ->select(['office_id', 'cnpj', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'cnpj')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $multipleMatrices = DB::table('establishments')
            ->whereNull('deleted_at')
            ->where('is_matrix', true)
            ->select(['client_id', DB::raw('COUNT(*) as total')])
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $issues = [];
        if ($emptyNames > 0) {
            $issues[] = "nomes vazios: {$emptyNames}";
        }
        if ($duplicateRoots > 0) {
            $issues[] = "raízes duplicadas: {$duplicateRoots}";
        }
        if ($duplicateCnpjs > 0) {
            $issues[] = "CNPJs duplicados: {$duplicateCnpjs}";
        }
        if ($multipleMatrices > 0) {
            $issues[] = "múltiplas matrizes: {$multipleMatrices}";
        }

        if ($issues !== []) {
            throw new RuntimeException(
                'Migração de cadastro ampliado interrompida por dados inconsistentes ('.implode('; ', $issues).'). '
                .'Execute `php artisan clients:preflight-registration-expand` e corrija antes de prosseguir.'
            );
        }
    }

    private function createPartialUniqueIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $trueLiteral = $driver === 'sqlite' ? '1' : 'true';

        if (! $this->indexExists('establishments', 'establishments_one_matrix_per_client')) {
            DB::statement(
                "CREATE UNIQUE INDEX establishments_one_matrix_per_client
                 ON establishments (client_id)
                 WHERE is_matrix = {$trueLiteral} AND deleted_at IS NULL"
            );
        }

        if (Schema::hasTable('client_contacts')
            && ! $this->indexExists('client_contacts', 'client_contacts_one_primary_active_per_client')) {
            DB::statement(
                "CREATE UNIQUE INDEX client_contacts_one_primary_active_per_client
                 ON client_contacts (client_id)
                 WHERE is_primary = {$trueLiteral} AND is_active = {$trueLiteral} AND deleted_at IS NULL"
            );
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        // Preferência: Schema::getIndexes (Laravel 11+)
        if (method_exists(Schema::class, 'getIndexes')) {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName],
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }
};
