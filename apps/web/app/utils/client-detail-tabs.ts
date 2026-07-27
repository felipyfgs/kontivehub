/**
 * Taxonomia canônica do detalhe do cliente.
 * Dados cadastrais · Dados adicionais · Contatos · Departamentos · Observações · Contratos.
 */
export type ClientDetailTab
  = 'cadastro'
    | 'dados-adicionais'
    | 'contato'
    | 'departamento'
    | 'observacoes'
    | 'contratos'

export type ClientDetailTabDef = {
  value: ClientDetailTab
  label: string
  icon: string
  badge?: string
  disabled?: boolean
}

export const CLIENT_DETAIL_TABS: ClientDetailTabDef[] = [
  {
    value: 'cadastro',
    label: 'Dados cadastrais',
    icon: 'i-lucide-clipboard-list'
  },
  {
    value: 'dados-adicionais',
    label: 'Dados adicionais',
    icon: 'i-lucide-list'
  },
  {
    value: 'contato',
    label: 'Contatos',
    icon: 'i-lucide-contact'
  },
  {
    value: 'departamento',
    label: 'Departamentos',
    icon: 'i-lucide-network'
  },
  {
    value: 'observacoes',
    label: 'Observações',
    icon: 'i-lucide-sticky-note'
  },
  {
    value: 'contratos',
    label: 'Contratos',
    icon: 'i-lucide-file-text',
    badge: 'Em breve'
  }
]

function tabDef(tab: ClientDetailTab): ClientDetailTabDef {
  return CLIENT_DETAIL_TABS.find(item => item.value === tab) || CLIENT_DETAIL_TABS[0]!
}

export function clientDetailHref(
  clientId: string | number,
  tab: ClientDetailTab = 'cadastro'
): string {
  return `/clients/${clientId}/${tab}`
}

export function clientToolbarTabForPath(path: string): ClientDetailTab | null {
  const match = path.replace(/\/+$/, '').match(/^\/clients\/\d+\/([^/?#]+)$/)
  const segment = match?.[1]
  return CLIENT_DETAIL_TABS.some(item => item.value === segment)
    ? segment as ClientDetailTab
    : null
}

export function clientPageCrumbs(
  clientId: string | number,
  tab: ClientDetailTab
): Array<{ label: string, to?: string }> {
  return [
    { label: 'Cliente', to: clientDetailHref(clientId) },
    { label: tabDef(tab).label }
  ]
}

export type ClientMeiSignal = {
  tax_regime?: string | null
  establishments?: Array<{ mei_optant?: boolean | null }> | null
}

export function clientIsMei(client: ClientMeiSignal | null | undefined): boolean {
  if (!client) return false
  if (client.tax_regime === 'MEI') return true
  return (client.establishments || []).some(establishment => establishment.mei_optant === true)
}

export function primaryTabItems() {
  return CLIENT_DETAIL_TABS.map(tab => ({
    label: tab.label,
    value: tab.value,
    icon: tab.icon,
    badge: tab.badge,
    disabled: tab.disabled
  }))
}

export type ClientModalTab = 'cadastro' | 'contato' | 'dados-adicionais'

export function clientModalTabItems() {
  return [
    { label: 'Cadastro', value: 'cadastro' as const, icon: 'i-lucide-clipboard-list' },
    { label: 'Contato', value: 'contato' as const, icon: 'i-lucide-contact' },
    { label: 'Dados adicionais', value: 'dados-adicionais' as const, icon: 'i-lucide-list' }
  ]
}
