/**
 * Seletores tipados de cliente / serviço / poder (Integra Contador).
 * Valores sintéticos de catálogo — sem fixture produtiva.
 */

export interface SerproSelectOption<T extends string | number = string> {
  label: string
  value: T
  description?: string
  disabled?: boolean
  meta?: Record<string, unknown>
}

/** Serviços inventariados (idSistema/idServico de alto nível para UI). */
export const SERPRO_SERVICE_OPTIONS: SerproSelectOption[] = [
  {
    label: 'SITFIS',
    value: 'SITFIS',
    description: 'Situação fiscal / relatórios'
  },
  {
    label: 'Caixa postal',
    value: 'CAIXAPOSTAL',
    description: 'Mensagens da RFB'
  },
  {
    label: 'DCTFWeb / MIT',
    value: 'DCTFWEB',
    description: 'Declarações e MIT'
  },
  {
    label: 'Guias',
    value: 'GUIAS',
    description: 'Consulta de guias (emissão bloqueada nesta fase)'
  },
  {
    label: 'Parcelamentos',
    value: 'PARCELAMENTOS',
    description: 'Parcelamentos federais'
  },
  {
    label: 'Procurações',
    value: 'PROCURACOES',
    description: 'Consulta/sincronização de poderes'
  },
  {
    label: 'Simples / MEI',
    value: 'SIMPLES_MEI',
    description: 'Simples Nacional e MEI'
  },
  {
    label: 'Cadastro / processos',
    value: 'CADASTRO',
    description: 'Vínculos e processos'
  }
]

/** Poderes comuns (códigos de catálogo — labels operacionais). */
export const SERPRO_POWER_OPTIONS: SerproSelectOption[] = [
  { label: 'Consultar situação fiscal', value: 'SITFIS_CONSULTAR' },
  { label: 'Caixa postal — ler', value: 'CAIXAPOSTAL_LER' },
  { label: 'Declarações — monitorar', value: 'DECLARACOES_MONITOR' },
  { label: 'Guias — consultar', value: 'GUIAS_CONSULTAR' },
  { label: 'Procuração — obter', value: 'PROCURACAO_OBTER' },
  { label: 'Procuração — listar', value: 'PROCURACAO_LISTAR' }
]

/** Ambientes explícitos. */
export const SERPRO_ENVIRONMENT_OPTIONS: SerproSelectOption[] = [
  {
    label: 'Demonstração SERPRO',
    value: 'TRIAL',
    description: 'Não constitui evidência fiscal ou confirmação de operação real.'
  },
  { label: 'Produção', value: 'PRODUCTION' }
]

export function serviceOption(value?: string | null): SerproSelectOption | null {
  if (!value) return null
  return SERPRO_SERVICE_OPTIONS.find(o => o.value === value) || {
    label: value,
    value
  }
}

export function powerOption(value?: string | null): SerproSelectOption | null {
  if (!value) return null
  return SERPRO_POWER_OPTIONS.find(o => o.value === value) || {
    label: value,
    value
  }
}

/**
 * Valida identidade de autor/contribuinte (CPF 11 dígitos ou CNPJ 14 alfanumérico).
 */
export function isValidSerproIdentity(type: 'CPF' | 'CNPJ' | string, raw: string): boolean {
  const identity = String(raw || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase()
  if (type === 'CPF') {
    return /^\d{11}$/.test(identity)
  }
  // CNPJ alfanumérico oficial: 14 chars [0-9A-Z]
  return /^[0-9A-Z]{14}$/.test(identity)
}

export function normalizeSerproIdentity(raw: string): string {
  return String(raw || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase()
}

/** Monta opções de cliente a partir de lista sanitizada (id + label). */
export function clientSelectOptions(
  clients: Array<{ id: number, name?: string | null, legal_name?: string | null, root_cnpj?: string | null }>
): SerproSelectOption<number>[] {
  return clients.map(c => ({
    value: c.id,
    label: c.name || c.legal_name || `Cliente #${c.id}`,
    description: c.root_cnpj ? `CNPJ raiz ${c.root_cnpj}` : undefined
  }))
}
