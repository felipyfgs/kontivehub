<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'capital_social')) {
                $table->decimal('capital_social', 18, 2)->nullable()->after('company_size_name');
            }
            if (! Schema::hasColumn('clients', 'responsible_qualification_code')) {
                $table->string('responsible_qualification_code', 16)->nullable()->after('capital_social');
            }
            if (! Schema::hasColumn('clients', 'responsible_qualification_name')) {
                $table->string('responsible_qualification_name')->nullable()->after('responsible_qualification_code');
            }
        });

        Schema::table('establishments', function (Blueprint $table): void {
            if (! Schema::hasColumn('establishments', 'secondary_cnaes')) {
                $table->json('secondary_cnaes')->nullable()->after('main_cnae_name');
            }
            if (! Schema::hasColumn('establishments', 'state_registrations')) {
                $table->json('state_registrations')->nullable()->after('secondary_cnaes');
            }
            if (! Schema::hasColumn('establishments', 'shareholders')) {
                $table->json('shareholders')->nullable()->after('state_registrations');
            }
            if (! Schema::hasColumn('establishments', 'public_phone_secondary')) {
                $table->string('public_phone_secondary', 32)->nullable()->after('public_phone');
            }
            if (! Schema::hasColumn('establishments', 'public_fax')) {
                $table->string('public_fax', 32)->nullable()->after('public_phone_secondary');
            }
            if (! Schema::hasColumn('establishments', 'special_situation')) {
                $table->string('special_situation')->nullable()->after('registration_status_reason');
            }
            if (! Schema::hasColumn('establishments', 'special_situation_at')) {
                $table->date('special_situation_at')->nullable()->after('special_situation');
            }
            if (! Schema::hasColumn('establishments', 'simples_optant')) {
                $table->boolean('simples_optant')->nullable()->after('public_fax');
            }
            if (! Schema::hasColumn('establishments', 'mei_optant')) {
                $table->boolean('mei_optant')->nullable()->after('simples_optant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            foreach (['capital_social', 'responsible_qualification_code', 'responsible_qualification_name'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('establishments', function (Blueprint $table): void {
            foreach ([
                'secondary_cnaes',
                'state_registrations',
                'shareholders',
                'public_phone_secondary',
                'public_fax',
                'special_situation',
                'special_situation_at',
                'simples_optant',
                'mei_optant',
            ] as $column) {
                if (Schema::hasColumn('establishments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
