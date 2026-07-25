<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil institucional único por Office (CNPJ, razão social, e-mail, telefone).
 * Backfill seguro a partir de OfficeFiscalIdentity ACTIVE e Office.name — sem input de cliente.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_institutional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('cnpj', 14)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('institutional_email')->nullable();
            $table->string('institutional_phone', 40)->nullable();
            $table->timestamps();

            $table->unique('office_id', 'office_institutional_profiles_office_unique');
            $table->index(['office_id', 'cnpj'], 'office_institutional_profiles_office_cnpj');
        });

        $this->backfillFromOfficeAndFiscalIdentity();
    }

    public function down(): void
    {
        Schema::dropIfExists('office_institutional_profiles');
    }

    private function backfillFromOfficeAndFiscalIdentity(): void
    {
        $now = now();
        $offices = DB::table('offices')->select(['id', 'name'])->orderBy('id')->get();

        foreach ($offices as $office) {
            $identity = DB::table('office_fiscal_identities')
                ->where('office_id', $office->id)
                ->where('status', 'ACTIVE')
                ->orderBy('id')
                ->first();

            if ($identity === null) {
                $identity = DB::table('office_fiscal_identities')
                    ->where('office_id', $office->id)
                    ->orderBy('id')
                    ->first();
            }

            $cnpj = $identity !== null && is_string($identity->cnpj) && $identity->cnpj !== ''
                ? strtoupper(preg_replace('/\W+/', '', $identity->cnpj) ?? $identity->cnpj)
                : null;

            $legalName = null;
            if ($identity !== null && is_string($identity->legal_name) && trim($identity->legal_name) !== '') {
                $legalName = trim($identity->legal_name);
            } elseif (is_string($office->name) && trim($office->name) !== '') {
                $legalName = trim($office->name);
            }

            DB::table('office_institutional_profiles')->insert([
                'office_id' => $office->id,
                'cnpj' => $cnpj,
                'legal_name' => $legalName,
                'institutional_email' => null,
                'institutional_phone' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
