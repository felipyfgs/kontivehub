import type { SerproProvenanceBadge } from '~/types/api'

export interface SerproBadgeMeta {
  code: SerproProvenanceBadge
  label: string
  color: 'success' | 'warning' | 'error' | 'info' | 'neutral' | 'primary'
  icon: string
  description: string
}

const BADGES: Record<SerproProvenanceBadge, SerproBadgeMeta> = {
  simulado: {
    code: 'simulado',
    label: 'Simulado',
    color: 'warning',
    icon: 'i-lucide-flask-conical',
    description: 'Evidência ou resposta simulada — não é produção SERPRO.'
  },
  real: {
    code: 'real',
    label: 'Real',
    color: 'success',
    icon: 'i-lucide-shield-check',
    description: 'Proveniência SERPRO real (não simulada).'
  },
  estimado: {
    code: 'estimado',
    label: 'Estimado',
    color: 'info',
    icon: 'i-lucide-calculator',
    description: 'Custo ou quantidade estimada — ainda não conciliado com fatura oficial.'
  },
  conciliado: {
    code: 'conciliado',
    label: 'Conciliado',
    color: 'success',
    icon: 'i-lucide-scale',
    description: 'Alinhado à fatura/detalhamento oficial importado.'
  },
  possivelmente_bilhetavel: {
    code: 'possivelmente_bilhetavel',
    label: 'Poss. bilhetável',
    color: 'warning',
    icon: 'i-lucide-circle-alert',
    description: 'Timeout ou incerteza — pode ter sido cobrado no SERPRO.'
  },
  nao_bilhetavel: {
    code: 'nao_bilhetavel',
    label: 'Não bilhetável',
    color: 'neutral',
    icon: 'i-lucide-circle-off',
    description: 'Rota/status classificados como isentos de bilhetagem.'
  }
}

/**
 * Deriva badge de proveniência a partir de campos sanitizados da API.
 */
export function resolveProvenanceBadge(input: {
  source_provenance?: string | null
  is_simulated?: boolean | null
  verification_state?: string | null
  consumption_class?: string | null
  reconciliation_status?: string | null
  is_billable_attempt?: boolean | null
  result?: string | null
}): SerproBadgeMeta {
  const recon = String(input.reconciliation_status || '').toUpperCase()
  if (recon === 'MATCHED' || recon === 'RECONCILED' || recon === 'CONCILIADO') {
    return BADGES.conciliado
  }

  if (input.is_simulated === true) {
    return BADGES.simulado
  }

  const prov = String(input.source_provenance || '').toUpperCase()
  if (prov === 'SIMULATED' || prov === 'DEMO' || prov === 'FAKE') {
    return BADGES.simulado
  }
  if (prov === 'SERPRO_REAL' || prov === 'REAL' || prov === 'LIVE') {
    // billing nuance
    if (input.is_billable_attempt === false) {
      return BADGES.nao_bilhetavel
    }
    const result = String(input.result || '').toUpperCase()
    if (result === 'POSSIBLY_BILLABLE' || result === 'UNCERTAIN') {
      return BADGES.possivelmente_bilhetavel
    }
    return BADGES.real
  }

  const cls = String(input.consumption_class || '').toUpperCase()
  if (cls === 'ESTIMATED' || cls === 'ESTIMADO') {
    return BADGES.estimado
  }
  if (cls === 'NON_BILLABLE' || cls === 'FREE' || cls === 'EXEMPT') {
    return BADGES.nao_bilhetavel
  }
  if (cls === 'POSSIBLY_BILLABLE') {
    return BADGES.possivelmente_bilhetavel
  }

  if (input.is_billable_attempt === true && !input.source_provenance) {
    return BADGES.estimado
  }

  return BADGES.estimado
}

export function provenanceBadgeMeta(code: SerproProvenanceBadge | string | null | undefined): SerproBadgeMeta {
  const key = String(code || '').toLowerCase() as SerproProvenanceBadge
  return BADGES[key] || BADGES.estimado
}

export const ALL_PROVENANCE_BADGES = Object.values(BADGES)
