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
        Schema::create('document_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('user_id');
            $table->string('status', 32)->default('PENDING');
            $table->jsonb('filters');
            $table->boolean('include_events')->default(false);
            $table->string('storage_path')->nullable();
            $table->bigInteger('byte_size')->nullable();
            $table->integer('files_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_exports');
    }
};
