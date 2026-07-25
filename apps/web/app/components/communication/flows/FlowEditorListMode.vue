<script setup lang="ts">
/**
 * Modo lista editável (mobile e alternativa ao drag no desktop).
 */
import type { CommunicationFlowGraph, CommunicationFlowNode } from '~/types/communication'
import {
  FLOW_NODE_TYPE_META,
  flowNodeSummary
} from '~/utils/communication-flow-graph'

const props = defineProps<{
  graph: CommunicationFlowGraph
  selectedNodeId?: string | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  select: [nodeId: string]
  remove: [nodeId: string]
  connect: [source: string, target: string]
}>()

const connectFrom = ref<string | null>(null)
const connectTo = ref<string | undefined>(undefined)

const nodeItems = computed(() =>
  props.graph.nodes.map(node => ({
    label: `${FLOW_NODE_TYPE_META[node.type].label} (${node.id})`,
    value: node.id
  }))
)

function onSelect(node: CommunicationFlowNode) {
  emit('select', node.id)
}

function beginConnect(nodeId: string) {
  connectFrom.value = nodeId
  connectTo.value = undefined
  emit('select', nodeId)
}

function confirmConnect() {
  if (!connectFrom.value || !connectTo.value) return
  emit('connect', connectFrom.value, connectTo.value)
  connectFrom.value = null
  connectTo.value = undefined
}
</script>

<template>
  <div
    class="space-y-3"
    data-testid="flow-editor-list-mode"
    aria-label="Modo lista do fluxo"
  >
    <UEmpty
      v-if="!graph.nodes.length"
      icon="i-lucide-list-tree"
      title="Grafo vazio"
      description="Insira um nó start pela paleta para começar."
      class="py-8"
    />

    <ul
      v-else
      class="divide-y divide-default rounded-lg border border-default"
      role="listbox"
      aria-label="Nós do fluxo"
    >
      <li
        v-for="node in graph.nodes"
        :key="node.id"
        class="flex flex-wrap items-start justify-between gap-3 px-3 py-3"
        :class="selectedNodeId === node.id ? 'bg-elevated' : ''"
        role="option"
        :aria-selected="selectedNodeId === node.id ? 'true' : 'false'"
        :data-testid="`flow-editor-list-node-${node.id}`"
        tabindex="0"
        @click="onSelect(node)"
        @keydown.enter.prevent="onSelect(node)"
        @keydown.space.prevent="onSelect(node)"
      >
        <div class="min-w-0">
          <p class="text-sm font-medium text-highlighted">
            {{ FLOW_NODE_TYPE_META[node.type].label }}
          </p>
          <p class="font-mono text-xs text-muted">
            {{ node.id }}
          </p>
          <p class="mt-1 text-xs text-muted">
            {{ flowNodeSummary(node) }}
          </p>
        </div>
        <div class="flex flex-wrap gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-git-branch-plus"
            label="Conectar"
            :disabled="disabled"
            :aria-label="`Conectar a partir de ${node.id}`"
            @click.stop="beginConnect(node.id)"
          />
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :disabled="disabled"
            :aria-label="`Remover nó ${node.id}`"
            @click.stop="emit('remove', node.id)"
          />
        </div>
      </li>
    </ul>

    <ShellSectionCard
      v-if="connectFrom"
      class="p-3"
      data-testid="flow-editor-list-connect"
    >
      <p class="text-sm text-highlighted">
        Conectar <span class="font-mono">{{ connectFrom }}</span> →
      </p>
      <div class="mt-2 flex flex-wrap items-end gap-2">
        <UFormField
          label="Destino"
          name="connect_to"
          class="min-w-48 flex-1"
        >
          <USelect
            v-model="connectTo"
            :items="nodeItems.filter(item => item.value !== connectFrom)"
            placeholder="Selecione o nó"
            class="w-full"
          />
        </UFormField>
        <UButton
          label="Confirmar conexão"
          icon="i-lucide-check"
          :disabled="disabled || !connectTo"
          data-testid="flow-editor-list-connect-confirm"
          @click="confirmConnect"
        />
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancelar"
          @click="() => { connectFrom = null }"
        />
      </div>
    </ShellSectionCard>

    <div
      v-if="graph.edges.length"
      class="rounded-lg border border-default p-3"
    >
      <p class="text-xs font-medium uppercase tracking-wide text-muted">
        Conexões
      </p>
      <ul class="mt-2 space-y-1">
        <li
          v-for="(edge, index) in graph.edges"
          :key="edge.id || `${edge.source}-${edge.target}-${index}`"
          class="font-mono text-xs text-muted"
        >
          {{ edge.source }} → {{ edge.target }}
          <span v-if="edge.label || edge.branch">
            ({{ edge.label || edge.branch }})
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>
