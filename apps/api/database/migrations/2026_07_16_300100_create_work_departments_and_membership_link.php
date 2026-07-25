<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20);
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['office_id', 'name']);
            $table->unique(['office_id', 'code']);
            $table->index(['office_id', 'is_active']);
        });

        Schema::table('office_user', function (Blueprint $table): void {
            $table->foreignId('work_department_id')
                ->nullable()
                ->after('is_active')
                ->constrained('work_departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('office_user', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_department_id');
        });
        Schema::dropIfExists('work_departments');
    }
};
