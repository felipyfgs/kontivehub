<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo SERPRO canônico (operação estável) + agregados mensais separados global/tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPrecondition::tableMissing('serpro_operations', 'serpro_canonical');

        Schema::create('serpro_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 120)->unique();
            $table->string('label', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('consumption_class', 30)->nullable();
            $table->json('metadata_sanitized')->nullable();
            $table->timestamps();
        });

        Schema::create('serpro_operation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serpro_operation_id')->constrained('serpro_operations')->restrictOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('id_sistema', 40)->nullable();
            $table->string('id_servico', 80)->nullable();
            $table->string('versao_sistema', 40)->nullable();
            $table->string('functional_route', 40)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->string('source_catalog', 40)->nullable(); // service_catalog|operation_catalog
            $table->unsignedBigInteger('source_row_id')->nullable();
            $table->timestamps();

            // Mesmas coordenadas oficiais podem aparecer nos dois catálogos legados.
            $table->unique(
                ['source_catalog', 'source_row_id'],
                'serpro_op_version_source_unique',
            );
            $table->index(
                ['serpro_operation_id', 'system_code', 'service_code', 'operation_code'],
                'serpro_op_version_coords_idx',
            );
            $table->index(['system_code', 'service_code', 'operation_code']);
        });

        // Agregados mensais separados (plano de controle vs tenant)
        if (! Schema::hasTable('serpro_usage_monthly_global_aggregates')) {
            Schema::create('serpro_usage_monthly_global_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->string('period_ym', 7); // YYYY-MM
                $table->string('consumption_class', 30);
                $table->unsignedBigInteger('quantity')->default(0);
                $table->unsignedBigInteger('estimated_cost_micros')->default(0);
                $table->timestamps();
                $table->unique(['period_ym', 'consumption_class'], 'serpro_global_monthly_unique');
            });
        }

        if (! Schema::hasTable('serpro_usage_monthly_office_aggregates')) {
            Schema::create('serpro_usage_monthly_office_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
                $table->string('period_ym', 7);
                $table->string('consumption_class', 30);
                $table->unsignedBigInteger('quantity')->default(0);
                $table->unsignedBigInteger('estimated_cost_micros')->default(0);
                $table->timestamps();
                $table->unique(
                    ['office_id', 'period_ym', 'consumption_class'],
                    'serpro_office_monthly_unique',
                );
            });
        }

        // Seed canônico a partir dos dois catálogos existentes (idempotente por operation_key)
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            $rows = DB::table('serpro_service_catalog_entries')->orderBy('id')->get();
            foreach ($rows as $row) {
                $key = $row->operation_key
                    ?? sprintf('%s.%s.%s', $row->solution_code ?? '', $row->service_code ?? '', $row->operation_code ?? '');
                if ($key === '' || $key === '..') {
                    continue;
                }
                $opId = DB::table('serpro_operations')->where('operation_key', $key)->value('id');
                if ($opId === null) {
                    $opId = DB::table('serpro_operations')->insertGetId([
                        'operation_key' => $key,
                        'label' => $row->label ?? $key,
                        'is_enabled' => (bool) ($row->is_enabled ?? true),
                        'consumption_class' => $row->billable_class ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $exists = DB::table('serpro_operation_versions')
                    ->where('source_catalog', 'service_catalog')
                    ->where('source_row_id', $row->id)
                    ->exists();
                if (! $exists) {
                    DB::table('serpro_operation_versions')->insert([
                        'serpro_operation_id' => $opId,
                        'system_code' => (string) ($row->solution_code ?? $row->id_sistema ?? ''),
                        'service_code' => (string) ($row->service_code ?? $row->id_servico ?? ''),
                        'operation_code' => (string) ($row->operation_code ?? ''),
                        'id_sistema' => $row->id_sistema ?? null,
                        'id_servico' => $row->id_servico ?? null,
                        'versao_sistema' => $row->versao_sistema ?? null,
                        'functional_route' => $row->functional_route ?? null,
                        'effective_from' => $row->effective_from ?? null,
                        'effective_to' => $row->effective_to ?? null,
                        'source_catalog' => 'service_catalog',
                        'source_row_id' => $row->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('serpro_operation_catalog')) {
            $rows = DB::table('serpro_operation_catalog')->orderBy('id')->get();
            foreach ($rows as $row) {
                $key = $row->operation_key
                    ?? sprintf('%s.%s.%s', $row->system_code ?? '', $row->service_code ?? '', $row->operation_code ?? '');
                if ($key === '' || $key === '..') {
                    continue;
                }
                $opId = DB::table('serpro_operations')->where('operation_key', $key)->value('id');
                if ($opId === null) {
                    $opId = DB::table('serpro_operations')->insertGetId([
                        'operation_key' => $key,
                        'label' => $row->label ?? $key,
                        'is_enabled' => true,
                        'consumption_class' => $row->consumption_class ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('serpro_operations')
                        ->where('id', $opId)
                        ->whereNull('consumption_class')
                        ->update([
                            'consumption_class' => $row->consumption_class ?? null,
                            'updated_at' => now(),
                        ]);
                }
                $exists = DB::table('serpro_operation_versions')
                    ->where('source_catalog', 'operation_catalog')
                    ->where('source_row_id', $row->id)
                    ->exists();
                if (! $exists) {
                    DB::table('serpro_operation_versions')->insert([
                        'serpro_operation_id' => $opId,
                        'system_code' => (string) ($row->system_code ?? ''),
                        'service_code' => (string) ($row->service_code ?? ''),
                        'operation_code' => (string) ($row->operation_code ?? ''),
                        'effective_from' => $row->effective_from ?? null,
                        'effective_to' => $row->effective_to ?? null,
                        'source_catalog' => 'operation_catalog',
                        'source_row_id' => $row->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_monthly_office_aggregates');
        Schema::dropIfExists('serpro_usage_monthly_global_aggregates');
        Schema::dropIfExists('serpro_operation_versions');
        Schema::dropIfExists('serpro_operations');
    }
};
