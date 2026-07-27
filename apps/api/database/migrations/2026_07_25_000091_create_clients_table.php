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
        Schema::create('clients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('legal_name');
            $table->string('root_cnpj', 8);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->string('display_name')->nullable();
            $table->string('legal_nature_code', 16)->nullable();
            $table->string('legal_nature_name')->nullable();
            $table->string('company_size_code', 16)->nullable();
            $table->string('company_size_name')->nullable();
            $table->text('inactive_reason')->nullable();
            $table->string('registration_source', 16)->default('MANUAL');
            $table->timestampTz('registration_refreshed_at')->nullable();
            $table->string('tax_regime', 64)->nullable();
            $table->decimal('capital_social', 18)->nullable();
            $table->string('responsible_qualification_code', 16)->nullable();
            $table->string('responsible_qualification_name')->nullable();
            $table->bigInteger('work_department_id')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'legal_name']);
            $table->index(['tenant_id', 'root_cnpj']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
