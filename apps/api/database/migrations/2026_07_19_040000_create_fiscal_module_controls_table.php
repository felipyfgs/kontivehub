<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_module_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 48);
            $table->string('scope', 12);
            $table->foreignId('office_id')->nullable()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->boolean('restricted')->default(true);
            $table->string('reason', 500);
            $table->foreignId('updated_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('restricted_at')->nullable();
            $table->unsignedBigInteger('blocked_jobs_count')->default(0);
            $table->string('control_key', 96)->unique();
            $table->timestampsTz();

            $table->index(['module_key', 'scope']);
            $table->index(['office_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_module_controls');
    }
};
