<script setup lang="ts">
/**
 * Coluna droppable do board Kanban.
 */
import type { WorkTaskSummary } from '~/types/work'
import type { IDragEvent } from '@vue-dnd-kit/core'
import {
  taskStatusColor,
  taskStatusIcon,
  taskStatusLabel
} from '~/utils/work-labels'
import type { WorkKanbanColumnStatus } from '~/utils/work-kanban-transition'

const props = defineProps<{
  status: WorkKanbanColumnStatus
  items: WorkTaskSummary[]
  selectedTaskId?: number | null
  disabled?: boolean
  onDropTask: (
    event: IDragEvent<WorkTaskSummary, { status: WorkKanbanColumnStatus }>
  ) => boolean | Promise<boolean>
}>()

const emit = defineEmits<{
  select: [id: number]
}>()

const el = useTemplateRef<HTMLElement>('el')

const { isDragOver, isAllowed } = makeDroppable(
  el,
  {
    disabled: computed(() => props.disabled === true),
    data: () => ({ status: props.status }),
    events: {
      onDrop: event => props.onDropTask(
        event as IDragEvent<WorkTaskSummary, { status: WorkKanbanColumnStatus }>
      )
    }
  },
  () => props.items
)
</script>

<template>
  <section
    ref="el"
    class="flex w-72 shrink-0 flex-col rounded-lg border border-default bg-elevated/40"
    :class="[
      isDragOver && isAllowed ? 'ring-2 ring-primary/50' : '',
      isDragOver && !isAllowed ? 'ring-2 ring-error/40' : ''
    ]"
    data-testid="work-kanban-column"
    :data-status="status"
    :aria-label="`Coluna ${taskStatusLabel(status)}`"
  >
    <header class="flex items-center gap-2 border-b border-default px-3 py-2.5">
      <UBadge
        size="sm"
        variant="subtle"
        :color="taskStatusColor(status)"
        :icon="taskStatusIcon(status)"
        :label="taskStatusLabel(status)"
      />
      <UBadge
        size="sm"
        variant="soft"
        color="neutral"
        :label="String(items.length)"
        class="ml-auto"
      />
    </header>

    <div class="flex min-h-40 flex-1 flex-col gap-2 overflow-y-auto p-2">
      <WorkKanbanCard
        v-for="item in items"
        :key="item.id"
        :item="item"
        :column-status="status"
        :column-items="items"
        :selected="selectedTaskId === item.id"
        :disabled="disabled"
        @select="emit('select', $event)"
      />
      <p
        v-if="!items.length"
        class="px-2 py-6 text-center text-xs text-muted"
      >
        Nenhuma tarefa
      </p>
    </div>
  </section>
</template>
