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
        Schema::create('tax_deadline_calendar_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 60);
            $table->integer('version');
            $table->string('label', 160);
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('source_ref')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['code', 'is_current']);
            $table->unique(['code', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_deadline_calendar_versions');
    }
};
