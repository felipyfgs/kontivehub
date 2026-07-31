<script setup lang="ts">
/**
 * Canvas Vue Flow (client-only). Desktop: editável; mobile: read-only.
 * Usa apenas `@vue-flow/core` (sem pacotes auxiliares extras).
 */
import {
  VueFlow,
  useVueFlow
} from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import type { FlowGraph } from '~/types/communication/flows'
import {
  domainGraphToVueFlow,
  FLOW_NODE_TYPE_META,
  isFlowNodeType,
  vueFlowToDomainGraph
} from '~/utils/communication-flow-graph'

const props = defineProps<{
  graph: FlowGraph
  selectedNodeId?: string | null
  readOnly?: boolean
  reducedMotion?: boolean
}>()

const emit = defineEmits<{
  'update:graph': [graph: FlowGraph]
  'select': [nodeId: string | null]
}>()

const { onConnect, onNodeDragStop, fitView } = useVueFlow()

/** Tipagem frouxa: evita conflito profundo Vue/Vue Flow em generate/typecheck. */
const nodes = ref<Array<Record<string, unknown>>>([])
const edges = ref<Array<Record<string, unknown>>>([])
const syncing = ref(false)

function applyGraph(graph: FlowGraph) {
  syncing.value = true
  const mapped = domainGraphToVueFlow(graph)
  nodes.value = mapped.nodes.map((node) => {
    const data = (node.data ?? {}) as Record<string, unknown>
    const type = data.domainType
    const typeLabel = isFlowNodeType(type) ? FLOW_NODE_TYPE_META[type].label : 'Nó'
    const customLabel = typeof data.label === 'string' ? data.label : typeLabel
    return {
      id: node.id,
      type: node.type,
      position: node.position,
      data: node.data,
      label: `${typeLabel}: ${customLabel}`,
      draggable: !props.readOnly,
      selectable: true,
      class: props.selectedNodeId === node.id ? 'flow-node-selected' : undefined
    }
  })
  edges.value = mapped.edges.map(edge => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    label: edge.label,
    data: edge.data,
    animated: !props.reducedMotion && !props.readOnly
  }))
  nextTick(() => {
    syncing.value = false
  })
}

watch(
  () => [props.graph, props.readOnly, props.selectedNodeId, props.reducedMotion] as const,
  () => applyGraph(props.graph),
  { deep: true, immediate: true }
)

function emitDomain() {
  if (syncing.value || props.readOnly) return
  emit(
    'update:graph',
    vueFlowToDomainGraph(
      nodes.value as never,
      edges.value as never
    )
  )
}

function onNodesUpdate(next: unknown) {
  nodes.value = Array.isArray(next) ? next as Array<Record<string, unknown>> : []
  emitDomain()
}

function onEdgesUpdate(next: unknown) {
  edges.value = Array.isArray(next) ? next as Array<Record<string, unknown>> : []
  emitDomain()
}

onConnect((connection) => {
  if (props.readOnly || !connection.source || !connection.target) return
  edges.value = [
    ...edges.value,
    {
      id: `e_${connection.source}_${connection.target}_${edges.value.length}`,
      source: connection.source,
      target: connection.target,
      animated: !props.reducedMotion
    }
  ]
  emitDomain()
})

onNodeDragStop(() => {
  emitDomain()
})

function onNodeClick(event: { node?: { id?: string }, id?: string }) {
  const id = event.node?.id ?? event.id
  emit('select', typeof id === 'string' ? id : null)
}

function onPaneClick() {
  emit('select', null)
}

onMounted(() => {
  void nextTick(() => fitView({ padding: 0.2 }))
})
</script>

<template>
  <div
    class="flow-editor-canvas h-full min-h-72 w-full overflow-hidden rounded-lg border border-default bg-default"
    :class="{ 'flow-editor-canvas--reduced': reducedMotion }"
    data-testid="flow-editor-canvas"
    :aria-readonly="readOnly ? 'true' : 'false'"
    role="application"
    aria-label="Canvas do fluxo"
  >
    <VueFlow
      :nodes="(nodes as never)"
      :edges="(edges as never)"
      :nodes-draggable="!readOnly"
      :nodes-connectable="!readOnly"
      :elements-selectable="true"
      :pan-on-drag="true"
      :zoom-on-scroll="true"
      :fit-view-on-init="true"
      class="h-full w-full"
      @update:nodes="onNodesUpdate"
      @update:edges="onEdgesUpdate"
      @node-click="onNodeClick"
      @pane-click="onPaneClick"
    />
  </div>
</template>

<style scoped>
.flow-editor-canvas :deep(.vue-flow) {
  background: transparent;
}

.flow-editor-canvas :deep(.vue-flow__node) {
  border: 1px solid var(--ui-border);
  border-radius: 0.5rem;
  background: var(--ui-bg-elevated);
  color: var(--ui-text-highlighted);
  padding: 0.35rem 0.6rem;
  font-size: 0.75rem;
}

.flow-editor-canvas :deep(.vue-flow__node.flow-node-selected),
.flow-editor-canvas :deep(.vue-flow__node.selected) {
  border-color: var(--ui-primary);
  box-shadow: 0 0 0 2px color-mix(in oklab, var(--ui-primary) 30%, transparent);
}

.flow-editor-canvas--reduced :deep(.vue-flow__edge-path) {
  transition: none !important;
}
</style>
