<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_communication_dispatches', function (Blueprint $table) {
            $table->foreign(['message_id'], 'ccd_message_fk')->references(['id'])->on('communication_messages')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('communication_messages', function (Blueprint $table) {
            $table->foreign(['client_communication_dispatch_id'], 'cm_dispatch_fk')->references(['id'])->on('client_communication_dispatches')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reply_to_message_id'], 'cm_reply_fk')->references(['id'])->on('communication_messages')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('fiscal_last_update_events', function (Blueprint $table) {
            $table->foreign(['directed_run_id'], 'flue_directed_run_fk')->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('fiscal_monitoring_runs', function (Blueprint $table) {
            $table->foreign(['last_update_event_id'], 'fmr_last_event_fk')->references(['id'])->on('fiscal_last_update_events')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['parent_run_id'], 'fmr_parent_fk')->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('pgdasd_artifacts', function (Blueprint $table) {
            $table->foreign(['operation_id'], 'pga_operation_fk')->references(['id'])->on('pgdasd_operations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['projection_id'], 'pga_projection_fk')->references(['id'])->on('tax_obligation_projections')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::table('pgdasd_operations', function (Blueprint $table) {
            $table->foreign(['amount_source_artifact_id'], 'pgo_amount_artifact_fk')->references(['id'])->on('pgdasd_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['projection_id'], 'pgo_projection_fk')->references(['id'])->on('tax_obligation_projections')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::table('pgdasd_rbt12_projections', function (Blueprint $table) {
            $table->foreign(['projection_id'], 'pgr_projection_fk')->references(['id'])->on('tax_obligation_projections')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_artifact_id'], 'pgr_source_artifact_fk')->references(['id'])->on('pgdasd_artifacts')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('tax_delivery_evidences', function (Blueprint $table) {
            $table->foreign(['projection_id'], 'tde_projection_fk')->references(['id'])->on('tax_obligation_projections')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::table('tax_guide_versions', function (Blueprint $table) {
            $table->foreign(['replaces_version_id'], 'tgv_replaces_fk')->references(['id'])->on('tax_guide_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['superseded_by_version_id'], 'tgv_superseded_fk')->references(['id'])->on('tax_guide_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tax_guide_id'], 'tgv_guide_fk')->references(['id'])->on('tax_guides')->onUpdate('no action')->onDelete('restrict');
        });

        Schema::table('tax_guides', function (Blueprint $table) {
            $table->foreign(['current_version_id'], 'tg_current_version_fk')->references(['id'])->on('tax_guide_versions')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('tax_installment_parcels', function (Blueprint $table) {
            $table->foreign(['payment_id'], 'tip_payment_fk')->references(['id'])->on('tax_installment_payments')->onUpdate('no action')->onDelete('set null');
        });

        Schema::table('tax_installment_payments', function (Blueprint $table) {
            $table->foreign(['parcel_id'], 'tipay_parcel_fk')->references(['id'])->on('tax_installment_parcels')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::table('tax_obligation_projections', function (Blueprint $table) {
            $table->foreign(['conclusive_evidence_id'], 'top_conclusive_evidence_fk')->references(['id'])->on('tax_delivery_evidences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['pgdasd_last_declaration_operation_id'], 'top_pgdasd_declaration_fk')->references(['id'])->on('pgdasd_operations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['pgdasd_latest_rbt12_projection_id'], 'top_pgdasd_rbt12_fk')->references(['id'])->on('pgdasd_rbt12_projections')->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tax_obligation_projections', function (Blueprint $table) {
            $table->dropForeign('top_pgdasd_rbt12_fk');
            $table->dropForeign('top_pgdasd_declaration_fk');
            $table->dropForeign('top_conclusive_evidence_fk');
        });

        Schema::table('tax_installment_payments', function (Blueprint $table) {
            $table->dropForeign('tipay_parcel_fk');
        });

        Schema::table('tax_installment_parcels', function (Blueprint $table) {
            $table->dropForeign('tip_payment_fk');
        });

        Schema::table('tax_guides', function (Blueprint $table) {
            $table->dropForeign('tg_current_version_fk');
        });

        Schema::table('tax_guide_versions', function (Blueprint $table) {
            $table->dropForeign('tgv_guide_fk');
            $table->dropForeign('tgv_superseded_fk');
            $table->dropForeign('tgv_replaces_fk');
        });

        Schema::table('tax_delivery_evidences', function (Blueprint $table) {
            $table->dropForeign('tde_projection_fk');
        });

        Schema::table('pgdasd_rbt12_projections', function (Blueprint $table) {
            $table->dropForeign('pgr_source_artifact_fk');
            $table->dropForeign('pgr_projection_fk');
        });

        Schema::table('pgdasd_operations', function (Blueprint $table) {
            $table->dropForeign('pgo_projection_fk');
            $table->dropForeign('pgo_amount_artifact_fk');
        });

        Schema::table('pgdasd_artifacts', function (Blueprint $table) {
            $table->dropForeign('pga_projection_fk');
            $table->dropForeign('pga_operation_fk');
        });

        Schema::table('fiscal_monitoring_runs', function (Blueprint $table) {
            $table->dropForeign('fmr_parent_fk');
            $table->dropForeign('fmr_last_event_fk');
        });

        Schema::table('fiscal_last_update_events', function (Blueprint $table) {
            $table->dropForeign('flue_directed_run_fk');
        });

        Schema::table('communication_messages', function (Blueprint $table) {
            $table->dropForeign('cm_reply_fk');
            $table->dropForeign('cm_dispatch_fk');
        });

        Schema::table('client_communication_dispatches', function (Blueprint $table) {
            $table->dropForeign('ccd_message_fk');
        });
    }
};
