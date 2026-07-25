<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('manifest_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status', 'finished_at']);
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_backup_runs');
    }
};
