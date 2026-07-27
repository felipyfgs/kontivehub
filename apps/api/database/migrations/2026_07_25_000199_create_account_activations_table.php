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
        Schema::create('account_activations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('purpose', 40);
            $table->string('method', 32);
            $table->bigInteger('user_id');
            $table->bigInteger('tenant_id')->nullable();
            $table->bigInteger('tenant_membership_id')->nullable()->index();
            $table->bigInteger('platform_membership_id')->nullable();
            $table->string('email_normalized');
            $table->string('secret_hash')->index();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->integer('generation')->default(1);
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestampsTz();

            $table->index(['email_normalized', 'method']);
            $table->index(['tenant_id', 'purpose']);
            $table->index(['purpose', 'consumed_at']);
            $table->index(['user_id', 'purpose']);
            $table->foreign(['created_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['platform_membership_id'])->references(['id'])->on('platform_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_activations');
    }
};
