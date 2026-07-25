<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direção fiscal (IN|OUT|UNKNOWN) nas projeções do catálogo.
 * Backfill imediato a partir de fiscal_role onde existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfse_notes', function (Blueprint $table) {
            $table->string('direction', 10)->default('UNKNOWN')->after('fiscal_role');
            $table->index(['office_id', 'direction']);
        });

        Schema::table('nfe_documents', function (Blueprint $table) {
            $table->string('direction', 10)->default('UNKNOWN')->after('fiscal_role');
            $table->index(['office_id', 'direction']);
        });

        // ISSUER → OUT; TAKER/INTERMEDIARY → IN
        DB::table('nfse_notes')->where('fiscal_role', 'ISSUER')->update(['direction' => 'OUT']);
        DB::table('nfse_notes')->whereIn('fiscal_role', ['TAKER', 'INTERMEDIARY'])->update(['direction' => 'IN']);

        // DistDFe típico: interesse de entrada (não emitente)
        DB::table('nfe_documents')->where('fiscal_role', 'ISSUER')->update(['direction' => 'OUT']);
        DB::table('nfe_documents')->whereIn('fiscal_role', ['TAKER', 'INTERMEDIARY'])->update(['direction' => 'IN']);
        // Sem papel mas já capturado via DistDFe: default IN
        DB::table('nfe_documents')
            ->where(function ($q): void {
                $q->whereNull('fiscal_role')->orWhere('fiscal_role', '');
            })
            ->update(['direction' => 'IN']);
    }

    public function down(): void
    {
        Schema::table('nfse_notes', function (Blueprint $table) {
            $table->dropIndex(['office_id', 'direction']);
            $table->dropColumn('direction');
        });

        Schema::table('nfe_documents', function (Blueprint $table) {
            $table->dropIndex(['office_id', 'direction']);
            $table->dropColumn('direction');
        });
    }
};
