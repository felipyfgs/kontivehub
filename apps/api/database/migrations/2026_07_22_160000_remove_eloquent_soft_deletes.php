<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $softDeleteTables = [
        'client_contacts',
        'client_custom_fields',
        'establishments',
        'clients',
        'communication_inboxes',
    ];

    public function up(): void
    {
        foreach ($this->softDeleteTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                DB::table($table)->whereNotNull('deleted_at')->delete();
            }
        }

        $this->dropPartialIndexesDependingOnDeletedAt();
        $this->makeOutboxInboxNullableOnDelete();

        foreach ($this->softDeleteTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropSoftDeletes();
                });
            }
        }

        $this->recreateBusinessUniquesWithoutSoftDelete();
    }

    public function down(): void
    {
        foreach ($this->softDeleteTables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if ($table === 'communication_inboxes') {
                    $blueprint->softDeletesTz();
                } else {
                    $blueprint->softDeletes();
                }
            });
        }

        foreach ([
            'clients_office_root_canonical_unique',
            'establishments_one_matrix_per_client',
            'client_contacts_one_primary_active_per_client',
            'comm_inboxes_office_name_uq',
            'comm_inboxes_office_address_uq',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clients_office_root_canonical_unique
            ON clients (office_id, root_cnpj)
            WHERE matrix_client_id IS NULL AND deleted_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX establishments_one_matrix_per_client
            ON establishments (client_id)
            WHERE is_matrix = true AND deleted_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX client_contacts_one_primary_active_per_client
            ON client_contacts (client_id)
            WHERE is_primary = true AND is_active = true AND deleted_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX comm_inboxes_office_name_uq
            ON communication_inboxes (office_id, name)
            WHERE deleted_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX comm_inboxes_office_address_uq
            ON communication_inboxes (office_id, address_hash)
            WHERE deleted_at IS NULL
            SQL);
    }

    private function dropPartialIndexesDependingOnDeletedAt(): void
    {
        foreach ([
            'clients_office_root_canonical_unique',
            'establishments_one_matrix_per_client',
            'client_contacts_one_primary_active_per_client',
            'comm_inboxes_office_name_uq',
            'comm_inboxes_office_address_uq',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }

    private function recreateBusinessUniquesWithoutSoftDelete(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clients_office_root_canonical_unique
            ON clients (office_id, root_cnpj)
            WHERE matrix_client_id IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX establishments_one_matrix_per_client
            ON establishments (client_id)
            WHERE is_matrix = true
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX client_contacts_one_primary_active_per_client
            ON client_contacts (client_id)
            WHERE is_primary = true AND is_active = true
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS comm_inboxes_office_name_uq
            ON communication_inboxes (office_id, name)
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS comm_inboxes_office_address_uq
            ON communication_inboxes (office_id, address_hash)
            SQL);
    }

    private function makeOutboxInboxNullableOnDelete(): void
    {
        if (! Schema::hasTable('communication_outbox_entries')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE communication_outbox_entries DROP CONSTRAINT IF EXISTS communication_outbox_entries_inbox_id_foreign');
            DB::statement('ALTER TABLE communication_outbox_entries ALTER COLUMN inbox_id DROP NOT NULL');
            DB::statement(<<<'SQL'
                ALTER TABLE communication_outbox_entries
                ADD CONSTRAINT communication_outbox_entries_inbox_id_foreign
                FOREIGN KEY (inbox_id) REFERENCES communication_inboxes(id) ON DELETE SET NULL
                SQL);

            return;
        }

        // SQLite (RefreshDatabase): 120000 already creates inbox_id nullable + nullOnDelete.
        // Avoid ->change() / dropForeign rebuilds that are brittle without dbal.
        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->dropForeign(['inbox_id']);
        });
        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('inbox_id')->nullable()->change();
            $table->foreign('inbox_id')
                ->references('id')
                ->on('communication_inboxes')
                ->nullOnDelete();
        });
    }
};
