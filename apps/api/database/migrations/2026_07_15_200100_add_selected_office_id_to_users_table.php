<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de tenant do usuário (troca explícita).
 * Fonte durável quando a requisição API não carrega sessão SPA (token/testes);
 * a sessão continua sendo espelhada quando EnsureFrontendRequestsAreStateful aplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('selected_office_id')
                ->nullable()
                ->after('is_active')
                ->constrained('offices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_office_id');
        });
    }
};
