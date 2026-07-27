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
        Schema::create('serpro_external_gates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 80)->unique();
            $table->string('status', 32)->default('OPEN')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('ticket_ref', 120)->nullable();
            $table->string('evidence_ref', 200)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('answer_summary', 1000)->nullable();
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->string('responsible_name', 200)->nullable();
            $table->date('reference_date')->nullable();
            $table->string('environment', 20)->default('PRODUCTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_external_gates');
    }
};
