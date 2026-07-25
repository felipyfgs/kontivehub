<?php

use App\Enums\TaxRegimeCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('name_key', 80);
            $table->string('color', 20)->default('neutral');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'name_key'], 'client_categories_office_name_key_uq');
            $table->index(['office_id', 'is_active', 'name'], 'client_categories_office_active_name_idx');
        });

        Schema::create('client_category_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_category_id')->constrained('client_categories')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'client_category_id'],
                'client_category_assignments_office_client_category_uq'
            );
            $table->index(['office_id', 'client_id'], 'client_category_assignments_office_client_idx');
            $table->index(['office_id', 'client_category_id'], 'client_category_assignments_office_category_idx');
        });

        DB::table('clients')
            ->select(['id', 'tax_regime'])
            ->whereNotNull('tax_regime')
            ->orderBy('id')
            ->chunkById(500, function ($clients): void {
                foreach ($clients as $client) {
                    $canonical = TaxRegimeCode::fromInput(
                        is_string($client->tax_regime) ? $client->tax_regime : null
                    );

                    DB::table('clients')->where('id', $client->id)->update([
                        'tax_regime' => $canonical?->value,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_category_assignments');
        Schema::dropIfExists('client_categories');
    }
};
