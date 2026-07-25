<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presets nomeados de filtros de lista (personal | office) por superfície e tenant.
 *
 * @see openspec/changes/filtros-salvos-monitoring
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_list_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('surface', 128);
            $table->string('name', 120);
            $table->string('visibility', 16); // personal | office
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('payload');
            $table->timestamps();

            $table->index(['office_id', 'surface', 'user_id'], 'saved_list_filters_office_surface_user_idx');
            $table->index(['office_id', 'surface', 'visibility'], 'saved_list_filters_office_surface_visibility_idx');
        });

        // Unicidade: personal (office, user, surface, name); office (office, surface, name).
        // SQLite de teste e Postgres aceitam unique parcial filtrado.
        DB::statement(
            'CREATE UNIQUE INDEX saved_list_filters_personal_name_unique
             ON saved_list_filters (office_id, user_id, surface, name)
             WHERE visibility = \'personal\'',
        );
        DB::statement(
            'CREATE UNIQUE INDEX saved_list_filters_office_name_unique
             ON saved_list_filters (office_id, surface, name)
             WHERE visibility = \'office\'',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS saved_list_filters_personal_name_unique');
        DB::statement('DROP INDEX IF EXISTS saved_list_filters_office_name_unique');
        Schema::dropIfExists('saved_list_filters');
    }
};
