<?php

use App\Enums\CredentialStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('client_credentials exige PostgreSQL.');
        }

        Schema::create('client_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('status', 32);
            $table->string('subject_name');
            $table->string('holder_cnpj', 14);
            $table->string('fingerprint_sha256', 64);
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');
            $table->string('vault_object_id', 26);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->boolean('expires_alert_30')->default(false);
            $table->boolean('expires_alert_7')->default(false);
            $table->boolean('expires_alert_1')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'valid_to']);
            $table->foreign(['tenant_id', 'client_id'])
                ->references(['tenant_id', 'id'])
                ->on('clients')
                ->onUpdate('no action')
                ->onDelete('cascade');
            $table->foreign(['tenant_id'])
                ->references(['id'])
                ->on('tenants')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });

        DB::statement(sprintf(
            <<<'SQL'
                CREATE UNIQUE INDEX client_credentials_one_active_per_client
                ON client_credentials (tenant_id, client_id)
                WHERE status = '%s'
                SQL,
            CredentialStatus::Active->value,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credentials');
    }
};
