<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece a projeção nfse_notes com campos do leiaute nacional
 * (número, razões sociais, localidades e cStat) para o painel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfse_notes', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->after('access_key');
            $table->string('issuer_name', 255)->nullable()->after('issuer_cnpj');
            $table->string('taker_name', 255)->nullable()->after('taker_cnpj');
            $table->string('intermediary_name', 255)->nullable()->after('intermediary_cnpj');
            $table->string('issue_location', 120)->nullable()->after('service_amount');
            $table->string('service_location', 120)->nullable()->after('issue_location');
            $table->string('official_status_code', 10)->nullable()->after('status');

            $table->index(['office_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('nfse_notes', function (Blueprint $table) {
            $table->dropIndex(['office_id', 'number']);
            $table->dropColumn([
                'number',
                'issuer_name',
                'taker_name',
                'intermediary_name',
                'issue_location',
                'service_location',
                'official_status_code',
            ]);
        });
    }
};
