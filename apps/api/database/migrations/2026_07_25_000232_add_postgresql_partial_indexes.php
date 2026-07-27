<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX client_contacts_one_primary_active_per_client
                ON client_contacts (client_id)
                WHERE is_primary = true AND is_active = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_conversations_one_active
                ON communication_conversations (inbox_id, identity_id)
                WHERE status <> 'RESOLVED'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_flow_bindings_one_enabled_per_inbox
                ON communication_flow_inbox_bindings (inbox_id)
                WHERE enabled = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_flow_runs_one_active_per_conversation
                ON communication_flow_runs (conversation_id)
                WHERE conversation_id IS NOT NULL
                  AND status IN ('pending', 'running', 'waiting_input', 'waiting_delay', 'waiting_outbox', 'paused')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_identity_links_client_unique
                ON communication_identity_links (identity_id, client_id)
                WHERE client_contact_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_identity_links_contact_unique
                ON communication_identity_links (identity_id, client_id, client_contact_id)
                WHERE client_contact_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX communication_inboxes_one_default_per_tenant
                ON communication_inboxes (tenant_id)
                WHERE is_default = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX document_acquisitions_one_canonical_per_document
                ON document_acquisitions (dfe_document_id)
                WHERE is_canonical = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX establishments_one_headquarters_per_client
                ON establishments (client_id)
                WHERE is_headquarters = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX fiscal_snapshots_one_current_per_identity
                ON fiscal_snapshots (
                    tenant_id,
                    client_id,
                    system_code,
                    service_code,
                    COALESCE(competence_id, 0::bigint)
                )
                WHERE is_current = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX monitor_commercial_inaugural_unique
                ON monitor_commercial_ledger_entries (tenant_id, client_id, monitor_key)
                WHERE origin = 'inaugural'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX monitor_commercial_scheduled_period_unique
                ON monitor_commercial_ledger_entries (tenant_id, client_id, monitor_key, period_key)
                WHERE origin = 'scheduled'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX outbound_retrieval_active_svrs_unique
                ON outbound_retrieval_requests (tenant_id, outbound_capture_profile_id, access_key, origin)
                WHERE origin = 'SVRS_PORTAL_BY_KEY'
                  AND access_key IS NOT NULL
                  AND recovery_status IS NOT NULL
                  AND recovery_status NOT IN ('CAPTURED', 'NOT_AVAILABLE_VISIBLE', 'BLOCKED', 'RESOLVED_BY_OTHER_SOURCE')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX outbound_retrieval_slot_attempt_unique
                ON outbound_retrieval_requests (tenant_id, access_key, origin, svrs_transaction_count)
                WHERE origin = 'SVRS_PORTAL_BY_KEY'
                  AND access_key IS NOT NULL
                  AND recovery_status IS NOT NULL
                  AND recovery_status NOT IN ('CAPTURED', 'NOT_AVAILABLE_VISIBLE', 'BLOCKED', 'RESOLVED_BY_OTHER_SOURCE')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX saved_list_filters_tenant_name_unique
                ON saved_list_filters (tenant_id, surface, name)
                WHERE visibility = 'tenant'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX saved_list_filters_personal_name_unique
                ON saved_list_filters (tenant_id, user_id, surface, name)
                WHERE visibility = 'personal'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX serpro_contracts_one_active_per_environment
                ON serpro_contracts (environment)
                WHERE status = 'ACTIVE'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX serpro_credential_versions_one_active_per_environment
                ON serpro_credential_versions (environment)
                WHERE status = 'ACTIVE'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tax_guide_versions_one_current
                ON tax_guide_versions (tax_guide_id)
                WHERE is_current = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tenant_credential_purpose_links_one_active
                ON tenant_credential_purpose_links (tenant_id, purpose)
                WHERE status = 'ACTIVE'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tenant_credentials_one_active_per_tenant
                ON tenant_credentials (tenant_id)
                WHERE status = 'ACTIVE'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX work_processes_template_unique
                ON work_processes (tenant_id, work_process_template_id, client_id, competence)
                WHERE origin = 'TEMPLATE' AND work_process_template_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX work_processes_template_unique');
        DB::statement('DROP INDEX tenant_credentials_one_active_per_tenant');
        DB::statement('DROP INDEX tenant_credential_purpose_links_one_active');
        DB::statement('DROP INDEX tax_guide_versions_one_current');
        DB::statement('DROP INDEX serpro_credential_versions_one_active_per_environment');
        DB::statement('DROP INDEX serpro_contracts_one_active_per_environment');
        DB::statement('DROP INDEX saved_list_filters_personal_name_unique');
        DB::statement('DROP INDEX saved_list_filters_tenant_name_unique');
        DB::statement('DROP INDEX outbound_retrieval_slot_attempt_unique');
        DB::statement('DROP INDEX outbound_retrieval_active_svrs_unique');
        DB::statement('DROP INDEX monitor_commercial_scheduled_period_unique');
        DB::statement('DROP INDEX monitor_commercial_inaugural_unique');
        DB::statement('DROP INDEX fiscal_snapshots_one_current_per_identity');
        DB::statement('DROP INDEX establishments_one_headquarters_per_client');
        DB::statement('DROP INDEX document_acquisitions_one_canonical_per_document');
        DB::statement('DROP INDEX communication_inboxes_one_default_per_tenant');
        DB::statement('DROP INDEX communication_identity_links_contact_unique');
        DB::statement('DROP INDEX communication_identity_links_client_unique');
        DB::statement('DROP INDEX communication_flow_runs_one_active_per_conversation');
        DB::statement('DROP INDEX communication_flow_bindings_one_enabled_per_inbox');
        DB::statement('DROP INDEX communication_conversations_one_active');
        DB::statement('DROP INDEX client_contacts_one_primary_active_per_client');
    }
};
