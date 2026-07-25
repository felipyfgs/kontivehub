<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo matriz → filiais: cada cliente continua 1 CNPJ com cadastro próprio;
 * filiais apontam para a matriz via matrix_client_id (mesmo escritório).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'matrix_client_id')) {
                $table->foreignId('matrix_client_id')
                    ->nullable()
                    ->after('root_cnpj')
                    ->constrained('clients')
                    ->nullOnDelete();
                $table->index(['office_id', 'matrix_client_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (Schema::hasColumn('clients', 'matrix_client_id')) {
                $table->dropConstrainedForeignId('matrix_client_id');
            }
        });
    }
};
