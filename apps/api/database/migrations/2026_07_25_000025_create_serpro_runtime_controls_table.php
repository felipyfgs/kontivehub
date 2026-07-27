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
        Schema::create('serpro_runtime_controls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('control_key', 80)->unique();
            $table->string('control_type', 40);
            $table->boolean('active')->default(false);
            $table->string('source', 40)->default('runtime');
            $table->string('reason', 500)->nullable();
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['control_type', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_runtime_controls');
    }
};
