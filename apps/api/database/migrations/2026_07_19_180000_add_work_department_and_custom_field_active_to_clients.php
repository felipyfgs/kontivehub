<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('work_department_id')
                ->nullable()
                ->after('tax_regime')
                ->constrained('work_departments')
                ->nullOnDelete();
        });

        Schema::table('client_custom_fields', function (Blueprint $table): void {
            $table->boolean('is_active')
                ->default(true)
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_department_id');
        });

        Schema::table('client_custom_fields', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
