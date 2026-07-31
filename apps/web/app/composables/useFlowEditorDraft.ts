/**
 * Estado do editor de fluxo — composable Nuxt (ref/computed), sem Pinia.
 */
import type {
  FlowGraph,
  FlowGraphError,
  FlowNode,
  FlowNodeType
} from '~/types/communication/flows'
import {
  connectFlowNodes,
  insertFlowNode,
  normalizeFlowGraph,
  removeFlowNode,
  updateFlowNodeData,
  validateFlowGraphClient
} from '~/utils/communication-flow-graph'

export function useFlowEditorDraft(initial?: FlowGraph | null) {
  const graph = ref<FlowGraph>(normalizeFlowGraph(initial ?? null))
  const lockVersion = ref(1)
  const graphDigest = ref('')
  const selectedNodeId = ref<string | null>(null)
  const dirty = ref(false)
  const clientErrors = ref<FlowGraphError[]>([])
  const versionConflict = ref(false)

  const selectedNode = computed<FlowNode | null>(() => {
    if (!selectedNodeId.value) return null
    return graph.value.nodes.find(node => node.id === selectedNodeId.value) ?? null
  })

  const clientValidation = computed(() => validateFlowGraphClient(graph.value))

  function hydrate(next: {
    graph: FlowGraph
    lock_version: number
    graph_digest?: string
  }) {
    graph.value = normalizeFlowGraph(next.graph)
    lockVersion.value = next.lock_version
    graphDigest.value = next.graph_digest ?? ''
    dirty.value = false
    versionConflict.value = false
    clientErrors.value = []
    if (selectedNodeId.value && !graph.value.nodes.some(node => node.id === selectedNodeId.value)) {
      selectedNodeId.value = null
    }
  }

  function setGraph(next: FlowGraph, markDirty = true) {
    graph.value = normalizeFlowGraph(next)
    if (markDirty) dirty.value = true
    clientErrors.value = validateFlowGraphClient(graph.value).errors
  }

  function selectNode(id: string | null) {
    selectedNodeId.value = id
  }

  function addNode(
    type: FlowNodeType,
    options?: { connectFrom?: string | null, position?: { x: number, y: number } }
  ): string | null {
    const result = insertFlowNode(graph.value, type, {
      connectFrom: options?.connectFrom ?? selectedNodeId.value,
      position: options?.position
    })
    if ('error' in result) {
      clientErrors.value = [{
        path: 'graph.nodes',
        code: 'node_insert_rejected',
        message: result.error
      }]
      return null
    }
    setGraph(result)
    selectedNodeId.value = result.nodes[result.nodes.length - 1]?.id ?? null
    return selectedNodeId.value
  }

  function connect(source: string, target: string, extras?: { label?: string, branch?: string }) {
    const result = connectFlowNodes(graph.value, source, target, extras)
    if ('error' in result) {
      clientErrors.value = [{
        path: 'graph.edges',
        code: 'edge_insert_rejected',
        message: result.error
      }]
      return false
    }
    setGraph(result)
    return true
  }

  function removeSelected() {
    if (!selectedNodeId.value) return
    setGraph(removeFlowNode(graph.value, selectedNodeId.value))
    selectedNodeId.value = null
  }

  function removeNode(nodeId: string) {
    setGraph(removeFlowNode(graph.value, nodeId))
    if (selectedNodeId.value === nodeId) selectedNodeId.value = null
  }

  function patchSelectedData(data: Record<string, unknown>) {
    if (!selectedNodeId.value) return
    setGraph(updateFlowNodeData(graph.value, selectedNodeId.value, data))
  }

  function runClientValidate(): boolean {
    const result = validateFlowGraphClient(graph.value)
    clientErrors.value = result.errors
    return result.valid
  }

  function markConflict() {
    versionConflict.value = true
  }

  return {
    graph,
    lockVersion,
    graphDigest,
    selectedNodeId,
    selectedNode,
    dirty,
    clientErrors,
    clientValidation,
    versionConflict,
    hydrate,
    setGraph,
    selectNode,
    addNode,
    connect,
    removeSelected,
    removeNode,
    patchSelectedData,
    runClientValidate,
    markConflict
  }
}
