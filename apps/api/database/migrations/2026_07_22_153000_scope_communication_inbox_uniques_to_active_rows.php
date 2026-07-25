<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS comm_inboxes_office_name_uq');
            DB::statement('DROP INDEX IF EXISTS comm_inboxes_office_address_uq');
        } else {
            DB::statement('ALTER TABLE communication_inboxes DROP CONSTRAINT IF EXISTS comm_inboxes_office_name_uq');
            DB::statement('ALTER TABLE communication_inboxes DROP CONSTRAINT IF EXISTS comm_inboxes_office_address_uq');
        }

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

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comm_inboxes_office_name_uq');
        DB::statement('DROP INDEX IF EXISTS comm_inboxes_office_address_uq');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX comm_inboxes_office_name_uq ON communication_inboxes (office_id, name)');
            DB::statement('CREATE UNIQUE INDEX comm_inboxes_office_address_uq ON communication_inboxes (office_id, address_hash)');
        } else {
            DB::statement('ALTER TABLE communication_inboxes ADD CONSTRAINT comm_inboxes_office_name_uq UNIQUE (office_id, name)');
            DB::statement('ALTER TABLE communication_inboxes ADD CONSTRAINT comm_inboxes_office_address_uq UNIQUE (office_id, address_hash)');
        }
    }
};
