import type {
  CommunicationFlow,
  CommunicationFlowGraph,
  CommunicationFlowStatus
} from '~/types/communication'

export const EMPTY_FLOW_GRAPH: CommunicationFlowGraph = {
  nodes: [],
  edges: []
}

export function communicationFlowStatusLabel(
  status: CommunicationFlowStatus | Pick<CommunicationFlow, 'status'>
): string {
  const value = typeof status === 'string' ? status : status.status
  return value === 'active' ? 'Ativo' : 'Pausado'
}

export function communicationFlowStatusColor(
  status: CommunicationFlowStatus | Pick<CommunicationFlow, 'status'>
): 'success' | 'neutral' {
  const value = typeof status === 'string' ? status : status.status
  return value === 'active' ? 'success' : 'neutral'
}

export function communicationFlowEmptyKind(input: {
  q: string
  status: 'all' | CommunicationFlowStatus
}): 'empty' | 'filtered' {
  if (input.q.trim() || input.status !== 'all') return 'filtered'
  return 'empty'
}

export function filterCommunicationFlows(
  items: CommunicationFlow[],
  input: { q: string, status: 'all' | CommunicationFlowStatus }
): CommunicationFlow[] {
  const needle = input.q.trim().toLowerCase()
  return items.filter((item) => {
    if (input.status !== 'all' && item.status !== input.status) return false
    if (!needle) return true
    return item.name.toLowerCase().includes(needle)
  })
}

export function paginateCommunicationFlows<T>(
  items: T[],
  page: number,
  perPage: number
): { rows: T[], total: number } {
  const total = items.length
  const safePage = Math.max(1, page)
  const numeric = Number(perPage)
  const safePerPage = Number.isFinite(numeric) && numeric > 0 ? Math.floor(numeric) : 20
  const start = (safePage - 1) * safePerPage
  return {
    rows: items.slice(start, start + safePerPage),
    total
  }
}

export function formatFlowGraphJson(graph: CommunicationFlowGraph | null | undefined): string {
  return JSON.stringify(graph ?? EMPTY_FLOW_GRAPH, null, 2)
}

export function parseFlowGraphJson(raw: string): { ok: true, graph: CommunicationFlowGraph } | { ok: false, message: string } {
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return { ok: false, message: 'JSON inválido. Corrija a sintaxe do grafo.' }
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return { ok: false, message: 'O grafo deve ser um objeto com nodes e edges.' }
  }
  const record = parsed as Record<string, unknown>
  if (!Array.isArray(record.nodes) || !Array.isArray(record.edges)) {
    return { ok: false, message: 'O grafo deve incluir arrays nodes e edges.' }
  }
  return {
    ok: true,
    graph: {
      nodes: record.nodes,
      edges: record.edges
    }
  }
}

/** Mutações bloqueadas quando a engine está fail-closed (flag OFF). */
export function communicationFlowsMutationBlocked(
  flowsEnabled: boolean,
  canManage: boolean
): string | null {
  if (!canManage) {
    return 'É necessária a permissão communication.manage_flows para alterar fluxos.'
  }
  if (!flowsEnabled) {
    return 'Engine de fluxos desabilitada (fail-closed). Mutações estão bloqueadas.'
  }
  return null
}
