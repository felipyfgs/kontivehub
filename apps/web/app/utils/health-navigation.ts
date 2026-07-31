export const HEALTH_TYPE_PATH_VALUES = [
  'cursor_blocked',
  'cursor_error',
  'sync_failed_recent',
  'credential_expired',
  'credential_expiring_7d',
  'credential_expiring_30d',
  'backup_stale',
  'backup_never',
  'outbound_gap_exhausted',
  'outbound_562_no_key',
  'outbound_656',
  'outbound_retrieval_expired',
  'outbound_xml_divergent',
  'outbound_authorized_unexpected',
  'outbound_cancel_failed',
  'svrs_nfce_certificate',
  'svrs_nfce_auth',
  'svrs_nfce_rate_limit',
  'svrs_nfce_multiple_queries',
  'svrs_nfce_budget',
  'svrs_nfce_contract_changed',
  'svrs_nfce_xml_signature',
  'svrs_nfce_divergent',
  'svrs_nfce_breaker',
  'svrs_nfce_exhausted',
  'cte_certificate_missing',
  'cte_593',
  'cte_656',
  'cte_decode_failures',
  'cte_heartbeat_stale',
  'cte_external_consumer',
  'cte_unexpected_own_issuer',
  'cte_redaction',
  'cte_conflict',
  'cte_pending_import',
  'sitfis_run_completed',
  'sitfis_run_failed',
  'quarantine_unmatched_issuer',
  'quarantine_autxml_tag',
  'quarantine_orphan_event',
  'quarantine_bytes_diverge',
  'quarantine_schema',
  'quarantine_other',
  'serpro_termo_missing',
  'serpro_termo_expired',
  'serpro_token_expiring',
  'serpro_auth_action_required',
  'serpro_auth_blocked',
  'proxy_power_expired',
  'proxy_power_missing',
  'source_unavailable',
  'query_blocked',
  'usage_franchise_exceeded',
  'usage_high'
] as const

const healthTypeValues = new Set<string>(HEALTH_TYPE_PATH_VALUES)

export function normalizeHealthTypePathParam(value: unknown): string | null {
  const scalar = Array.isArray(value) ? value[0] : value
  const normalized = typeof scalar === 'string' ? scalar.trim().toLowerCase() : ''
  return healthTypeValues.has(normalized) ? normalized : null
}

export function healthTypePath(value: unknown): string {
  const type = normalizeHealthTypePathParam(value)
  return type ? `/health/type/${type}` : '/health'
}
