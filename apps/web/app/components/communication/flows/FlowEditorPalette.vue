<script setup lang="ts">
/**
 * Paleta de nós allowlisted do designer de fluxos.
 */
import type { CommunicationFlowNodeType } from '~/types/communication'
import { FLOW_NODE_TYPES, FLOW_NODE_TYPE_META } from '~/utils/communication-flow-graph'

const props = defineProps<{
  disabled?: boolean
}>()

const emit = defineEmits<{
  add: [type: CommunicationFlowNodeType]
}>()

const items = FLOW_NODE_TYPES.filter(type => type !== 'start').map(type => ({
  type,
  ...FLOW_NODE_TYPE_META[type]
}))

const startMeta = FLOW_NODE_TYPE_META.start
</script>

<template>
  <nav
    class="flex h-full flex-col gap-3 overflow-y-auto p-3"
    aria-label="Paleta de nós do fluxo"
    data-testid="flow-editor-palette"
  >
    <div>
      <p class="text-xs font-medium uppercase tracking-wide text-muted">
        Entrada
      </p>
      <UButton
        class="mt-2 w-full justify-start"
        color="neutral"
        variant="soft"
        :icon="startMeta.icon"
        :label="startMeta.label"
        :disabled="props.disabled"
        :aria-label="`Inserir nó ${startMeta.label}`"
        data-testid="flow-editor-palette-start"
        @click="emit('add', 'start')"
      />
      <p class="mt-1 text-xs text-muted">
        {{ startMeta.description }}
      </p>
    </div>

    <USeparator />

    <div>
      <p class="text-xs font-medium uppercase tracking-wide text-muted">
        Nós
      </p>
      <ul class="mt-2 space-y-1">
        <li
          v-for="item in items"
          :key="item.type"
        >
          <UButton
            class="w-full justify-start"
            color="neutral"
            variant="ghost"
            :icon="item.icon"
            :label="item.label"
            :disabled="props.disabled"
            :aria-label="`Inserir nó ${item.label}`"
            :data-testid="`flow-editor-palette-${item.type}`"
            @click="emit('add', item.type)"
          />
        </li>
      </ul>
    </div>
  </nav>
</template>
