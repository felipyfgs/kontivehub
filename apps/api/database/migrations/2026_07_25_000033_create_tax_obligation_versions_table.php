<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_obligation_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('obligation_definition_id');
            $table->integer('version');
            $table->string('rule_key', 80);
            $table->string('default_applicability', 30)->default('UNKNOWN');
            $table->text('rule_basis')->nullable();
            $table->string('source_ref')->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_current')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['obligation_definition_id', 'is_current'], 'tax_obligation_versions_obligation_definition_id_i_1ca29cc130');
            $table->unique(['obligation_definition_id', 'rule_key'], 'tax_obligation_versions_obligation_definition_id_r_a7629623a6');
            $table->unique(['obligation_definition_id', 'version']);
            $table->foreign(['obligation_definition_id'])->references(['id'])->on('tax_obligation_definitions')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_obligation_versions');
    }
};
