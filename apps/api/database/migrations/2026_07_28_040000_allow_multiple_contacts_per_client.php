<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table): void {
            $table->dropForeign('client_contacts_client_id_foreign');
            $table->dropUnique('client_contacts_client_id_unique');
            $table->unique(
                ['tenant_id', 'id'],
                'client_contacts_tenant_id_id_unique',
            );
            $table->foreign(
                ['tenant_id', 'client_id'],
                'client_contacts_tenant_client_fk',
            )
                ->references(['tenant_id', 'id'])
                ->on('clients')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $hasMultipleContacts = DB::table('client_contacts')
            ->select('client_id')
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleContacts) {
            throw new RuntimeException(
                'Rollback bloqueado: há clientes com múltiplos contatos e a constraint anterior causaria perda de dados.',
            );
        }

        Schema::table('client_contacts', function (Blueprint $table): void {
            $table->dropForeign('client_contacts_tenant_client_fk');
            $table->dropUnique('client_contacts_tenant_id_id_unique');
            $table->unique('client_id', 'client_contacts_client_id_unique');
            $table->foreign('client_id', 'client_contacts_client_id_foreign')
                ->references('id')
                ->on('clients')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }
};
