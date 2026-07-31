/**
 * Domínio do grafo de fluxos: allowlist, validação client prévia e adapters Vue Flow.
 * Sem Pinia — puro TypeScript.
 */
import type {
  Edge as VueFlowEdge,
  Node as VueFlowNode
} from '@vue-flow/core'
import type {
  FlowActionKind,
  FlowConditionField,
  FlowConditionOperator,
  FlowEdge,
  FlowGraph,
  FlowGraphError,
  FlowNode,
  FlowNodeType
} from '~/types/communication/flows'

export const FLOW_NODE_TYPES = [
  'start',
  'message',
  'quick_reply',
  'question',
  'condition',
  'delay',
  'action',
  'handoff',
  'end'
] as const satisfies readonly FlowNodeType[]

export const FLOW_NODE_TYPE_META: Record<FlowNodeType, {
  label: string
  description: string
  icon: string
}> = {
  start: {
    label: 'Início',
    description: 'Entrada única do fluxo',
    icon: 'i-lucide-play'
  },
  message: {
    label: 'Mensagem',
    description: 'Envia texto ou resposta rápida',
    icon: 'i-lucide-message-square'
  },
  quick_reply: {
    label: 'Resposta rápida',
    description: 'Envia canned response do Tenant',
    icon: 'i-lucide-zap'
  },
  question: {
    label: 'Pergunta',
    description: 'Aguarda resposta enumerada',
    icon: 'i-lucide-help-circle'
  },
  condition: {
    label: 'Condição',
    description: 'Ramifica por predicado allowlisted',
    icon: 'i-lucide-git-branch'
  },
  delay: {
    label: 'Espera',
    description: 'Aguarda duração em segundos',
    icon: 'i-lucide-timer'
  },
  action: {
    label: 'Ação',
    description: 'Label, assignee ou status',
    icon: 'i-lucide-bolt'
  },
  handoff: {
    label: 'Handoff',
    description: 'Transfere para atendimento humano',
    icon: 'i-lucide-user-round-cog'
  },
  end: {
    label: 'Fim',
    description: 'Encerra o fluxo',
    icon: 'i-lucide-flag'
  }
}

export const FLOW_CONDITION_FIELDS: readonly FlowConditionField[] = [
  'contact.name',
  'conversation.status',
  'last_inbound_text'
]

export const FLOW_CONDITION_OPERATORS: readonly FlowConditionOperator[] = [
  'eq',
  'contains'
]

export const FLOW_ACTION_KINDS: readonly FlowActionKind[] = [
  'label',
  'assignee',
  'status'
]

const FORBIDDEN_HINTS = [
  'webhook',
  'code',
  'script',
  'ai',
  'llm',
  'regex',
  'jsonpath',
  'http',
  'fiscal',
  'serpro',
  'callback'
] as const

export function isFlowNodeType(value: unknown): value is FlowNodeType {
  return typeof value === 'string'
    && (FLOW_NODE_TYPES as readonly string[]).includes(value)
}

export function createEmptyFlowGraph(): FlowGraph {
  return { nodes: [], edges: [] }
}

export function createDefaultNodeData(type: FlowNodeType): Record<string, unknown> {
  switch (type) {
    case 'message':
      return { body: '' }
    case 'quick_reply':
      return { canned_response_id: null }
    case 'question':
      return { prompt: '', options: ['Sim', 'Não'] }
    case 'condition':
      return {
        field: 'last_inbound_text' satisfies FlowConditionField,
        operator: 'contains' satisfies FlowConditionOperator,
        value: ''
      }
    case 'delay':
      return { duration_seconds: 60 }
    case 'action':
      return { kind: 'status' satisfies FlowActionKind, status: 'OPEN' }
    case 'handoff':
      return { assignee_membership_id: null }
    case 'start':
    case 'end':
    default:
      return {}
  }
}

export function createFlowNode(
  type: FlowNodeType,
  overrides?: Partial<FlowNode>
): FlowNode {
  const id = overrides?.id?.trim() || `node_${type}_${Math.random().toString(36).slice(2, 9)}`
  return {
    id,
    type,
    data: { ...createDefaultNodeData(type), ...(overrides?.data ?? {}) },
    position: overrides?.position ?? { x: 80, y: 80 },
    label: overrides?.label ?? FLOW_NODE_TYPE_META[type].label
  }
}

export function normalizeFlowGraph(input: unknown): FlowGraph {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return createEmptyFlowGraph()
  }
  const record = input as Record<string, unknown>
  const nodesRaw = Array.isArray(record.nodes) ? record.nodes : []
  const edgesRaw = Array.isArray(record.edges) ? record.edges : []

  const nodes: FlowNode[] = []
  for (const item of nodesRaw) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) continue
    const row = item as Record<string, unknown>
    const id = typeof row.id === 'string' ? row.id.trim() : ''
    const type = typeof row.type === 'string' ? row.type.trim().toLowerCase() : ''
    if (!id || !isFlowNodeType(type)) continue
    const position = row.position && typeof row.position === 'object' && !Array.isArray(row.position)
      ? row.position as Record<string, unknown>
      : null
    nodes.push({
      id,
      type,
      data: row.data && typeof row.data === 'object' && !Array.isArray(row.data)
        ? { ...(row.data as Record<string, unknown>) }
        : {},
      position: position
        ? {
            x: Number(position.x) || 0,
            y: Number(position.y) || 0
          }
        : undefined,
      label: typeof row.label === 'string' ? row.label : undefined
    })
  }

  const edges: FlowEdge[] = []
  for (const [index, item] of edgesRaw.entries()) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) continue
    const row = item as Record<string, unknown>
    const source = typeof row.source === 'string' ? row.source.trim() : ''
    const target = typeof row.target === 'string' ? row.target.trim() : ''
    if (!source || !target) continue
    edges.push({
      id: typeof row.id === 'string' && row.id.trim()
        ? row.id.trim()
        : `edge_${source}_${target}_${index}`,
      source,
      target,
      label: typeof row.label === 'string' ? row.label : undefined,
      branch: typeof row.branch === 'string' ? row.branch : undefined,
      data: row.data && typeof row.data === 'object' && !Array.isArray(row.data)
        ? { ...(row.data as Record<string, unknown>) }
        : undefined
    })
  }

  return { nodes, edges }
}

export function domainGraphToVueFlow(graph: FlowGraph): {
  nodes: VueFlowNode[]
  edges: VueFlowEdge[]
} {
  const nodes: VueFlowNode[] = graph.nodes.map((node, index) => ({
    id: node.id,
    type: 'default',
    position: node.position ?? {
      x: 120 + (index % 4) * 220,
      y: 80 + Math.floor(index / 4) * 140
    },
    data: {
      domainType: node.type,
      label: node.label ?? FLOW_NODE_TYPE_META[node.type].label,
      payload: node.data ?? {}
    },
    label: node.label ?? FLOW_NODE_TYPE_META[node.type].label,
    draggable: true,
    selectable: true
  }))

  const edges: VueFlowEdge[] = graph.edges.map((edge, index) => ({
    id: edge.id || `e_${edge.source}_${edge.target}_${index}`,
    source: edge.source,
    target: edge.target,
    label: edge.label || edge.branch,
    data: {
      branch: edge.branch,
      payload: edge.data ?? {}
    },
    animated: false
  }))

  return { nodes, edges }
}

export function vueFlowToDomainGraph(
  nodes: VueFlowNode[],
  edges: VueFlowEdge[]
): FlowGraph {
  const domainNodes: FlowNode[] = []
  for (const node of nodes) {
    const data = (node.data ?? {}) as Record<string, unknown>
    const typeRaw = data.domainType ?? node.type
    if (!isFlowNodeType(typeRaw)) continue
    const payload = data.payload && typeof data.payload === 'object' && !Array.isArray(data.payload)
      ? data.payload as Record<string, unknown>
      : {}
    domainNodes.push({
      id: node.id,
      type: typeRaw,
      data: payload,
      position: {
        x: Math.round(node.position.x),
        y: Math.round(node.position.y)
      },
      label: typeof data.label === 'string'
        ? data.label
        : (typeof node.label === 'string' ? node.label : FLOW_NODE_TYPE_META[typeRaw].label)
    })
  }

  const domainEdges: FlowEdge[] = edges.map((edge, index) => {
    const data = (edge.data ?? {}) as Record<string, unknown>
    return {
      id: edge.id || `e_${edge.source}_${edge.target}_${index}`,
      source: edge.source,
      target: edge.target,
      label: typeof edge.label === 'string' ? edge.label : undefined,
      branch: typeof data.branch === 'string' ? data.branch : undefined,
      data: data.payload && typeof data.payload === 'object' && !Array.isArray(data.payload)
        ? data.payload as Record<string, unknown>
        : undefined
    }
  })

  return { nodes: domainNodes, edges: domainEdges }
}

function pushError(
  errors: FlowGraphError[],
  path: string,
  code: string,
  message: string
) {
  errors.push({ path, code, message })
}

function rejectForbidden(value: unknown, path: string, errors: FlowGraphError[]) {
  if (typeof value === 'string') {
    const lower = value.toLowerCase()
    for (const hint of FORBIDDEN_HINTS) {
      if (lower === hint || lower.includes(`${hint}:`) || lower.includes(`${hint}_`)) {
        pushError(errors, path, 'forbidden_content', `Conteúdo proibido detectado (${hint}).`)
        return
      }
    }
    return
  }
  if (!value || typeof value !== 'object') return
  if (Array.isArray(value)) {
    value.forEach((child, index) => rejectForbidden(child, `${path}.${index}`, errors))
    return
  }
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    const keyLower = key.toLowerCase()
    for (const hint of FORBIDDEN_HINTS) {
      if (keyLower === hint || keyLower.includes(hint)) {
        pushError(errors, `${path}.${key}`, 'forbidden_field', `Campo proibido: ${key}.`)
        break
      }
    }
    rejectForbidden(child, `${path}.${key}`, errors)
  }
}

/**
 * Validação client prévia (espelho parcial do PHP). Server continua autoritativo.
 */
export function validateFlowGraphClient(graph: FlowGraph): {
  valid: boolean
  errors: FlowGraphError[]
} {
  const errors: FlowGraphError[] = []
  rejectForbidden(graph, 'graph', errors)

  if (!Array.isArray(graph.nodes)) {
    pushError(errors, 'graph.nodes', 'nodes_required', 'O grafo exige nodes[] como lista.')
  }
  if (!Array.isArray(graph.edges)) {
    pushError(errors, 'graph.edges', 'edges_required', 'O grafo exige edges[] como lista.')
  }
  if (errors.length) {
    return { valid: false, errors }
  }

  const nodeIds = new Map<string, FlowNodeType>()
  const startIds: string[] = []

  graph.nodes.forEach((node, index) => {
    const path = `graph.nodes.${index}`
    if (!node?.id?.trim()) {
      pushError(errors, `${path}.id`, 'node_id_required', 'Cada nó exige id.')
      return
    }
    if (nodeIds.has(node.id)) {
      pushError(errors, `${path}.id`, 'node_id_duplicate', `Id de nó duplicado: ${node.id}.`)
    }
    if (!isFlowNodeType(node.type)) {
      pushError(errors, `${path}.type`, 'node_type_forbidden', `Tipo de nó não permitido: ${String(node.type)}.`)
      return
    }
    nodeIds.set(node.id, node.type)
    if (node.type === 'start') startIds.push(node.id)
    rejectForbidden(node.data ?? {}, `${path}.data`, errors)

    const data = node.data ?? {}
    if (node.type === 'message') {
      const body = typeof data.body === 'string' ? data.body.trim() : ''
      const canned = Number(data.canned_response_id)
      if (!body && !(Number.isInteger(canned) && canned > 0)) {
        pushError(errors, `${path}.data`, 'message_content_required', 'Nó message exige body ou canned_response_id.')
      }
    }
    if (node.type === 'quick_reply') {
      const canned = Number(data.canned_response_id)
      if (!(Number.isInteger(canned) && canned > 0)) {
        pushError(errors, `${path}.data.canned_response_id`, 'canned_required', 'Nó quick_reply exige canned_response_id.')
      }
    }
    if (node.type === 'question') {
      const prompt = typeof data.prompt === 'string' ? data.prompt.trim() : ''
      if (!prompt) {
        pushError(errors, `${path}.data.prompt`, 'prompt_required', 'Nó question exige prompt.')
      }
      if (!Array.isArray(data.options) || data.options.length === 0) {
        pushError(errors, `${path}.data.options`, 'options_required', 'Nó question exige options[] enumeradas.')
      }
    }
    if (node.type === 'condition') {
      const field = typeof data.field === 'string' ? data.field : ''
      const operator = typeof data.operator === 'string' ? data.operator : ''
      if (!(FLOW_CONDITION_FIELDS as readonly string[]).includes(field)) {
        pushError(errors, `${path}.data.field`, 'condition_field_forbidden', 'Campo de condição fora da allowlist.')
      }
      if (!(FLOW_CONDITION_OPERATORS as readonly string[]).includes(operator)) {
        pushError(errors, `${path}.data.operator`, 'condition_operator_forbidden', 'Operador de condição fora da allowlist.')
      }
    }
    if (node.type === 'delay') {
      const seconds = Number(data.duration_seconds)
      if (!Number.isInteger(seconds) || seconds < 1) {
        pushError(errors, `${path}.data.duration_seconds`, 'delay_out_of_bounds', 'Delay deve ser um inteiro ≥ 1.')
      }
    }
    if (node.type === 'action') {
      const kind = typeof data.kind === 'string' ? data.kind : ''
      if (!(FLOW_ACTION_KINDS as readonly string[]).includes(kind)) {
        pushError(errors, `${path}.data.kind`, 'action_kind_forbidden', 'Ação deve ser label, assignee ou status.')
      }
    }
  })

  if (startIds.length !== 1) {
    pushError(errors, 'graph.nodes', 'start_required', 'O grafo exige exatamente um nó start.')
  }

  const adjacency = new Map<string, string[]>()
  for (const id of nodeIds.keys()) adjacency.set(id, [])

  graph.edges.forEach((edge, index) => {
    const path = `graph.edges.${index}`
    if (!edge.source || !edge.target) {
      pushError(errors, path, 'edge_endpoints_required', 'Aresta exige source e target.')
      return
    }
    if (!nodeIds.has(edge.source) || !nodeIds.has(edge.target)) {
      pushError(errors, path, 'edge_unknown_node', 'Aresta aponta para nó inexistente.')
      return
    }
    adjacency.get(edge.source)!.push(edge.target)
  })

  if (startIds.length === 1) {
    const start = startIds[0]!
    const reachable = new Set<string>()
    const stack = [start]
    while (stack.length) {
      const current = stack.pop()!
      if (reachable.has(current)) continue
      reachable.add(current)
      for (const next of adjacency.get(current) ?? []) stack.push(next)
    }
    for (const id of nodeIds.keys()) {
      if (!reachable.has(id)) {
        pushError(errors, 'graph.nodes', 'orphan_node', `Nó órfão não alcançável a partir do start: ${id}.`)
      }
    }
    if (hasCycle(adjacency, reachable)) {
      pushError(errors, 'graph.edges', 'cycle_detected', 'O grafo contém ciclo; apenas DAG é permitido.')
    }
  }

  return { valid: errors.length === 0, errors }
}

function hasCycle(adjacency: Map<string, string[]>, reachable: Set<string>): boolean {
  const visiting = new Set<string>()
  const visited = new Set<string>()

  const dfs = (node: string): boolean => {
    if (visiting.has(node)) return true
    if (visited.has(node)) return false
    visiting.add(node)
    for (const next of adjacency.get(node) ?? []) {
      if (dfs(next)) return true
    }
    visiting.delete(node)
    visited.add(node)
    return false
  }

  for (const id of reachable) {
    if (dfs(id)) return true
  }
  return false
}

export function canInsertFlowNodeType(
  type: string,
  graph: FlowGraph
): { ok: true } | { ok: false, message: string } {
  if (!isFlowNodeType(type)) {
    return { ok: false, message: `Tipo de nó não permitido: ${type}.` }
  }
  if (type === 'start' && graph.nodes.some(node => node.type === 'start')) {
    return { ok: false, message: 'O grafo já possui um nó start.' }
  }
  return { ok: true }
}

export function insertFlowNode(
  graph: FlowGraph,
  type: FlowNodeType,
  options?: { connectFrom?: string | null, position?: { x: number, y: number } }
): FlowGraph | { error: string } {
  const allowed = canInsertFlowNodeType(type, graph)
  if (!allowed.ok) return { error: allowed.message }

  const node = createFlowNode(type, {
    position: options?.position ?? {
      x: 120 + graph.nodes.length * 40,
      y: 100 + (graph.nodes.length % 5) * 80
    }
  })
  const edges = [...graph.edges]
  const from = options?.connectFrom?.trim()
  if (from && graph.nodes.some(item => item.id === from)) {
    edges.push({
      id: `edge_${from}_${node.id}`,
      source: from,
      target: node.id
    })
  }
  return {
    nodes: [...graph.nodes, node],
    edges
  }
}

export function connectFlowNodes(
  graph: FlowGraph,
  source: string,
  target: string,
  extras?: { label?: string, branch?: string }
): FlowGraph | { error: string } {
  if (!graph.nodes.some(node => node.id === source)) {
    return { error: 'Nó de origem não encontrado.' }
  }
  if (!graph.nodes.some(node => node.id === target)) {
    return { error: 'Nó de destino não encontrado.' }
  }
  if (source === target) {
    return { error: 'Não é possível conectar um nó a si mesmo.' }
  }
  if (graph.edges.some(edge => edge.source === source && edge.target === target)) {
    return { error: 'Essa conexão já existe.' }
  }
  return {
    nodes: graph.nodes,
    edges: [
      ...graph.edges,
      {
        id: `edge_${source}_${target}_${graph.edges.length}`,
        source,
        target,
        label: extras?.label,
        branch: extras?.branch
      }
    ]
  }
}

export function removeFlowNode(graph: FlowGraph, nodeId: string): FlowGraph {
  return {
    nodes: graph.nodes.filter(node => node.id !== nodeId),
    edges: graph.edges.filter(edge => edge.source !== nodeId && edge.target !== nodeId)
  }
}

export function updateFlowNodeData(
  graph: FlowGraph,
  nodeId: string,
  data: Record<string, unknown>
): FlowGraph {
  return {
    nodes: graph.nodes.map((node) => {
      if (node.id !== nodeId) return node
      return { ...node, data: { ...data } }
    }),
    edges: graph.edges
  }
}

export function flowNodeSummary(node: FlowNode): string {
  const data = node.data ?? {}
  switch (node.type) {
    case 'message':
      return typeof data.body === 'string' && data.body.trim()
        ? data.body.trim().slice(0, 80)
        : (data.canned_response_id ? `Resposta #${data.canned_response_id}` : 'Sem texto')
    case 'quick_reply':
      return data.canned_response_id ? `Canned #${data.canned_response_id}` : 'Sem canned'
    case 'question':
      return typeof data.prompt === 'string' ? data.prompt.slice(0, 80) : 'Pergunta'
    case 'condition':
      return `${String(data.field ?? '')} ${String(data.operator ?? '')} ${String(data.value ?? '')}`.trim()
    case 'delay':
      return `${Number(data.duration_seconds) || 0}s`
    case 'action':
      return String(data.kind ?? 'ação')
    case 'handoff':
      return data.assignee_membership_id ? `Assignee #${data.assignee_membership_id}` : 'Atendimento humano'
    default:
      return FLOW_NODE_TYPE_META[node.type].label
  }
}
