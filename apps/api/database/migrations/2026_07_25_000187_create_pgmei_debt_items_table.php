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
        Schema::create('pgmei_debt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('observation_id');
            $table->smallInteger('position')->default(0);
            $table->string('logical_key', 64);
            $table->string('periodo_apuracao', 6);
            $table->string('tributo', 120);
            $table->bigInteger('amount_cents');
            $table->string('ente_federado', 120);
            $table->string('situacao_debito');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['observation_id', 'logical_key']);
            $table->index(['tenant_id', 'client_id', 'observation_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['observation_id'])->references(['id'])->on('pgmei_debt_observations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgmei_debt_items');
    }
};
