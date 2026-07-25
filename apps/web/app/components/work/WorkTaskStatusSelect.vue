<script setup lang="ts">
/**
 * Select compacto de transição rápida de status da tarefa (listas Work).
 * Impedir / evidência / comentário → detalhe `/work/tasks/:id`.
 * Não acionável sem `canExecuteWorkTasks` (effective_permissions / fallback).
 */
import type { TaskStatus } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import { canExecuteWorkTasks } from '~/utils/permissions'
import {
  workTaskStatusOptions,
  type WorkTaskInlineAction
} from '~/utils/work-task-status-options'
import {
  taskStatusColor,
  taskStatusIcon,
  taskStatusLabel
} from '~/utils/work-labels'

const props = defineProps<{
  taskId: number
  status: TaskStatus
  lockVersion: number
  /** Sem responsável → ofereceere claim quando A_FAZER. */
  canClaim?: boolean
  /** Quando true, oculta "Concluir" (fluxo de evidência no detalhe). */
  requiresEvidence?: boolean
  disabled?: boolean
  size?: 'xs' | 'sm' | 'md'
}>()

const emit = defineEmits<{
  updated: []
}>()

const api = useApi()
const toast = useToast()
const { me } = useDashboard()

const loading = ref(false)
const canExecute = computed(() => canExecuteWorkTasks(me.value))

const options = computed(() => workTaskStatusOptions(props.status, {
  canClaim: props.canClaim === true,
  requiresEvidence: props.requiresEvidence === true
}))

const selectItems = computed(() =>
  options.value.map(option => ({
    label: option.label,
    value: option.value
  }))
)

const isDisabled = computed(() =>
  props.disabled === true
  || !canExecute.value
  || loading.value
  || !selectItems.value.length
)

const currentLabel = computed(() => taskStatusLabel(props.status))
const currentColor = computed(() => taskStatusColor(props.status))
const currentIcon = computed(() => taskStatusIcon(props.status))
/** Remount após mudança de status para não reter chave de ação no trigger. */
const selectKey = computed(() => `${props.taskId}-${props.status}-${props.requiresEvidence ? 'ev' : 'ok'}`)

async function runAction(action: WorkTaskInlineAction) {
  if (!canExecute.value) return
  loading.value = true
  try {
    const lock = props.lockVersion
    if (action === 'start') await api.work.tasks.start(props.taskId, lock)
    else if (action === 'complete') await api.work.tasks.complete(props.taskId, lock)
    else if (action === 'resume') await api.work.tasks.resume(props.taskId, lock)
    else if (action === 'claim') await api.work.tasks.claim(props.taskId, lock)
    toast.add({ title: 'Status atualizado', color: 'success' })
    emit('updated')
  } catch (e: unknown) {
    const statusCode = (e as { statusCode?: number, status?: number })?.statusCode
      ?? (e as { status?: number })?.status
    toast.add({
      title: apiErrorMessage(e, 'Não foi possível atualizar o status.'),
      color: 'error'
    })
    if (statusCode === 409) {
      emit('updated')
    }
  } finally {
    loading.value = false
  }
}

function onSelect(value: string | undefined | null) {
  if (!value || isDisabled.value) return
  void runAction(value as WorkTaskInlineAction)
}
</script>

<template>
  <div class="inline-flex min-w-0 items-center gap-1" data-testid="work-task-status-select">
    <USelect
      :key="selectKey"
      :model-value="undefined"
      :items="selectItems"
      :placeholder="currentLabel"
      :color="currentColor"
      :icon="currentIcon"
      variant="subtle"
      highlight
      :disabled="isDisabled"
      :loading="loading"
      :size="size || 'xs'"
      class="min-w-36"
      aria-label="Alterar status da tarefa"
      @update:model-value="onSelect"
    />
  </div>
</template>
