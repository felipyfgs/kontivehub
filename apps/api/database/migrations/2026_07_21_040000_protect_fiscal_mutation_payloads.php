<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_mutation_operations', function (Blueprint $table): void {
            $table->string('provider_operation_key', 160)->nullable()->after('operation_code');
            $table->text('request_payload_encrypted')->nullable()->after('request_sanitized');
            $table->char('request_payload_digest', 64)->nullable()->after('request_payload_encrypted');
        });

        $now = now();
        foreach ($this->declarationOperationClasses() as [$key, $system, $operation, $class, $label]) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'system_code' => $system,
                'service_code' => $system,
                'operation_code' => $operation,
                'effective_from' => '2026-07-16 00:00:00+00',
            ], [
                'operation_key' => $key,
                'consumption_class' => $class,
                'is_essential' => false,
                'effective_to' => null,
                'label' => $label,
                'notes' => 'Catálogo declarativo oficial Integra Contador v2026-07-16.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('serpro_operation_catalog')
            ->whereIn('operation_key', array_column($this->declarationOperationClasses(), 0))
            ->delete();

        Schema::table('fiscal_mutation_operations', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_operation_key',
                'request_payload_encrypted',
                'request_payload_digest',
            ]);
        });
    }

    /** @return list<array{string, string, string, string, string}> */
    private function declarationOperationClasses(): array
    {
        return [
            ['pgdasd.transdeclaracao', 'PGDASD', 'TRANSDECLARACAO11', 'DECLARACAO', 'Entregar declaração mensal PGDAS-D'],
            ['pgdasd.gerardas', 'PGDASD', 'GERARDAS12', 'EMISSAO', 'Gerar DAS PGDAS-D'],
            ['pgdasd.gerardascobranca', 'PGDASD', 'GERARDASCOBRANCA17', 'EMISSAO', 'Gerar DAS de cobrança'],
            ['pgdasd.gerardasprocesso', 'PGDASD', 'GERARDASPROCESSO18', 'EMISSAO', 'Gerar DAS de processo'],
            ['pgdasd.gerardasavulso', 'PGDASD', 'GERARDASAVULSO19', 'EMISSAO', 'Gerar DAS avulso'],
            ['defis.transdeclaracao', 'DEFIS', 'TRANSDECLARACAO141', 'DECLARACAO', 'Transmitir DEFIS'],
            ['dctfweb.gerarguia', 'DCTFWEB', 'GERARGUIA31', 'EMISSAO', 'Gerar guia DCTFWeb'],
            ['dctfweb.transdeclaracao', 'DCTFWEB', 'TRANSDECLARACAO310', 'DECLARACAO', 'Transmitir DCTFWeb'],
            ['dctfweb.gerarguiaandamento', 'DCTFWEB', 'GERARGUIAANDAMENTO313', 'EMISSAO', 'Gerar guia em andamento DCTFWeb'],
            ['mit.encapuracao', 'MIT', 'ENCAPURACAO314', 'DECLARACAO', 'Encerrar apuração MIT'],
        ];
    }
};
