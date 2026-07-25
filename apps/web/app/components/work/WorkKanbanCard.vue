<script setup lang="ts">
/**
 * Card arrastável do board Kanban de tarefas.
 * DnD via makeDraggable (filho de DnDProvider no board).
 */
import type { OperationalTaskSummary } from '~/types/work'
import {
  formatDueDate,
  highestRiskColor,
  taskStatusColor,
  taskStatusIcon,
  taskStatusLabel,
  workRiskLabel
} from '~/utils/work-labels'
import type { WorkKanbanColumnStatus } from '~/utils/work-kanban-transition'

const props = defineProps<{
  item: OperationalTaskSummary
  columnStatus: WorkKanbanColumnStatus
  /** Array mutável da coluna — payload do vue-dnd-kit. */
  columnItems: OperationalTaskSummary[]
  selected?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  select: [id: number]
}>()

const el = useTemplateRef<HTMLElement>('el')
const didDrag = ref(false)

const index = computed(() =>
  props.columnItems.findIndex(task => task.id === props.item.id)
)

const { isDragging } = makeDraggable(
  el,
  {
    id: `work-kanban-card-${props.item.id}`,
    disabled: computed(() => props.disabled === true),
    activation: { distance: 6 },
    data: () => ({
      taskId: props.item.id,
      status: props.columnStatus,
      lockVersion: props.item.lock_version
    }),
    events: {
      onSelfDragStart: () => {
        didDrag.value = true
      },
      onSelfDragCancel: () => {
        didDrag.value = false
      },
      onSelfDragEnd: () => {
        // Clique após drag curto: ignorar select
        window.setTimeout(() => {
          didDrag.value = false
        }, 0)
      }
    }
  },
  () => [Math.max(0, index.value), props.columnItems]
)

function onActivate() {
  if (didDrag.value || isDragging.value) return
  emit('select', props.item.id)
}
</script>

<template>
  <div
    ref="el"
    role="button"
    tabindex="0"
    class="cursor-grab rounded-md border border-default bg-default p-3 text-sm shadow-xs transition-colors active:cursor-grabbing"
    :class="[
      selected ? 'ring-2 ring-primary' : 'hover:border-primary/40',
      isDragging ? 'opacity-60' : '',
      disabled ? 'cursor-not-allowed opacity-70' : ''
    ]"
    data-testid="work-kanban-card"
    :aria-label="`Tarefa ${item.title}`"
    @click="onActivate"
    @keydown.enter.prevent="onActivate"
    @keydown.space.prevent="onActivate"
  >
    <div class="flex items-start justify-between gap-2">
      <p class="min-w-0 font-medium text-highlighted line-clamp-2">
        {{ item.title }}
      </p>
      <UChip
        v-if="item.is_critical"
        color="warning"
        size="sm"
        class="shrink-0"
      />
    </div>
    <p class="mt-1 truncate text-xs text-toned">
      {{ item.process?.client?.name || 'Sem cliente' }}
      <span v-if="item.process?.title"> · {{ item.process.title }}</span>
    </p>
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
      <UBadge
        size="sm"
        variant="subtle"
        :color="taskStatusColor(item.status)"
        :icon="taskStatusIcon(item.status)"
        :label="taskStatusLabel(item.status)"
      />
      <UBadge
        v-if="item.risks?.[0]"
        size="sm"
        variant="subtle"
        :color="highestRiskColor(item.risks)"
        :label="workRiskLabel(item.risks[0])"
      />
      <span class="ml-auto shrink-0 text-xs text-muted tabular-nums">
        {{ formatDueDate(item.effective_due_date || item.due_date) }}
      </span>
    </div>
    <p
      v-if="item.assignee?.name"
      class="mt-1 truncate text-xs text-muted"
    >
      {{ item.assignee.name }}
    </p>
    <p
      v-else
      class="mt-1 truncate text-xs text-warning"
    >
      Sem responsável
    </p>
  </div>
</template>
