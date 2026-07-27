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
        Schema::create('tax_obligation_regime_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('obligation_version_id');
            $table->string('tax_regime', 40);
            $table->string('applicability', 30);
            $table->text('rule_basis')->nullable();
            $table->smallInteger('priority')->default(100);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['obligation_version_id', 'applicability'], 'tax_obligation_regime_rules_obligation_version_id__2447e30496');
            $table->unique(['obligation_version_id', 'tax_regime'], 'tax_obligation_regime_rules_obligation_version_id__4cd3fa4fc6');
            $table->foreign(['obligation_version_id'])->references(['id'])->on('tax_obligation_versions')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_obligation_regime_rules');
    }
};
