<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-out explícito de carteira por módulo/submodule (tenant-scoped).
 * Elegibilidade continua em tax_regime; exclusão só remove da lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_monitoring_module_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->string('submodule', 64)->default('');
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'module_key', 'submodule'],
                'ome_office_client_module_sub_unique'
            );
            $table->index(['office_id', 'module_key', 'submodule'], 'ome_office_module_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_monitoring_module_exclusions');
    }
};
