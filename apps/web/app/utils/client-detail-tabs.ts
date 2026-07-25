/**
 * Taxonomia do detalhe do cliente — layout master (main + sidebar).
 * Dados cadastrais · Dados adicionais · Contatos · Departamentos · Observações · Contratos.
 * Paths legados de operação fiscal redirecionam para /monitoring/clients/:id
 * via {@link legacyFiscalSegmentToHref}; certificado/config → dados-adicionais.
 */
import {
  isLegacyFiscalSegment,
  legacyFiscalSegmentToHref
} from '~/utils/client-cross-links'

export type ClientDetailTab
  = 'cadastro'
    | 'dados-adicionais'
    | 'contato'
    | 'departamento'
    | 'observacoes'
    | 'contratos'

/** Painéis legados ainda aceitos em helpers/query (redirecionam). */
export type ClientDetailPanel
  = 'dados'
    | 'contatos'
    | 'ccmei'
    | 'pagamentos'
    | 'certificado'
    | 'sincronizacao'
    | 'saidas'
    | 'sicalc'
    | 'comprovantes'
    | 'renuncias'
    | 'estabelecimentos'
    | 'fiscal'
    | 'integracoes'

export type ClientDetailSegment = ClientDetailTab | ClientDetailPanel

export type ClientDetailTabDef = {
  value: ClientDetailTab
  label: string
  icon: string
  /** Badge opcional na toolbar (ex.: Em breve). */
  badge?: string
  /** Tab desabilitada (placeholder). */
  disabled?: boolean
  panels: Array<{ value: ClientDetailPanel, label: string, icon: string, description?: string }>
}

/** Segmento de path → destino canônico. */
const SEGMENT_MAP: Record<string, { tab: ClientDetailTab, path: string }> = {
  'resumo': { tab: 'cadastro', path: 'cadastro' },
  'cadastro': { tab: 'cadastro', path: 'cadastro' },
  'dados': { tab: 'cadastro', path: 'cadastro' },
  'socios': { tab: 'cadastro', path: 'cadastro' },
  'dados-adicionais': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  'contato': { tab: 'contato', path: 'contato' },
  'contatos': { tab: 'contato', path: 'contato' },
  'departamento': { tab: 'departamento', path: 'departamento' },
  'observacoes': { tab: 'observacoes', path: 'observacoes' },
  'contratos': { tab: 'contratos', path: 'contratos' },
  // Legado Configuração → Dados adicionais
  'configuracao': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  'estabelecimentos': { tab: 'cadastro', path: 'cadastro' },
  'integracoes': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  'certificado': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  'sincronizacao': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  'saidas': { tab: 'dados-adicionais', path: 'dados-adicionais' },
  // Legado fiscal → monitoring (toolbar só até o redirect da página stub)
  'fiscal': { tab: 'cadastro', path: 'cadastro' },
  'ccmei': { tab: 'cadastro', path: 'cadastro' },
  'sicalc': { tab: 'cadastro', path: 'cadastro' },
  'pagamentos': { tab: 'cadastro', path: 'cadastro' },
  'comprovantes': { tab: 'cadastro', path: 'cadastro' },
  'renuncias': { tab: 'cadastro', path: 'cadastro' }
}

const LEGACY_SECTION_MAP: Record<string, string> = {
  resumo: 'cadastro',
  cadastro: 'cadastro',
  estabelecimentos: 'cadastro',
  certificado: 'dados-adicionais',
  sincronizacao: 'dados-adicionais',
  contatos: 'contato',
  configuracao: 'dados-adicionais'
}

export const CLIENT_DETAIL_TABS: ClientDetailTabDef[] = [
  {
    value: 'cadastro',
    label: 'Dados cadastrais',
    icon: 'i-lucide-clipboard-list',
    panels: []
  },
  {
    value: 'dados-adicionais',
    label: 'Dados adicionais',
    icon: 'i-lucide-list',
    panels: []
  },
  {
    value: 'contato',
    label: 'Contatos',
    icon: 'i-lucide-contact',
    panels: []
  },
  {
    value: 'departamento',
    label: 'Departamentos',
    icon: 'i-lucide-network',
    panels: []
  },
  {
    value: 'observacoes',
    label: 'Observações',
    icon: 'i-lucide-sticky-note',
    panels: []
  },
  {
    value: 'contratos',
    label: 'Contratos',
    icon: 'i-lucide-file-text',
    badge: 'Em breve',
    panels: []
  }
]

function tabDef(tab: ClientDetailTab): ClientDetailTabDef {
  return CLIENT_DETAIL_TABS.find(t => t.value === tab) || CLIENT_DETAIL_TABS[0]!
}

function panelPath(panel: ClientDetailPanel): string | null {
  if (panel === 'dados') return 'cadastro'
  if (panel === 'contatos') return 'contato'
  return SEGMENT_MAP[panel]?.path ?? null
}

/** Path canônico `/clients/:id/:segment`. */
export function clientDetailHref(
  clientId: string | number,
  tab: ClientDetailTab = 'cadastro',
  panel?: ClientDetailPanel
): string {
  if (panel) {
    const segment = panelPath(panel)
    if (segment) return `/clients/${clientId}/${segment}`
  }
  return `/clients/${clientId}/${tab}`
}

export function legacyClientPathToHref(clientId: string | number, segment?: string | null): string | null {
  if (!segment || segment === 'index') {
    return clientDetailHref(clientId, 'cadastro')
  }
  const fiscalHref = legacyFiscalSegmentToHref(clientId, segment)
  if (fiscalHref) return fiscalHref
  const mapped = SEGMENT_MAP[segment]
  if (!mapped) return null
  return `/clients/${clientId}/${mapped.path}`
}

export function legacySectionToHref(clientId: string | number, section: string): string | null {
  const segment = LEGACY_SECTION_MAP[section]
  if (!segment) return null
  return legacyClientPathToHref(clientId, segment)
}

export function parseClientDetailQuery(query: Record<string, unknown>): {
  tab: ClientDetailTab
  panel?: ClientDetailPanel
} {
  const rawTab = typeof query.tab === 'string' ? query.tab : ''
  const rawPanel = typeof query.panel === 'string' ? query.panel : undefined

  if (rawPanel === 'contatos' || rawTab === 'contato') {
    return { tab: 'contato', panel: 'contatos' }
  }
  if (rawTab === 'estabelecimentos' || rawPanel === 'estabelecimentos') {
    return { tab: 'cadastro' }
  }
  if (
    rawTab === 'integracoes'
    || rawTab === 'configuracao'
    || rawPanel === 'certificado'
    || rawPanel === 'sincronizacao'
  ) {
    return { tab: 'dados-adicionais' }
  }
  if (isLegacyFiscalSegment(rawTab) || isLegacyFiscalSegment(rawPanel)) {
    return { tab: 'cadastro' }
  }

  const tab = (CLIENT_DETAIL_TABS.some(t => t.value === rawTab)
    ? rawTab
    : 'cadastro') as ClientDetailTab
  if (tab === 'cadastro') {
    return { tab, panel: rawPanel === 'contatos' ? 'contatos' : 'dados' }
  }
  return { tab }
}

export function queryToClientDetailHref(
  clientId: string | number,
  query: Record<string, unknown>
): string {
  const rawTab = typeof query.tab === 'string' ? query.tab : ''
  const rawPanel = typeof query.panel === 'string' ? query.panel : ''
  const fiscalFromQuery = legacyFiscalSegmentToHref(clientId, rawPanel)
    || legacyFiscalSegmentToHref(clientId, rawTab)
  if (fiscalFromQuery) return fiscalFromQuery

  const { tab, panel } = parseClientDetailQuery(query)
  return clientDetailHref(clientId, tab, panel)
}

export function clientToolbarTabForPath(path: string): ClientDetailTab {
  const match = path.replace(/\/+$/, '').match(/^\/clients\/\d+(?:\/([^/?#]+))?/)
  const segment = match?.[1] || 'cadastro'
  return SEGMENT_MAP[segment]?.tab || 'cadastro'
}

export function clientPageCrumbs(
  clientId: string | number,
  tab: ClientDetailTab
): Array<{ label: string, to?: string }> {
  return [
    { label: 'Cliente', to: clientDetailHref(clientId, 'cadastro') },
    { label: tabDef(tab).label }
  ]
}

/** @deprecated hubs removidos — retorna [] */
export function clientHubCards(_tab: 'fiscal' | 'integracoes') {
  return []
}

export type ClientMeiSignal = {
  tax_regime?: string | null
  establishments?: Array<{ mei_optant?: boolean | null }> | null
}

export function clientIsMei(client: ClientMeiSignal | null | undefined): boolean {
  if (!client) return false
  if (client.tax_regime === 'MEI') return true
  return (client.establishments || []).some(est => est.mei_optant === true)
}

export function clientHubCardsForClient(
  _tab: 'fiscal' | 'integracoes',
  _client: ClientMeiSignal | null | undefined
) {
  return []
}

export function clientLeafCrumbs(
  clientId: string | number,
  _leaf: string
): Array<{ label: string, to?: string }> {
  return clientPageCrumbs(clientId, 'dados-adicionais')
}

export function clientHubCrumbs(
  clientId: string | number,
  _hub: 'fiscal' | 'integracoes'
): Array<{ label: string, to?: string }> {
  return clientPageCrumbs(clientId, 'dados-adicionais')
}

export function clientHubTabForPath(_path: string): 'fiscal' | 'integracoes' | null {
  return null
}

export function clientHubLeafForPath(_path: string): null {
  return null
}

export function primaryTabItems() {
  return CLIENT_DETAIL_TABS.map(t => ({
    label: t.label,
    value: t.value,
    icon: t.icon,
    badge: t.badge,
    disabled: t.disabled
  }))
}

export function panelTabItems(_tab: ClientDetailTab) {
  return []
}

export type ClientModalTab = 'cadastro' | 'contato' | 'dados-adicionais'

export function clientModalTabItems() {
  return [
    { label: 'Cadastro', value: 'cadastro' as const, icon: 'i-lucide-clipboard-list' },
    { label: 'Contato', value: 'contato' as const, icon: 'i-lucide-contact' },
    { label: 'Dados adicionais', value: 'dados-adicionais' as const, icon: 'i-lucide-list' }
  ]
}

export function clientModalPanelItems(tab: ClientModalTab) {
  if (tab === 'cadastro') {
    return [
      { label: 'Dados', value: 'dados' as const, icon: 'i-lucide-building-2' }
    ]
  }
  if (tab === 'contato') {
    return [
      { label: 'Contatos', value: 'contatos' as const, icon: 'i-lucide-contact' }
    ]
  }
  return []
}
