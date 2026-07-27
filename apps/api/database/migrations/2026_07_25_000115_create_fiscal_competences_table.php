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
        Schema::create('fiscal_competences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('fiscal_category_id')->nullable();
            $table->string('period_key', 20);
            $table->smallInteger('period_year');
            $table->smallInteger('period_month')->nullable();
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'fiscal_category_id', 'period_key'], 'fiscal_competences_tenant_id_client_id_fiscal_cate_6fd75235de');
            $table->index(['tenant_id', 'period_year', 'period_month']);
            $table->index(['tenant_id', 'situation']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_category_id'])->references(['id'])->on('fiscal_categories')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_competences');
    }
};
