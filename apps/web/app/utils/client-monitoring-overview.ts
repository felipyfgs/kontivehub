/**
 * Overview empresa-first: cards de processos monitorados a partir do catálogo
 * fiscal + snapshots locais (fail-closed — sem inventar situação).
 * Alinhado ao rail canônico (labels Monitoramento; MEI só MEI).
 */
import type { FiscalSnapshot } from '~/types/api'
import type { ClientFiscalSectionKey } from '~/utils/client-fiscal-detail-navigation'
import { isClientFiscalSectionVisible } from '~/utils/client-fiscal-detail-navigation'
import { clientFiscalHref } from '~/utils/client-cross-links'
import { MONITORING_NAV_ITEMS } from '~/utils/monitoring-nav'

/** Seções de processo no overview (sem Dashboard; sem ocultas). */
export const CLIENT_MONITORING_PROCESS_KEYS = [
  'pgdasd',
  'ccmei',
  'dctfweb',
  'fgts',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'registrations',
  'tax_processes'
] as const satisfies readonly ClientFiscalSectionKey[]

export type ClientMonitoringProcessKey = (typeof CLIENT_MONITORING_PROCESS_KEYS)[number]

export type ClientMonitoringOverviewOptions = {
  isMei?: boolean
}

interface ProcessDef {
  key: ClientMonitoringProcessKey
  label: string
  icon: string
}

const SECTION_TO_MODULE = {
  pgdasd: 'simples_mei',
  ccmei: 'mei',
  dctfweb: 'dctfweb',
  fgts: 'fgts',
  installments: 'installments',
  sitfis: 'sitfis',
  mailbox: 'mailbox',
  declarations: 'declarations',
  guides: 'guides',
  registrations: 'registrations',
  tax_processes: 'tax_processes'
} as const

function processDef(key: ClientMonitoringProcessKey): ProcessDef {
  const moduleKey = SECTION_TO_MODULE[key]
  const item = MONITORING_NAV_ITEMS.find(nav => nav.moduleKey === moduleKey)
  return {
    key,
    label: item?.label ?? key,
    icon: item?.icon ?? 'i-lucide-circle'
  }
}

const PROCESS_DEFS: readonly ProcessDef[] = CLIENT_MONITORING_PROCESS_KEYS.map(processDef)

/** service_code (e aliases) → seção do detalhe do cliente. */
const SERVICE_CODE_TO_SECTION: Record<string, ClientMonitoringProcessKey> = {
  'PGDASD': 'pgdasd',
  'DEFIS': 'pgdasd',
  'PGMEI': 'declarations',
  'DASN_SIMEI': 'declarations',
  'CCMEI': 'ccmei',
  'SITFIS': 'sitfis',
  'FGTS': 'fgts',
  'DCTFWEB': 'dctfweb',
  'MIT': 'dctfweb',
  'GUIAS': 'guides',
  'SICALC': 'guides',
  'PARCSN': 'installments',
  'PARCSN-ESP': 'installments',
  'PARCELAMENTO': 'installments',
  'CAIXAPOSTAL': 'mailbox',
  'MAILBOX': 'mailbox',
  'CADASTRO': 'registrations',
  'PROCESSO': 'tax_processes'
}

export interface ClientMonitoringProcessCard {
  key: ClientMonitoringProcessKey
  label: string
  icon: string
  to: string
  /** Situação do snapshot local quando houver; null = sem evidência. */
  situation: string | null
  /** observed_at / created_at do snapshot mais recente mapeado. */
  lastObservedAt: string | null
  hasLocalEvidence: boolean
}

function norm(value?: string | null): string {
  return String(value || '').trim().toUpperCase()
}

export function mapSnapshotToProcessKey(
  snapshot: Pick<FiscalSnapshot, 'service_code' | 'system_code' | 'operation_key'>
): ClientMonitoringProcessKey | null {
  const service = norm(snapshot.service_code)
  if (service && SERVICE_CODE_TO_SECTION[service]) {
    return SERVICE_CODE_TO_SECTION[service]
  }

  const system = norm(snapshot.system_code)
  if (system.includes('SITFIS')) return 'sitfis'
  if (system.includes('PGDAS') || system === 'INTEGRA_SN') return 'pgdasd'
  if (system.includes('MEI') || system.includes('CCMEI')) return 'ccmei'
  if (system.includes('DCTF')) return 'dctfweb'
  if (system.includes('FGTS') || system === 'ESOCIAL') return 'fgts'
  if (system.includes('PARCEL') || system.includes('PARC')) return 'installments'
  if (system.includes('GUIA') || system.includes('PAGAMENTO') || system.includes('SICALC')) {
    return 'guides'
  }
  if (system.includes('CAIXA') || system.includes('MAILBOX') || system.includes('DTE')) {
    return 'mailbox'
  }

  const op = norm(snapshot.operation_key)
  if (op.includes('PGDAS')) return 'pgdasd'
  if (op.includes('SITFIS')) return 'sitfis'
  if (op.includes('DCTF')) return 'dctfweb'

  return null
}

function snapshotTime(snapshot: FiscalSnapshot): number {
  const raw = snapshot.observed_at || snapshot.created_at
  if (!raw) return 0
  const t = Date.parse(raw)
  return Number.isNaN(t) ? 0 : t
}

/**
 * Monta um card por processo do catálogo visível.
 * Status só vem de snapshot local mapeável; caso contrário “sem evidência local”.
 */
export function buildClientMonitoringOverview(
  clientId: string | number,
  snapshots: readonly FiscalSnapshot[] = [],
  options: ClientMonitoringOverviewOptions = {}
): ClientMonitoringProcessCard[] {
  const bestBySection = new Map<ClientMonitoringProcessKey, FiscalSnapshot>()

  for (const snap of snapshots) {
    const key = mapSnapshotToProcessKey(snap)
    if (!key) continue
    if (!isClientFiscalSectionVisible(key, options)) continue
    const prev = bestBySection.get(key)
    if (!prev || snapshotTime(snap) >= snapshotTime(prev)) {
      bestBySection.set(key, snap)
    }
  }

  return PROCESS_DEFS
    .filter(def => isClientFiscalSectionVisible(def.key, options))
    .map((def) => {
      const snap = bestBySection.get(def.key)
      const situation = snap?.situation ? String(snap.situation) : null
      const lastObservedAt = snap?.observed_at || snap?.created_at || null
      return {
        key: def.key,
        label: def.label,
        icon: def.icon,
        to: clientFiscalHref(clientId, def.key),
        situation,
        lastObservedAt: lastObservedAt ? String(lastObservedAt) : null,
        hasLocalEvidence: Boolean(snap)
      }
    })
}
