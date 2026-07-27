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
        Schema::create('fiscal_mutation_operation_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('fiscal_mutation_operation_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event', 80);
            $table->string('result', 40)->default('SUCCESS');
            $table->string('correlation_id', 64)->nullable();
            $table->bigInteger('actor_user_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'fiscal_mutation_operation_id', 'created_at'], 'fiscal_mutation_operation_events_tenant_id_fiscal__e5d4a14e60');
            $table->foreign(['actor_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_mutation_operation_id'], 'fiscal_mutation_operation_events_fiscal_mutation_o_8a5fb4e37c')->references(['id'])->on('fiscal_mutation_operations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_mutation_operation_events');
    }
};
