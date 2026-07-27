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
        Schema::create('outbound_capture_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('establishment_id');
            $table->string('uf', 2)->default('MA');
            $table->string('environment', 40);
            $table->string('model', 5);
            $table->string('mode', 20)->default('ASSISTED');
            $table->string('status', 32)->default('DRAFT');
            $table->boolean('consent_recorded')->default(false);
            $table->timestampTz('consent_recorded_at')->nullable();
            $table->string('mandate_reference')->nullable();
            $table->boolean('allowlisted')->default(false);
            $table->timestampTz('allowlisted_at')->nullable();
            $table->boolean('kill_switch')->default(false);
            $table->string('kill_switch_reason', 500)->nullable();
            $table->timestampTz('kill_switch_at')->nullable();
            $table->string('csc_vault_object_id', 26)->nullable();
            $table->string('csc_id', 20)->nullable();
            $table->boolean('csc_configured')->default(false);
            $table->timestampTz('csc_configured_at')->nullable();
            $table->bigInteger('activated_by')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['establishment_id', 'environment', 'model'], 'outbound_capture_profiles_establishment_id_environ_cdd71f191d');
            $table->foreign(['activated_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_capture_profiles');
    }
};
