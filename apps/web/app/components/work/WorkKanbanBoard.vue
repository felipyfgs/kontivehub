<script setup lang="ts">
/**
 * Board Kanban da fila de tarefas — colunas por status + DnD → mutações HTTP.
 */
import type { OperationalTaskSummary } from '~/types/work'
import type { IDragEvent } from '@vue-dnd-kit/core'
import { apiErrorMessage } from '~/utils/api-error'
import {
  WORK_KANBAN_COLUMNS,
  actionForKanbanDrop,
  completeKanbanDnDDrop,
  groupTasksForKanbanBoard,
  isKanbanBoardTruncated,
  isWorkKanbanColumnStatus,
  kanbanTruncationMessage,
  type WorkKanbanBoardColumns,
  type WorkKanbanColumnStatus,
  type WorkKanbanTransitionAction
} from '~/utils/work-kanban-transition'

const props = defineProps<{
  items: OperationalTaskSummary[]
  total: number
  loading?: boolean
  error?: string | null
  selectedTaskId?: number | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  select: [id: number]
  refreshed: []
  retry: []
}>()

const api = useApi()
const toast = useToast()

const INVALID_DROP_MESSAGE = 'Essa mudança de status não é permitida no quadro.'

const columnItems = reactive<WorkKanbanBoardColumns>({
  A_FAZER: [],
  EM_PROGRESSO: [],
  IMPEDIDA: [],
  CONCLUIDA: []
})

const pending = ref(false)

const blockOpen = ref(false)
const blockReason = ref('')
const reopenOpen = ref(false)
const reopenJustification = ref('')

type PendingDrop = {
  task: OperationalTaskSummary
  from: WorkKanbanColumnStatus
  to: WorkKanbanColumnStatus
  action: WorkKanbanTransitionAction
  snapshot: WorkKanbanBoardColumns
  resolve: (ok: boolean) => void
}

const pendingDrop = ref<PendingDrop | null>(null)

const showTruncationBanner = computed(() =>
  isKanbanBoardTruncated(props.total, props.items.length)
)

const truncationText = computed(() =>
  kanbanTruncationMessage(props.total, props.items.length)
)

function cloneBoard(source: WorkKanbanBoardColumns): WorkKanbanBoardColumns {
  return {
    A_FAZER: source.A_FAZER.map(item => ({ ...item })),
    EM_PROGRESSO: source.EM_PROGRESSO.map(item => ({ ...item })),
    IMPEDIDA: source.IMPEDIDA.map(item => ({ ...item })),
    CONCLUIDA: source.CONCLUIDA.map(item => ({ ...item }))
  }
}

function syncColumns(items: OperationalTaskSummary[]) {
  const grouped = groupTasksForKanbanBoard(items)
  for (const status of WORK_KANBAN_COLUMNS) {
    columnItems[status] = grouped[status].map(item => ({ ...item }))
  }
}

watch(
  () => props.items,
  (items) => {
    if (pending.value || pendingDrop.value) return
    syncColumns(items)
  },
  { immediate: true, deep: true }
)

watch(blockOpen, (open) => {
  if (!open && pendingDrop.value?.action === 'block' && !pending.value) {
    cancelPendingModal()
  }
})

watch(reopenOpen, (open) => {
  if (!open && pendingDrop.value?.action === 'reopen' && !pending.value) {
    cancelPendingModal()
  }
})

function findTaskLocation(taskId: number): {
  status: WorkKanbanColumnStatus
  index: number
  task: OperationalTaskSummary
} | null {
  for (const status of WORK_KANBAN_COLUMNS) {
    const index = columnItems[status].findIndex(task => task.id === taskId)
    if (index >= 0) {
      return { status, index, task: columnItems[status][index]! }
    }
  }
  return null
}

function moveTaskOptimistic(
  taskId: number,
  to: WorkKanbanColumnStatus
): { from: WorkKanbanColumnStatus, task: OperationalTaskSummary } | null {
  const located = findTaskLocation(taskId)
  if (!located) return null
  const [removed] = columnItems[located.status].splice(located.index, 1)
  if (!removed) return null
  const moved = { ...removed, status: to }
  columnItems[to].unshift(moved)
  return { from: located.status, task: moved }
}

function restoreBoard(snapshot: WorkKanbanBoardColumns) {
  for (const status of WORK_KANBAN_COLUMNS) {
    columnItems[status] = snapshot[status].map(item => ({ ...item }))
  }
}

async function persistTransition(
  task: OperationalTaskSummary,
  action: WorkKanbanTransitionAction,
  reason?: string
) {
  const lock = task.lock_version
  if (action === 'start') await api.work.tasks.start(task.id, lock)
  else if (action === 'resume') await api.work.tasks.resume(task.id, lock)
  else if (action === 'complete') await api.work.tasks.complete(task.id, lock)
  else if (action === 'block') await api.work.tasks.block(task.id, lock, reason || '')
  else if (action === 'reopen') await api.work.tasks.reopen(task.id, lock, reason || '')
}

/** Encerra Promise do onDrop com `true` (vue-dnd-kit: false deixa o drag preso). */
function finishPending() {
  const current = pendingDrop.value
  pendingDrop.value = null
  blockOpen.value = false
  blockReason.value = ''
  reopenOpen.value = false
  reopenJustification.value = ''
  current?.resolve(completeKanbanDnDDrop())
}

async function runPersistedDrop(drop: PendingDrop, reason?: string) {
  pending.value = true
  try {
    await persistTransition(drop.task, drop.action, reason)
    toast.add({ title: 'Status atualizado', color: 'success' })
    finishPending()
    emit('refreshed')
  } catch (e: unknown) {
    restoreBoard(drop.snapshot)
    toast.add({
      title: apiErrorMessage(e, 'Não foi possível atualizar o status.'),
      color: 'error'
    })
    finishPending()
    emit('refreshed')
  } finally {
    pending.value = false
  }
}

function confirmBlock() {
  const drop = pendingDrop.value
  if (!drop || drop.action !== 'block') return
  if (!blockReason.value.trim()) {
    toast.add({ title: 'Informe o motivo do impedimento.', color: 'warning' })
    return
  }
  void runPersistedDrop(drop, blockReason.value.trim())
}

function confirmReopen() {
  const drop = pendingDrop.value
  if (!drop || drop.action !== 'reopen') return
  if (!reopenJustification.value.trim()) {
    toast.add({ title: 'Informe a justificativa de reabertura.', color: 'warning' })
    return
  }
  void runPersistedDrop(drop, reopenJustification.value.trim())
}

function cancelPendingModal() {
  const drop = pendingDrop.value
  if (!drop) return
  restoreBoard(drop.snapshot)
  finishPending()
}

async function onColumnDrop(
  event: IDragEvent<OperationalTaskSummary, { status: WorkKanbanColumnStatus }>
): Promise<boolean> {
  const dragged = event.draggedItems[0]
  const toStatus = (event.dropZone?.data as { status?: WorkKanbanColumnStatus } | undefined)?.status
  if (!dragged?.item || !toStatus || !isWorkKanbanColumnStatus(toStatus)) {
    return completeKanbanDnDDrop()
  }

  const task = dragged.item
  const fromRaw = (dragged.data as { status?: string } | undefined)?.status || task.status
  if (!isWorkKanbanColumnStatus(fromRaw)) {
    toast.add({ title: INVALID_DROP_MESSAGE, color: 'error' })
    return completeKanbanDnDDrop()
  }

  const decision = actionForKanbanDrop(fromRaw, toStatus)
  if (decision.kind === 'noop') {
    return completeKanbanDnDDrop()
  }
  if (decision.kind === 'invalid') {
    toast.add({ title: decision.message, color: 'error' })
    return completeKanbanDnDDrop()
  }

  if (props.disabled || pending.value) {
    toast.add({ title: 'Você não pode alterar o status desta tarefa.', color: 'warning' })
    return completeKanbanDnDDrop()
  }

  const snapshot = cloneBoard(columnItems)
  const moved = moveTaskOptimistic(task.id, toStatus)
  if (!moved) {
    toast.add({ title: 'Não foi possível mover a tarefa no quadro.', color: 'error' })
    return completeKanbanDnDDrop()
  }

  const drop: PendingDrop = {
    task: moved.task,
    from: moved.from,
    to: toStatus,
    action: decision.action,
    snapshot,
    resolve: () => undefined
  }

  if (decision.requiresReason) {
    return await new Promise<boolean>((resolve) => {
      drop.resolve = resolve
      pendingDrop.value = drop
      if (decision.action === 'block') {
        blockReason.value = ''
        blockOpen.value = true
      } else {
        reopenJustification.value = ''
        reopenOpen.value = true
      }
    })
  }

  pendingDrop.value = drop
  await runPersistedDrop(drop)
  return completeKanbanDnDDrop()
}
</script>

<template>
  <div
    data-testid="work-kanban-board"
    class="flex min-h-0 flex-1 flex-col gap-3"
  >
    <UAlert
      v-if="showTruncationBanner"
      color="warning"
      variant="subtle"
      icon="i-lucide-info"
      :title="truncationText"
      data-testid="work-kanban-truncation-banner"
    />

    <div v-if="error" class="p-1">
      <UAlert color="error" :title="error">
        <template #actions>
          <UButton
            size="xs"
            variant="soft"
            label="Tentar de novo"
            @click="emit('retry')"
          />
        </template>
      </UAlert>
    </div>

    <div
      v-else-if="loading && !items.length"
      class="flex gap-3 overflow-x-auto pb-2"
    >
      <USkeleton
        v-for="i in 4"
        :key="i"
        class="h-64 w-72 shrink-0 rounded-lg"
      />
    </div>

    <DnDProvider v-else>
      <div
        class="flex min-h-0 flex-1 gap-3 overflow-x-auto pb-2"
        data-testid="work-kanban-columns"
      >
        <WorkKanbanColumn
          v-for="status in WORK_KANBAN_COLUMNS"
          :key="status"
          :status="status"
          :items="columnItems[status]"
          :selected-task-id="selectedTaskId"
          :disabled="disabled || pending"
          :on-drop-task="onColumnDrop"
          @select="emit('select', $event)"
        />
      </div>
      <DragPreview />
    </DnDProvider>

    <ShellFormModal
      v-model:open="blockOpen"
      title="Impedir tarefa"
      description="Informe o motivo do impedimento."
      submit-label="Impedir"
      submit-color="warning"
      :loading="pending"
      :disabled="!blockReason.trim()"
      test-id="work-kanban-block-modal"
      @submit="confirmBlock"
      @cancel="cancelPendingModal"
    >
      <template #body>
        <UFormField label="Motivo" required>
          <UTextarea
            v-model="blockReason"
            :rows="3"
            maxlength="2000"
            placeholder="Descreva o impedimento…"
            data-testid="work-kanban-block-reason"
          />
        </UFormField>
      </template>
    </ShellFormModal>

    <ShellFormModal
      v-model:open="reopenOpen"
      title="Reabrir tarefa"
      description="Confirme a reabertura e informe a justificativa."
      submit-label="Reabrir"
      submit-color="primary"
      :loading="pending"
      :disabled="!reopenJustification.trim()"
      test-id="work-kanban-reopen-modal"
      @submit="confirmReopen"
      @cancel="cancelPendingModal"
    >
      <template #body>
        <UFormField label="Justificativa" required>
          <UTextarea
            v-model="reopenJustification"
            :rows="3"
            maxlength="2000"
            placeholder="Descreva o motivo da reabertura…"
            data-testid="work-kanban-reopen-justification"
          />
        </UFormField>
      </template>
    </ShellFormModal>
  </div>
</template>
