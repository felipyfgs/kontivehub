<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('field_key', 26)->unique();
            $table->string('label', 100);
            $table->string('type', 16);
            $table->text('value_text')->nullable();
            $table->char('vault_object_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['office_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_custom_fields');
    }
};
