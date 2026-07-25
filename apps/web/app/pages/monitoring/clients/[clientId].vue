<script setup lang="ts">
/**
 * Detalhe mestre–cliente fiscal (7.11).
 * Arquétipo painel duplo (mailbox/inbox): sidebar interno vertical + conteúdo.
 * Seções LAZY: só a aba ativa é carregada. Falha parcial com retry — nunca lista vazia silenciosa.
 */
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import NavbarMoreActions from '~/components/navigation/NavbarMoreActions.vue'
import type { Client, FiscalFinding, FiscalMonitoringRun, FiscalPendingItem, FiscalSnapshot } from '~/types/api'
import type { OperationalProcess } from '~/types/work'
import type {
  FiscalDocumentDescriptor,
  FiscalRegistrationLink,
  FiscalTaxProcess
} from '~/types/fiscal-modules'
import { clientCrmHref } from '~/utils/client-cross-links'
import { clientIsMei } from '~/utils/client-detail-tabs'
import {
  clientFiscalNavigationMenu,
  clientFiscalSwitchPath,
  isClientFiscalSectionVisible,
  sectionKeyFromFiscalPath,
  type ClientFiscalSectionKey
} from '~/utils/client-fiscal-detail-navigation'
import { buildClientMonitoringOverview } from '~/utils/client-monitoring-overview'
import { setMailboxClientFilterHandoff } from '~/utils/mailbox-handoff'
import { mailboxTriageLabel } from '~/utils/mailbox-triage'
import ShellDataTable from '~/components/shell/DataTable.vue'

const FiscalStatusBadge = resolveComponent('FiscalStatusBadge')
const FiscalDocumentAction = resolveComponent('FiscalDocumentAction')

// Um único path com section opcional — evita warn do Vue Router de alias
// com params diferentes do record original (`:section` vs só `:clientId`).
definePageMeta({
  path: '/monitoring/clients/:clientId/:section?'
})

type SectionKey
  = | 'overview'
    | 'runs'
    | 'findings'
    | 'pending'
    | 'installments'
    | 'declarations'
    | 'pgdasd'
    | 'dctfweb'
    | 'guides'
    | 'fgts'
    | 'sitfis'
    | 'mailbox'
    | 'registrations'
    | 'ccmei'
    | 'renunciations'
    | 'tax_processes'

const SECTION_KEYS: SectionKey[] = [
  'overview',
  'runs',
  'findings',
  'pending',
  'installments',
  'declarations',
  'pgdasd',
  'dctfweb',
  'guides',
  'fgts',
  'sitfis',
  'mailbox',
  'registrations',
  'ccmei',
  'renunciations',
  'tax_processes'
]

function isSectionKey(value: string): value is SectionKey {
  return (SECTION_KEYS as string[]).includes(value)
}

const api = useApi()
const route = useRoute()
const router = useRouter()
const { canTriggerSync, sessionEpoch } = useDashboard()

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')
const navOpen = ref(false)

function openClientNavigation(): void {
  navOpen.value = true
}
/**
 * Rail sempre fino (ícones); no hover/focus expand overlay sem empurrar a tabela.
 * Pequeno delay no leave evita flicker com tooltips.
 */
const navHovered = ref(false)
let navLeaveTimer: ReturnType<typeof setTimeout> | null = null

function onNavEnter() {
  if (navLeaveTimer) {
    clearTimeout(navLeaveTimer)
    navLeaveTimer = null
  }
  navHovered.value = true
}

function onNavLeave() {
  navLeaveTimer = setTimeout(() => {
    navHovered.value = false
    navLeaveTimer = null
  }, 140)
}

function onNavFocusOut(event: FocusEvent) {
  const root = event.currentTarget as HTMLElement | null
  const next = event.relatedTarget as Node | null
  if (root && next && root.contains(next)) return
  onNavLeave()
}

onBeforeUnmount(() => {
  if (navLeaveTimer) clearTimeout(navLeaveTimer)
})

watch(() => route.path, () => {
  navOpen.value = false
  navHovered.value = false
})

const clientId = computed(() => Number(route.params.clientId))
const client = ref<Client | null>(null)
const clientLoading = ref(false)
const clientError = ref<string | null>(null)
const clientIsMeiSignal = computed(() => clientIsMei(client.value))

const tab = computed({
  get: (): SectionKey => {
    const raw = String(route.params.section || 'overview')
    if (!isSectionKey(raw)) return 'overview'
    if (!isClientFiscalSectionVisible(raw as ClientFiscalSectionKey, { isMei: clientIsMeiSignal.value })) {
      return 'overview'
    }
    return raw
  },
  set: (value: SectionKey) => {
    const base = `/monitoring/clients/${clientId.value}`
    void router.replace(value === 'overview' ? base : `${base}/${value}`)
  }
})

const pageTitle = computed(() =>
  client.value?.name || client.value?.legal_name || `Cliente #${clientId.value}`
)

const snapshots = ref<FiscalSnapshot[]>([])
const operationalProcesses = ref<OperationalProcess[]>([])
const overviewProcessCards = computed(() =>
  buildClientMonitoringOverview(clientId.value, snapshots.value, {
    isMei: clientIsMeiSignal.value
  })
)
const runs = ref<FiscalMonitoringRun[]>([])
const findings = ref<FiscalFinding[]>([])
const pending = ref<FiscalPendingItem[]>([])
const installments = ref<Record<string, unknown>[]>([])
const declarations = ref<Record<string, unknown>[]>([])
const dctfwebDeclarations = ref<Record<string, unknown>[]>([])
const guides = ref<Record<string, unknown>[]>([])
const sitfis = ref<Record<string, unknown> | null>(null)
const fgtsCompetences = ref<Record<string, unknown>[]>([])
const mailboxMessages = ref<Record<string, unknown>[]>([])
const registrationLinks = ref<FiscalRegistrationLink[]>([])
const taxProcesses = ref<FiscalTaxProcess[]>([])

/** publicView SITFIS: campos flat + snapshot aninhado (evita painel em branco). */
const sitfisSnapshot = computed(() => {
  const s = sitfis.value?.snapshot
  return s && typeof s === 'object' ? s as Record<string, unknown> : null
})
const sitfisSituation = computed(() => {
  const flat = sitfis.value?.situation
  if (flat != null && String(flat) !== '') return String(flat)
  const nested = sitfisSnapshot.value?.situation
  return nested != null && String(nested) !== '' ? String(nested) : ''
})
const sitfisObserved = computed(() => {
  const flat = sitfis.value?.observed_at ?? sitfis.value?.as_of
  if (flat != null && String(flat) !== '') return String(flat)
  const nested = sitfisSnapshot.value?.observed_at
  return nested != null ? String(nested) : null
})
const sitfisProtocol = computed(() => {
  const flat = sitfis.value?.protocol ?? sitfis.value?.protocol_number
  if (flat != null && String(flat) !== '') return String(flat)
  const norm = sitfisSnapshot.value?.normalized
  if (norm && typeof norm === 'object') {
    const n = norm as Record<string, unknown>
    if (n.protocol != null) return String(n.protocol)
    if (n.protocol_number != null) return String(n.protocol_number)
  }
  return ''
})
const sitfisCoverage = computed(() => {
  const flat = sitfis.value?.coverage
  if (flat != null && String(flat) !== '') return String(flat)
  const nested = sitfisSnapshot.value?.coverage
  return nested != null ? String(nested) : ''
})
const sitfisErrorCode = computed(() => {
  const c = sitfis.value?.error_code
  return c != null && String(c) !== '' ? String(c) : ''
})
const sitfisErrorMessage = computed(() => {
  const m = sitfis.value?.error_message
  return m != null && String(m) !== '' ? String(m) : ''
})
const sitfisDocument = computed(() => {
  const fromFlat = (sitfis.value as { document?: FiscalDocumentDescriptor | null } | null)?.document
  if (fromFlat) return fromFlat
  const snap = sitfisSnapshot.value as { document?: FiscalDocumentDescriptor | null } | null
  return snap?.document ?? null
})

interface SectionState {
  loading: boolean
  error: string | null
  /** epoch+clientId em que a seção foi carregada com sucesso */
  loadedKey: string | null
}

function emptySection(): SectionState {
  return { loading: false, error: null, loadedKey: null }
}

const sections = reactive<Record<SectionKey, SectionState>>({
  overview: emptySection(),
  runs: emptySection(),
  findings: emptySection(),
  pending: emptySection(),
  installments: emptySection(),
  declarations: emptySection(),
  pgdasd: emptySection(),
  dctfweb: emptySection(),
  guides: emptySection(),
  fgts: emptySection(),
  sitfis: emptySection(),
  mailbox: emptySection(),
  registrations: emptySection(),
  ccmei: emptySection(),
  renunciations: emptySection(),
  tax_processes: emptySection()
})

/** Estado independente: uma falha do Work não invalida os snapshots fiscais. */
const operationalWorkState = reactive<SectionState>(emptySection())

function cacheKey(): string {
  return `${clientId.value}@${sessionEpoch.value}`
}

const links = computed(() =>
  clientFiscalNavigationMenu(clientId.value, route.path, {
    isMei: clientIsMeiSignal.value
  })
)

/** Deep-link para seção oculta / MEI sem regime → overview. */
watch(
  () => [route.params.section, client.value?.id, clientIsMeiSignal.value] as const,
  () => {
    const raw = String(route.params.section || 'overview')
    if (!raw || raw === 'overview') return
    const key = sectionKeyFromFiscalPath(route.path)
    if (!isClientFiscalSectionVisible(key, { isMei: clientIsMeiSignal.value })) {
      void router.replace(`/monitoring/clients/${clientId.value}`)
    }
  },
  { immediate: true }
)

function onSwitchClient(payload: { id: number, isMei: boolean }) {
  if (!Number.isFinite(payload.id) || payload.id < 1 || payload.id === clientId.value) return
  const current = sectionKeyFromFiscalPath(route.path)
  void router.push(clientFiscalSwitchPath(payload.id, current, { isMei: payload.isMei }))
}

/** Coluna de documento quando o item expõe descritor com href do servidor. */
function documentColumnFor<T extends { document?: FiscalDocumentDescriptor | null }>() {
  return {
    id: 'document',
    header: 'Documento',
    cell: ({ row }: { row: { original: T } }) =>
      h(FiscalDocumentAction, { document: row.original.document ?? null })
  }
}

/** Colunas das seções-lista: ShellDataTable sempre montada (#empty), padrão ModuleTable/customers. */
const runColumns = [
  {
    id: 'run',
    header: 'Execução',
    cell: ({ row }: { row: { original: FiscalMonitoringRun } }) =>
      `#${row.original.id} · ${row.original.system_code}/${row.original.service_code}`
  },
  {
    id: 'when',
    header: 'Quando',
    cell: ({ row }: { row: { original: FiscalMonitoringRun } }) =>
      formatDateTime(row.original.started_at || row.original.created_at)
  },
  {
    id: 'detail',
    header: 'Detalhe',
    cell: ({ row }: { row: { original: FiscalMonitoringRun } }) =>
      row.original.error_message || row.original.skip_reason || row.original.result || '—'
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: FiscalMonitoringRun } }) =>
      h(FiscalStatusBadge, { fill: true, status: row.original.situation || row.original.status })
  }
]

const findingColumns = [
  {
    id: 'title',
    header: 'Achado',
    cell: ({ row }: { row: { original: FiscalFinding } }) => row.original.title || row.original.code
  },
  {
    id: 'detail',
    header: 'Detalhe',
    cell: ({ row }: { row: { original: FiscalFinding } }) => row.original.detail || '—'
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: FiscalFinding } }) =>
      h(FiscalStatusBadge, { fill: true, status: row.original.situation || row.original.severity })
  }
]

const pendingColumns = [
  {
    id: 'title',
    header: 'Pendência',
    cell: ({ row }: { row: { original: FiscalPendingItem } }) => row.original.title || row.original.code
  },
  {
    id: 'due',
    header: 'Vencimento',
    cell: ({ row }: { row: { original: FiscalPendingItem } }) => formatDateTime(row.original.due_at)
  },
  {
    id: 'detail',
    header: 'Detalhe',
    cell: ({ row }: { row: { original: FiscalPendingItem } }) => row.original.detail || '—'
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: FiscalPendingItem } }) =>
      h(FiscalStatusBadge, { fill: true, status: row.original.situation || row.original.status })
  }
]

const installmentColumns = [
  {
    id: 'order',
    header: 'Pedido',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) => {
      const id = row.original.id
      const ext = row.original.external_order_id
      return ext ? `Pedido #${id} · ${ext}` : `Pedido #${id}`
    }
  },
  {
    id: 'modality',
    header: 'Modalidade',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.modality || row.original.modality_code || '—')
  },
  {
    id: 'amount',
    header: 'Valor',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      formatAmountCents(row.original.total_amount_cents as number | null)
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      h(FiscalStatusBadge, { fill: true, status: String(row.original.situation || row.original.status || '') })
  },
  documentColumnFor<Record<string, unknown> & { document?: FiscalDocumentDescriptor | null }>()
]

const declarationColumns = [
  {
    id: 'name',
    header: 'Obrigação',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) => {
      const code = String(row.original.obligation_name || row.original.obligation_code || `Decl. #${row.original.id}`)
      const number = row.original.declaration_number || row.original.receipt_number
      return number ? `${code} · ${String(number)}` : code
    }
  },
  {
    id: 'period',
    header: 'Período',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.period_key || row.original.competence_period_key || '—')
  },
  {
    id: 'due',
    header: 'Vencimento',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      formatDateTime(String(row.original.due_at || '') || null)
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      h(FiscalStatusBadge, { fill: true, status: String(row.original.delivery_status || row.original.situation || row.original.status || '') })
  },
  documentColumnFor<Record<string, unknown> & { document?: FiscalDocumentDescriptor | null }>()
]

const mailboxColumns = [
  {
    id: 'subject',
    header: 'Assunto',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.subject_preview || row.original.subject || `Msg #${row.original.id}`)
  },
  {
    id: 'when',
    header: 'Recebida',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      formatDateTime(String(row.original.received_at_official || row.original.received_at || row.original.created_at || '') || null)
  },
  {
    id: 'triage',
    header: 'Triagem',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      mailboxTriageLabel(String(row.original.triage_status || '') || null)
  }
]

function openMailboxInbox() {
  const id = Number(clientId.value)
  if (Number.isFinite(id) && id > 0) {
    setMailboxClientFilterHandoff(id)
  }
  void router.push('/monitoring/mailbox')
}

function openMailboxMessage(row: unknown) {
  const record = (row && typeof row === 'object' && 'original' in row
    ? (row as { original: Record<string, unknown> }).original
    : row) as Record<string, unknown> | null
  const messageId = Number(record?.id)
  const id = Number(clientId.value)
  if (Number.isFinite(id) && id > 0) {
    setMailboxClientFilterHandoff(id)
  }
  if (Number.isFinite(messageId) && messageId > 0) {
    void router.push(`/monitoring/mailbox/${messageId}`)
    return
  }
  void router.push('/monitoring/mailbox')
}

const guideColumns = [
  {
    id: 'guide',
    header: 'Guia',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) => {
      const period = String(row.original.competence_period_key || row.original.period_key || '—')
      const source = String(row.original.source || '')
      const code = row.original.das_number || row.original.identifier_code
      if (source === 'DCTFWEB_DARF' && code) {
        return `DARF ${String(code)} · ${period}`
      }
      if (code) {
        const prefix = source === 'PGDASD_CONSULT' || row.original.das_number ? 'DAS' : 'Guia'
        return `${prefix} ${String(code)} · ${period}`
      }
      return `Guia #${row.original.id} · ${period}`
    }
  },
  {
    id: 'amount',
    header: 'Valor',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      formatAmountCents(row.original.amount_cents as number | null)
  },
  {
    id: 'emission',
    header: 'Emissão',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) => {
      const ver = row.original.current_version as Record<string, unknown> | undefined
      const emittedAt = ver?.emitted_at || row.original.issued_at
      if (emittedAt) {
        return formatDateTime(String(emittedAt))
      }
      return String(ver?.emission_status || row.original.emission_status || '—')
    }
  },
  {
    id: 'status',
    header: 'Pagamento',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      h(FiscalStatusBadge, { fill: true, status: String(row.original.payment_status || 'UNKNOWN') })
  },
  documentColumnFor<Record<string, unknown> & { document?: FiscalDocumentDescriptor | null }>()
]

const fgtsColumns = [
  {
    id: 'competence',
    header: 'Competência',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.competence_period_key || `Competência #${row.original.id}`)
  },
  {
    id: 'closure',
    header: 'Fechamento',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.closure_status || '—')
  },
  {
    id: 'totalization',
    header: 'Totalização',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      String(row.original.totalization_status || '—')
  },
  {
    id: 'status',
    header: 'Situação',
    cell: ({ row }: { row: { original: Record<string, unknown> } }) =>
      h(FiscalStatusBadge, { fill: true, status: String(row.original.situation || row.original.closure_status || '') })
  }
]

const registrationColumns = [
  {
    id: 'key',
    header: 'Vínculo',
    cell: ({ row }: { row: { original: FiscalRegistrationLink } }) => row.original.link_key
  },
  {
    id: 'updated',
    header: 'Atualizado',
    cell: ({ row }: { row: { original: FiscalRegistrationLink } }) =>
      row.original.refreshed_at || row.original.observed_at || '—'
  },
  {
    id: 'origin',
    header: 'Origem',
    cell: ({ row }: { row: { original: FiscalRegistrationLink } }) =>
      row.original.is_simulated ? 'Simulado' : 'SERPRO'
  },
  {
    id: 'status',
    header: 'Status',
    cell: ({ row }: { row: { original: FiscalRegistrationLink } }) =>
      h(resolveComponent('UBadge'), { variant: 'subtle', label: String(row.original.status || '—') })
  }
]

const taxProcessColumns = [
  {
    id: 'number',
    header: 'Processo',
    cell: ({ row }: { row: { original: FiscalTaxProcess } }) => row.original.process_number
  },
  {
    id: 'updated',
    header: 'Atualizado',
    cell: ({ row }: { row: { original: FiscalTaxProcess } }) =>
      row.original.refreshed_at || row.original.observed_at || '—'
  },
  {
    id: 'origin',
    header: 'Origem',
    cell: ({ row }: { row: { original: FiscalTaxProcess } }) =>
      row.original.is_simulated ? 'Simulado' : 'SERPRO'
  },
  {
    id: 'status',
    header: 'Status',
    cell: ({ row }: { row: { original: FiscalTaxProcess } }) =>
      h(resolveComponent('UBadge'), { variant: 'subtle', label: String(row.original.status || '—') })
  }
]

function clearSectionData(key: SectionKey) {
  switch (key) {
    case 'overview':
      snapshots.value = []
      break
    case 'runs':
      runs.value = []
      break
    case 'findings':
      findings.value = []
      break
    case 'pending':
      pending.value = []
      break
    case 'installments':
      installments.value = []
      break
    case 'declarations':
      declarations.value = []
      break
    case 'pgdasd':
      break
    case 'dctfweb':
      dctfwebDeclarations.value = []
      break
    case 'guides':
      guides.value = []
      break
    case 'fgts':
      fgtsCompetences.value = []
      break
    case 'sitfis':
      sitfis.value = null
      break
    case 'mailbox':
      mailboxMessages.value = []
      break
    case 'registrations':
      registrationLinks.value = []
      break
    case 'ccmei':
      break
    case 'renunciations':
      break
    case 'tax_processes':
      taxProcesses.value = []
      break
  }
}

function invalidateAllSections() {
  for (const key of SECTION_KEYS) {
    sections[key] = emptySection()
    clearSectionData(key)
  }
  operationalProcesses.value = []
  Object.assign(operationalWorkState, emptySection())
}

async function loadClient(force = false) {
  if (!Number.isFinite(clientId.value) || clientId.value < 1) {
    clientError.value = 'Cliente inválido.'
    client.value = null
    return
  }

  if (!force && client.value?.id === clientId.value && !clientError.value) {
    return
  }

  const keyNow = cacheKey()
  clientLoading.value = true
  clientError.value = null
  try {
    const data = (await api.clients.get(clientId.value)).data
    // Descarte se o office/epoch mudou durante o request (mesmo clientId em outro tenant).
    if (cacheKey() !== keyNow) return
    client.value = data
  } catch (caught) {
    if (cacheKey() !== keyNow) return
    client.value = null
    clientError.value = apiErrorMessage(caught, 'Cliente não encontrado neste escritório.')
  } finally {
    if (cacheKey() === keyNow) {
      clientLoading.value = false
    }
  }
}

async function loadOperationalWork(force = false) {
  if (!Number.isFinite(clientId.value) || clientId.value < 1) return

  const keyNow = cacheKey()
  if (!force && operationalWorkState.loadedKey === keyNow && !operationalWorkState.error) {
    return
  }

  operationalWorkState.loading = true
  operationalWorkState.error = null
  try {
    const response = await api.work.processes.list({
      client_id: clientId.value,
      active_only: true,
      sort: 'due_date',
      direction: 'asc',
      per_page: 5
    })
    if (cacheKey() !== keyNow) return
    operationalProcesses.value = response.data || []
    operationalWorkState.loadedKey = keyNow
  } catch (caught) {
    if (cacheKey() !== keyNow) return
    operationalProcesses.value = []
    operationalWorkState.loadedKey = null
    operationalWorkState.error = apiErrorMessage(caught, 'Falha ao carregar o trabalho operacional.')
  } finally {
    if (cacheKey() === keyNow) operationalWorkState.loading = false
  }
}

async function loadSection(key: SectionKey, force = false) {
  if (!Number.isFinite(clientId.value) || clientId.value < 1) return

  const keyNow = cacheKey()
  const state = sections[key]
  if (!force && state.loadedKey === keyNow && !state.error) {
    return
  }

  state.loading = true
  state.error = null

  try {
    switch (key) {
      case 'overview': {
        // Carregamento paralelo e isolado: Work indisponível não oculta o fiscal.
        void loadOperationalWork(force)
        const res = await api.fiscal.snapshots.list({
          client_id: clientId.value,
          per_page: 20,
          current_only: true
        })
        if (cacheKey() !== keyNow) return
        snapshots.value = res.data || []
        break
      }
      case 'runs': {
        const res = await api.fiscal.runs.list({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        runs.value = res.data || []
        break
      }
      case 'findings': {
        const res = await api.fiscal.findings({
          client_id: clientId.value,
          per_page: 20,
          active_only: true
        })
        if (cacheKey() !== keyNow) return
        findings.value = res.data || []
        break
      }
      case 'pending': {
        const res = await api.fiscal.pending({
          client_id: clientId.value,
          per_page: 20,
          status: 'OPEN'
        })
        if (cacheKey() !== keyNow) return
        pending.value = res.data || []
        break
      }
      case 'installments': {
        const res = await api.fiscal.installments.orders({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        installments.value = (res.data || []).map(order => ({ ...order }))
        break
      }
      case 'declarations': {
        const res = await api.fiscal.declarations.list({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        declarations.value = (res.data as Record<string, unknown>[]) || []
        break
      }
      case 'pgdasd':
        // O histórico carrega apenas a projeção local dentro do próprio painel.
        break
      case 'dctfweb': {
        const res = await api.fiscal.dctfweb.list({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        dctfwebDeclarations.value = (res.data as Record<string, unknown>[]) || []
        break
      }
      case 'guides': {
        const res = await api.fiscal.guides.list({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        guides.value = (res.data as Record<string, unknown>[]) || []
        break
      }
      case 'fgts': {
        const res = await api.fiscal.fgts.competences({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        fgtsCompetences.value = (res.data as Record<string, unknown>[]) || []
        break
      }
      case 'sitfis': {
        const res = await api.fiscal.sitfis.show(clientId.value)
        if (cacheKey() !== keyNow) return
        sitfis.value = (res.data as Record<string, unknown>) || null
        break
      }
      case 'mailbox': {
        const res = await api.fiscal.mailbox.list({
          client_id: clientId.value,
          per_page: 20
        })
        if (cacheKey() !== keyNow) return
        mailboxMessages.value = (res.data as Record<string, unknown>[]) || []
        break
      }
      case 'registrations': {
        const res = await api.fiscal.registrations.forClient(clientId.value)
        if (cacheKey() !== keyNow) return
        registrationLinks.value = res.data?.links || []
        break
      }
      case 'renunciations':
        // O painel possui carregamento próprio: trocar de aba não gera egress.
        break
      case 'ccmei':
        // Os painéis carregam somente projeções locais; egress exige clique explícito.
        break
      case 'tax_processes': {
        const res = await api.fiscal.taxProcesses.forClient(clientId.value)
        if (cacheKey() !== keyNow) return
        taxProcesses.value = res.data?.processes || []
        break
      }
    }
    if (cacheKey() === keyNow) {
      state.loadedKey = keyNow
      state.error = null
    }
  } catch (caught) {
    if (cacheKey() !== keyNow) return
    // Erro honesto: limpa dados da seção e registra mensagem (não finge lista vazia).
    clearSectionData(key)
    state.loadedKey = null
    state.error = apiErrorMessage(caught, `Falha ao carregar ${key}.`)
  } finally {
    if (cacheKey() === keyNow) {
      state.loading = false
    }
  }
}

async function bootstrap(force = false) {
  await loadClient(force)
  if (clientError.value) return
  await loadSection(tab.value, force)
}

function retrySection(key: SectionKey) {
  void loadSection(key, true)
}

watch(sessionEpoch, () => {
  invalidateAllSections()
  void bootstrap(true)
})

watch(clientId, () => {
  client.value = null
  invalidateAllSections()
  void bootstrap(true)
})

watch(tab, (next) => {
  void loadSection(next)
})

onMounted(async () => {
  const legacyTab = String(route.query.tab || '')
  if (Object.keys(route.query).length > 0) {
    const base = `/monitoring/clients/${clientId.value}`
    await router.replace(isSectionKey(legacyTab) && legacyTab !== 'overview'
      ? `${base}/${legacyTab}`
      : base)
  }
  await bootstrap()
})
</script>

<template>
  <div
    class="flex min-h-0 w-full flex-1 flex-col"
    data-testid="monitoring-client-detail"
  >
    <ShellPageNavbar :title="pageTitle">
      <template #leading>
        <ShellNavbarBack
          to="/monitoring"
          label="Dashboard"
          aria-label="Voltar ao dashboard de monitoramento"
          test-id="monitoring-client-back"
        />
      </template>
      <template #right>
        <UButton
          class="lg:hidden"
          icon="i-lucide-panel-left"
          color="neutral"
          variant="ghost"
          aria-label="Abrir navegação do cliente"
          data-testid="monitoring-client-nav-toggle"
          @click="openClientNavigation"
        />
        <NavbarMoreActions
          :items="[{
            id: 'client-cadastro',
            label: 'Cadastro',
            icon: 'i-lucide-clipboard-list',
            to: clientCrmHref(clientId, 'cadastro')
          }, {
            id: 'client-dados-adicionais',
            label: 'Dados adicionais',
            icon: 'i-lucide-list',
            to: clientCrmHref(clientId, 'dados-adicionais')
          }]"
        />
      </template>
    </ShellPageNavbar>

    <div class="flex min-h-0 w-full flex-1">
      <!--
        Slot fixo w-14 (sempre fechado na tela). No hover/focus o painel
        cresce em overlay sobre o conteúdo — a tabela não reflow.
      -->
      <aside
        class="relative z-20 hidden w-14 shrink-0 lg:block"
        data-testid="monitoring-client-nav-panel"
        @mouseenter="onNavEnter"
        @mouseleave="onNavLeave"
        @focusin="onNavEnter"
        @focusout="onNavFocusOut"
      >
        <div
          class="absolute inset-y-0 left-0 flex flex-col overflow-hidden border-e border-default bg-default transition-[width,box-shadow] duration-150 ease-out"
          :class="navHovered
            ? 'w-52 shadow-lg ring-1 ring-default'
            : 'w-14'"
        >
          <div class="min-h-0 flex-1 overflow-y-auto p-2">
            <MonitoringClientFiscalAside
              :collapsed="!navHovered"
              :client="client"
              :client-id="clientId"
              :links="links"
              :loading="clientLoading"
              @switch-client="onSwitchClient"
            />
          </div>
        </div>
      </aside>

      <UDashboardPanel
        id="monitoring-client-content"
        class="min-w-0"
        data-testid="settings-panel"
        :ui="{ body: 'gap-4 sm:gap-6 lg:py-8' }"
      >
        <template #body>
          <UAlert
            v-if="clientError"
            color="error"
            icon="i-lucide-circle-x"
            :title="clientError"
          >
            <template #actions>
              <UButton
                size="xs"
                color="neutral"
                variant="outline"
                label="Tentar de novo"
                @click="bootstrap(true)"
              />
            </template>
          </UAlert>

          <div
            v-if="clientLoading && !client"
            class="py-12 text-center text-sm text-muted"
          >
            Carregando cliente…
          </div>

          <template v-else-if="client">
            <!-- Estado de carregamento da seção ativa -->
            <div
              v-if="sections[tab].loading && !sections[tab].loadedKey"
              class="py-8 text-center text-sm text-muted"
              data-testid="section-loading"
            >
              Carregando seção…
            </div>

            <!-- Falha da seção (não vira lista vazia) -->
            <UAlert
              v-else-if="sections[tab].error"
              color="error"
              icon="i-lucide-circle-x"
              :title="sections[tab].error || 'Falha ao carregar seção'"
              data-testid="section-error"
            >
              <template #actions>
                <UButton
                  size="xs"
                  color="neutral"
                  variant="outline"
                  label="Tentar de novo"
                  @click="retrySection(tab)"
                />
              </template>
            </UAlert>

            <template v-else>
              <div
                v-if="tab === 'overview'"
                class="space-y-6"
              >
                <section class="space-y-3" aria-labelledby="client-fiscal-processes-title">
                  <h2 id="client-fiscal-processes-title" class="text-sm font-medium text-highlighted">
                    Processos monitorados
                  </h2>
                  <MonitoringClientProcessOverview
                    :cards="overviewProcessCards"
                    :loading="sections.overview.loading"
                  />
                </section>

                <MonitoringClientOperationalWork
                  :client-id="clientId"
                  :items="operationalProcesses"
                  :loading="operationalWorkState.loading"
                  :error="operationalWorkState.error"
                  @retry="loadOperationalWork(true)"
                />
              </div>

              <ClientsClientPnrRenunciationsPanel
                v-else-if="tab === 'renunciations'"
                :client-id="clientId"
                :can-consult="canTriggerSync"
              />

              <section v-else-if="tab === 'pgdasd'" class="space-y-3">
                <MonitoringPgdasdHistoryView
                  :client-id="clientId"
                  :can-collect-documents="canTriggerSync"
                />
              </section>

              <div v-else-if="tab === 'ccmei'" class="space-y-4">
                <ClientsClientCcmeiPanel
                  :client-id="clientId"
                  :can-consult="canTriggerSync"
                />
                <ClientsClientCcmeiRegistrationStatusPanel
                  :client-id="clientId"
                  :can-consult="canTriggerSync"
                />
                <ClientsClientCcmeiCertificateIssuancePanel
                  :client-id="clientId"
                  :can-consult="canTriggerSync"
                />
              </div>

              <UPageCard
                v-else-if="tab === 'runs'"
                title="Execuções"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="runs"
                  :columns="runColumns"
                  :page="1"
                  :total="runs.length"
                  :items-per-page="runs.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-runs"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma execução"
                    />
                  </template>
                </ShellDataTable>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'findings'"
                title="Achados"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="findings"
                  :columns="findingColumns"
                  :page="1"
                  :total="findings.length"
                  :items-per-page="findings.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-findings"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhum achado ativo"
                    />
                  </template>
                </ShellDataTable>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'pending'"
                title="Pendências"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="pending"
                  :columns="pendingColumns"
                  :page="1"
                  :total="pending.length"
                  :items-per-page="pending.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-pending"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma pendência aberta"
                    />
                  </template>
                </ShellDataTable>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'installments'"
                title="Parcelamentos"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="installments"
                  :columns="installmentColumns"
                  :page="1"
                  :total="installments.length"
                  :items-per-page="installments.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-installments"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhum parcelamento"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/installments"
                    label="Abrir carteira de parcelamentos"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'declarations'"
                title="Declarações"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="declarations"
                  :columns="declarationColumns"
                  :page="1"
                  :total="declarations.length"
                  :items-per-page="declarations.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-declarations"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma declaração"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/declarations"
                    label="Abrir central de declarações"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'dctfweb'"
                title="DCTFWeb"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="dctfwebDeclarations"
                  :columns="declarationColumns"
                  :page="1"
                  :total="dctfwebDeclarations.length"
                  :items-per-page="dctfwebDeclarations.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-dctfweb"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma declaração DCTFWeb"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/dctfweb"
                    label="Abrir carteira DCTFWeb"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'mailbox'"
                title="Caixas Postais"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="mailboxMessages"
                  :columns="mailboxColumns"
                  :page="1"
                  :total="mailboxMessages.length"
                  :items-per-page="mailboxMessages.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-mailbox"
                  @select="(_event, row) => openMailboxMessage(row)"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma mensagem na caixa postal"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    label="Abrir caixas postais"
                    data-testid="client-mailbox-open-inbox"
                    @click="openMailboxInbox"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'guides'"
                title="Guias"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="guides"
                  :columns="guideColumns"
                  :page="1"
                  :total="guides.length"
                  :items-per-page="guides.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-guides"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma guia"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/guides"
                    label="Abrir central de guias"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'fgts'"
                title="FGTS Digital"
                variant="subtle"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="fgtsCompetences"
                  :columns="fgtsColumns"
                  :page="1"
                  :total="fgtsCompetences.length"
                  :items-per-page="fgtsCompetences.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-fgts"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhuma competência FGTS"
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/fgts"
                    label="Abrir carteira FGTS"
                  />
                </div>
              </UPageCard>

              <!-- sitfis: painel detalhe (não lista) — casca sempre visível -->
              <div
                v-else-if="tab === 'sitfis'"
                class="space-y-4"
                data-testid="client-sitfis-section"
              >
                <UPageCard
                  title="Situação fiscal (SITFIS)"
                  variant="subtle"
                >
                  <MonitoringTableEmptyState
                    v-if="!sitfis || (!sitfisSituation && !sitfisErrorCode)"
                    kind="empty"
                    title="Nenhum snapshot SITFIS"
                  />
                  <template v-else>
                    <UAlert
                      v-if="sitfisErrorCode"
                      color="error"
                      variant="subtle"
                      icon="i-lucide-circle-x"
                      class="mb-3"
                      :title="String(sitfisErrorCode)"
                      data-testid="client-sitfis-error"
                    />
                    <p v-if="sitfisErrorMessage" class="mb-3 text-sm text-error">
                      {{ sitfisErrorMessage }}
                    </p>
                    <dl
                      class="grid gap-2 text-sm sm:grid-cols-2"
                      data-testid="client-sitfis-fields"
                    >
                      <div>
                        <dt class="text-muted">
                          Situação
                        </dt>
                        <dd>
                          <FiscalStatusBadge
                            v-if="sitfisSituation"
                            :status="sitfisSituation"
                            show-hint
                          />
                          <span v-else class="text-muted">—</span>
                        </dd>
                      </div>
                      <div>
                        <dt class="text-muted">
                          Observado
                        </dt>
                        <dd>{{ formatDateTime(sitfisObserved) }}</dd>
                      </div>
                      <div>
                        <dt class="text-muted">
                          Protocolo
                        </dt>
                        <dd>{{ sitfisProtocol || '—' }}</dd>
                      </div>
                      <div>
                        <dt class="text-muted">
                          Cobertura
                        </dt>
                        <dd>{{ sitfisCoverage || '—' }}</dd>
                      </div>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                      <FiscalDocumentAction
                        :document="sitfisDocument"
                      />
                      <UButton
                        size="sm"
                        color="neutral"
                        variant="soft"
                        to="/monitoring/sitfis"
                        label="Abrir carteira SITFIS"
                      />
                    </div>
                  </template>
                </UPageCard>

                <MonitoringSitfisHistoryView
                  :client-id="clientId"
                  :client-name="client?.legal_name || client?.display_name"
                  :cnpj-masked="client?.cnpj || client?.root_cnpj"
                />
              </div>

              <UPageCard
                v-else-if="tab === 'registrations'"
                title="Cadastro e Vínculos"
                variant="subtle"
                data-testid="client-registrations-section"
              >
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="registrationLinks"
                  :columns="registrationColumns"
                  :page="1"
                  :total="registrationLinks.length"
                  :items-per-page="registrationLinks.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-registrations"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhum vínculo projetado"
                      description="Nenhum vínculo projetado para este cliente."
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/registrations"
                    label="Abrir carteira de vínculos"
                  />
                </div>
              </UPageCard>

              <UPageCard
                v-else-if="tab === 'tax_processes'"
                title="Processos Fiscais"
                variant="subtle"
                data-testid="client-tax-processes-section"
              >
                <p class="mb-3 text-xs text-muted">
                  Documentos indisponíveis via API produtiva
                </p>
                <ShellDataTable
                  ui-preset="monitoring-compact"
                  :data="taxProcesses"
                  :columns="taxProcessColumns"
                  :page="1"
                  :total="taxProcesses.length"
                  :items-per-page="taxProcesses.length || 1"
                  :show-footer="false"
                  test-id="client-section-table-tax-processes"
                >
                  <template #empty>
                    <MonitoringTableEmptyState
                      kind="empty"
                      title="Nenhum processo projetado"
                      description="Nenhum processo projetado para este cliente."
                    />
                  </template>
                </ShellDataTable>
                <div class="mt-3">
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="soft"
                    to="/monitoring/tax-processes"
                    label="Abrir carteira de processos"
                  />
                </div>
              </UPageCard>
            </template>
          </template>
        </template>
      </UDashboardPanel>
    </div>

    <ClientOnly>
      <USlideover
        v-if="isMobile"
        v-model:open="navOpen"
        side="left"
        title="Navegação do cliente"
        :ui="{ content: 'max-w-xs' }"
        data-testid="monitoring-client-nav-slideover"
      >
        <template #body>
          <MonitoringClientFiscalAside
            :collapsed="false"
            :client="client"
            :client-id="clientId"
            :links="links"
            :loading="clientLoading"
            class="p-1"
            @switch-client="onSwitchClient"
          />
        </template>
      </USlideover>
    </ClientOnly>
  </div>
</template>
