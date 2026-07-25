<?php

/**
 * Allowlists e constantes das convenções canônicas de schema.
 *
 * @see docs/ops/schema-conventions.md
 * @see tests/Architecture/SchemaConventionsTest
 */
return [
    /**
     * Models com coluna office_id de isolamento que NÃO usam BelongsToOffice
     * (membership, auditoria, billing/ops de plataforma, office_id nullable).
     * Tenant puro NÃO deve aparecer aqui.
     *
     * @var list<string>
     */
    'belongs_to_office_exceptions' => [
        'AccountActivation',
        'AuditLog',
        'OfficeMembership',
        'OfficeSubscription',
        'PlatformPrivilegedAuditEvent',
        'SerproBillingInvoiceLine',
        'SerproDteCanaryRequest', // dual-access plataforma + tenant; isolamento nos services
        'SerproOfficeQuantityUsageLimit',
        'SerproReadinessRun',
        'SerproRetentionJob',
        'SerproRolloutApproval',
        'SerproUsageBudget',
        'SerproUsageIncident',
        'SerproUsageMonthlyAggregate',
        'SerproUsageReconciliationAdjustment',
        'VaultObjectJournalEntry',
    ],

    /**
     * Models que mencionam office_id em relações/helpers mas não têm coluna
     * de tenancy fiscal própria (não exigem trait nem allowlist de tenant).
     *
     * @var list<string>
     */
    'office_id_not_tenant_column' => [
        'User', // selected_office_id / pivot queries
        'FiscalCategory', // catálogo global
        'PlatformMembership', // default_office_id
        'SerproContract', // catálogo plataforma
        'SerproDteControl', // pilot_office_id
        'SerproPriceVersion', // catálogo
        'TaxObligationDefinition', // catálogo
        'Office', // raiz do tenant
    ],

    /**
     * Colunas *cnpj* com length intencional ≠ 14 (raiz 8 ou identidade alfanumérica 18).
     * Chave: "BasenameDaMigration.php:column_name" => length esperado.
     *
     * @var array<string, int>
     */
    'cnpj_length_allowlist' => [
        // raiz 8
        '2026_07_13_232000_create_clients_and_establishments_tables.php:root_cnpj' => 8,
        '2026_07_15_040000_create_office_autxml_and_import_tables.php:root_cnpj' => 8,
        '2026_07_15_040000_create_office_autxml_and_import_tables.php:interested_root_cnpj' => 8,
        '2026_07_15_060000_add_outbound_deadline_scheduling_schema.php:root_cnpj' => 8,
        // identidade alfanumérica SERPRO (não é máscara)
        '2026_07_16_600000_create_serpro_credential_versions_table.php:contractor_cnpj' => 18,
        '2026_07_16_600200_evolve_serpro_auth_powers_ledger_and_cnpj.php:destination_cnpj' => 18,
    ],

    /**
     * Desvios legados de vault ainda presentes em migrations de create.
     * Remover entrada quando a create for alinhada a 26 e a remediação aditiva tiver sido aplicada.
     * Após wave vault desta change a allowlist deve ficar vazia.
     *
     * @var array<string, int>
     */
    'vault_length_allowlist' => [
        // preenchido dinamicamente vazio após remediação W2
    ],

    /**
     * environment com length > 20 em create legado (SEFAZ etc.) — shrink só após auditoria.
     *
     * @var array<string, int>
     */
    'environment_length_allowlist' => [
        '2026_07_13_234000_create_sync_and_documents_tables.php:environment' => 40,
        '2026_07_14_210000_create_channel_sync_cursors_and_nfe_projections.php:environment' => 40,
        '2026_07_15_030000_create_ma_outbound_capture_tables.php:environment' => 40,
        '2026_07_15_040000_create_office_autxml_and_import_tables.php:environment' => 40,
        // onboarding / snapshots usam 32 (entre 20 e 40)
        '2026_07_16_900104_create_office_serpro_onboarding_states_table.php:environment' => 32,
        '2026_07_16_900402_create_client_procuracao_snapshots_table.php:environment' => 32,
    ],

    'canonical' => [
        'vault_object_id_length' => 26,
        'cnpj_length' => 14,
        'root_cnpj_length' => 8,
        'status_min_length' => 32,
        'environment_length' => 20,
        'competence_monthly_length' => 7,
    ],

    /**
     * Soft delete permitido apenas nestas tabelas.
     *
     * @var list<string>
     */
    'soft_delete_allowlist' => [
        'clients',
        'establishments',
        'client_contacts',
        'client_custom_fields',
    ],
];
