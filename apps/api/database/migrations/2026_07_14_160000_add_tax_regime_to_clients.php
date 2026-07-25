<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Regime tributário da empresa (ex.: Lucro Presumido, Simples Nacional). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'tax_regime')) {
                $table->string('tax_regime', 64)->nullable()->after('company_size_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (Schema::hasColumn('clients', 'tax_regime')) {
                $table->dropColumn('tax_regime');
            }
        });
    }
};
