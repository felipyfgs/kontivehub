<script setup lang="ts">
/**
 * Modal de ações em massa para Processos e/ou Tarefas (listas Work).
 * Padrão visual: AssignCategoriesModal / SaveFilterModal / ShellFormModal.
 */
import type { TenantMember } from '~/types/api'
import type { WorkDepartment } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import { initialWorkBulkScope } from '~/utils/work-bulk-actions'

export type WorkBulkScope = 'tasks' | 'processes'

export interface WorkBulkItem {
  id: number
  lock_version: number
  label: string
}

type TaskBulkAction
  = 'start'
    | 'complete'
    | 'resume'
    | 'block'
    | 'claim'
    | 'assign'
    | 'set_due_date'
    | 'set_department'

type ProcessBulkAction = 'archive' | 'assign' | 'set_due_date' | 'set_department'

type WorkBulkChanges<TAction extends TaskBulkAction | ProcessBulkAction> = {
  action: TAction
  reason?: string
  assignee_membership_id?: number
  due_date?: string
  work_department_id?: number
} & Record<string, unknown>

const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  processes?: WorkBulkItem[]
  tasks?: WorkBulkItem[]
  canExecuteTasks?: boolean
  canAdminister?: boolean
  canUpdateProcesses?: boolean
}>(), {
  processes: () => [],
  tasks: () => [],
  canExecuteTasks: false,
  canAdminister: false,
  canUpdateProcesses: false
})

const emit = defineEmits<{
  done: []
}>()

const api = useApi()
const toast = useToast()

const submitting = ref(false)
const scope = ref<WorkBulkScope>('tasks')
const taskAction = ref<TaskBulkAction>('start')
const processAction = ref<ProcessBulkAction>('archive')
const reason = ref('')
const dueDate = ref('')
const assigneeMembershipId = ref<number | undefined>(undefined)
const departmentId = ref<number | undefined>(undefined)
const members = ref<TenantMember[]>([])
const departments = ref<WorkDepartment[]>([])

const processCount = computed(() => props.processes.length)
const taskCount = computed(() => props.tasks.length)

const scopeItems = computed(() => {
  const items: Array<{ label: string, value: WorkBulkScope, description: string }> = []
  if (taskCount.value) {
    items.push({
      label: 'Tarefas',
      value: 'tasks',
      description: `${taskCount.value} selecionada(s)`
    })
  }
  if (processCount.value) {
    items.push({
      label: 'Processos',
      value: 'processes',
      description: `${processCount.value} selecionado(s)`
    })
  }
  return items
})

const taskActionItems = computed(() => {
  const items: Array<{ label: string, value: TaskBulkAction, icon: string }> = []
  if (props.canExecuteTasks) {
    items.push(
      { label: 'Iniciar', value: 'start', icon: 'i-lucide-play' },
      { label: 'Concluir', value: 'complete', icon: 'i-lucide-check-check' },
      { label: 'Retomar', value: 'resume', icon: 'i-lucide-rotate-ccw' },
      { label: 'Impedir', value: 'block', icon: 'i-lucide-octagon-pause' },
      { label: 'Assumir', value: 'claim', icon: 'i-lucide-hand' }
    )
  }
  if (props.canAdminister) {
    items.push(
      { label: 'Atribuir responsável', value: 'assign', icon: 'i-lucide-user-round-pen' },
      { label: 'Definir prazo', value: 'set_due_date', icon: 'i-lucide-calendar-clock' },
      { label: 'Definir departamento', value: 'set_department', icon: 'i-lucide-building-2' }
    )
  }
  return items
})

const canManageProcessAttrs = computed(() => props.canAdminister || props.canUpdateProcesses)

const processActionItems = computed(() => {
  const items: Array<{ label: string, value: ProcessBulkAction, icon: string }> = []
  if (canManageProcessAttrs.value) {
    items.push(
      { label: 'Atribuir responsável', value: 'assign', icon: 'i-lucide-user-round-pen' },
      { label: 'Definir prazo', value: 'set_due_date', icon: 'i-lucide-calendar-clock' },
      { label: 'Definir departamento', value: 'set_department', icon: 'i-lucide-building-2' }
    )
  }
  if (props.canAdminister) {
    items.push({ label: 'Arquivar', value: 'archive', icon: 'i-lucide-archive' })
  }
  return items
})

const memberItems = computed(() =>
  members.value
    .filter(member => member.is_active)
    .map(member => ({
      label: member.name || member.email || `Membro #${member.id}`,
      value: member.id
    }))
)

const departmentItems = computed(() =>
  departments.value.map(department => ({
    label: department.name,
    value: department.id
  }))
)

const needsReason = computed(() =>
  scope.value === 'tasks' && taskAction.value === 'block'
)

const needsAssignee = computed(() =>
  (scope.value === 'tasks' && taskAction.value === 'assign')
  || (scope.value === 'processes' && processAction.value === 'assign')
)

const needsDueDate = computed(() =>
  (scope.value === 'tasks' && taskAction.value === 'set_due_date')
  || (scope.value === 'processes' && processAction.value === 'set_due_date')
)

const needsDepartment = computed(() =>
  (scope.value === 'tasks' && taskAction.value === 'set_department')
  || (scope.value === 'processes' && processAction.value === 'set_department')
)

const activeItems = computed(() =>
  scope.value === 'tasks' ? props.tasks : props.processes
)

const previewItems = computed(() => activeItems.value.slice(0, 12))

const canSubmit = computed(() => {
  if (submitting.value) return false
  if (scope.value === 'processes') {
    if (!processCount.value || !processActionItems.value.length) return false
    if (needsAssignee.value && !assigneeMembershipId.value) return false
    if (needsDueDate.value && !dueDate.value) return false
    if (needsDepartment.value && !departmentId.value) return false
    return true
  }
  if (!taskCount.value || !taskActionItems.value.length) return false
  if (needsReason.value && !reason.value.trim()) return false
  if (needsAssignee.value && !assigneeMembershipId.value) return false
  if (needsDueDate.value && !dueDate.value) return false
  if (needsDepartment.value && !departmentId.value) return false
  return true
})

const description = computed(() => {
  const parts: string[] = []
  if (taskCount.value) parts.push(`${taskCount.value} tarefa(s)`)
  if (processCount.value) parts.push(`${processCount.value} processo(s)`)
  return parts.length
    ? `${parts.join(' · ')} selecionado(s).`
    : 'Nenhum item selecionado.'
})

const submitLabel = computed(() => {
  if (scope.value === 'processes') {
    return processActionItems.value.find(item => item.value === processAction.value)?.label || 'Aplicar'
  }
  const match = taskActionItems.value.find(item => item.value === taskAction.value)
  return match?.label || 'Aplicar'
})

const submitIcon = computed(() => {
  if (scope.value === 'processes') {
    return processActionItems.value.find(item => item.value === processAction.value)?.icon
      || 'i-lucide-check'
  }
  return taskActionItems.value.find(item => item.value === taskAction.value)?.icon
    || 'i-lucide-check'
})

const submitColor = computed(() => {
  if (scope.value === 'processes' && processAction.value === 'archive') return 'warning'
  if (scope.value === 'tasks' && taskAction.value === 'block') return 'warning'
  return 'primary'
})

const actionAlert = computed(() => {
  if (scope.value === 'processes' && processAction.value === 'archive') {
    return {
      color: 'warning' as const,
      icon: 'i-lucide-archive',
      title: 'Arquivar processos',
      description: 'Remove os processos da lista ativa. Status do processo continua derivado das tarefas — use o escopo Tarefas para iniciar, concluir ou impedir.'
    }
  }
  if (scope.value === 'processes') {
    return {
      color: 'info' as const,
      icon: 'i-lucide-info',
      title: 'Gestão do processo',
      description: 'Atribuição, departamento e prazo atualizam o processo. O status (a fazer / em progresso / concluído) continua calculado a partir das tarefas.'
    }
  }
  if (scope.value === 'tasks' && taskAction.value === 'block') {
    return {
      color: 'warning' as const,
      icon: 'i-lucide-octagon-pause',
      title: 'Impedir tarefas',
      description: 'Informe o motivo. A ação será aplicada item a item com a política de cada tarefa.'
    }
  }
  if (scope.value === 'tasks' && taskAction.value === 'complete') {
    return {
      color: 'info' as const,
      icon: 'i-lucide-info',
      title: 'Conclusão parcial possível',
      description: 'Tarefas que exigem evidência e ainda não têm arquivo falham sem impedir as demais.'
    }
  }
  return null
})

watch(open, async (isOpen) => {
  if (!isOpen) return
  scope.value = initialWorkBulkScope({
    taskCount: taskCount.value,
    processCount: processCount.value,
    canExecuteTasks: props.canExecuteTasks,
    taskActionCount: taskActionItems.value.length,
    processActionCount: processActionItems.value.length
  })
  taskAction.value = taskActionItems.value[0]?.value ?? 'start'
  processAction.value = processActionItems.value[0]?.value ?? 'archive'
  reason.value = ''
  dueDate.value = ''
  assigneeMembershipId.value = undefined
  departmentId.value = undefined

  if (props.canAdminister || props.canUpdateProcesses) {
    try {
      const [membersRes, departmentsRes] = await Promise.all([
        api.tenant.members.list(),
        api.work.departments.list({ per_page: 100, is_active: true })
      ])
      members.value = Array.isArray(membersRes?.data) ? membersRes.data : []
      departments.value = Array.isArray(departmentsRes?.data) ? departmentsRes.data : []
    } catch {
      members.value = []
      departments.value = []
    }
  }
})

watch(processActionItems, (items) => {
  if (!items.some(item => item.value === processAction.value) && items[0]) {
    processAction.value = items[0].value
  }
})

watch(taskActionItems, (items) => {
  if (!items.some(item => item.value === taskAction.value) && items[0]) {
    taskAction.value = items[0].value
  }
})

watch(scopeItems, (items) => {
  if (!items.some(item => item.value === scope.value) && items[0]) {
    scope.value = items[0].value
  }
})

function toastBulkResult(succeeded: number, failed: Array<{ id: number, message: string }>) {
  if (succeeded > 0 && failed.length === 0) {
    toast.add({ title: `${succeeded} item(ns) atualizado(s)`, color: 'success' })
    return
  }
  if (succeeded > 0 && failed.length > 0) {
    toast.add({
      title: `${succeeded} ok · ${failed.length} falha(s)`,
      description: failed.slice(0, 3).map(item => `#${item.id}: ${item.message}`).join(' · '),
      color: 'warning'
    })
    return
  }
  toast.add({
    title: 'Nenhum item atualizado',
    description: failed[0]?.message,
    color: 'error'
  })
}

function buildBulkChanges<TAction extends TaskBulkAction | ProcessBulkAction>(
  action: TAction
): WorkBulkChanges<TAction> {
  const changes: WorkBulkChanges<TAction> = { action }
  if (needsReason.value) changes.reason = reason.value.trim()
  if (needsAssignee.value) changes.assignee_membership_id = assigneeMembershipId.value
  if (needsDueDate.value) changes.due_date = dueDate.value
  if (needsDepartment.value) changes.work_department_id = departmentId.value
  return changes
}

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  try {
    if (scope.value === 'processes') {
      const response = await api.work.processes.bulk({
        items: props.processes.map(item => ({
          id: item.id,
          lock_version: item.lock_version
        })),
        changes: buildBulkChanges(processAction.value)
      })
      toastBulkResult(response.meta.succeeded, response.meta.failed)
    } else {
      const response = await api.work.tasks.bulk({
        items: props.tasks.map(item => ({
          id: item.id,
          lock_version: item.lock_version
        })),
        changes: buildBulkChanges(taskAction.value)
      })
      toastBulkResult(response.meta.succeeded, response.meta.failed)
    }
    open.value = false
    emit('done')
  } catch (e) {
    toast.add({
      title: apiErrorMessage(e, 'Falha na ação em massa.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Ações em massa"
    :description="description"
    content-class="sm:max-w-lg"
    :submit-label="submitLabel"
    :submit-icon="submitIcon"
    :submit-color="submitColor"
    :loading="submitting"
    :disabled="!canSubmit"
    test-id="work-bulk-actions-modal"
    @cancel="() => { open = false }"
    @submit="submit"
  >
    <template #body>
      <div class="space-y-4 text-sm">
        <div class="flex flex-wrap items-center gap-1.5">
          <UBadge
            v-if="taskCount"
            color="primary"
            variant="subtle"
            icon="i-lucide-list-todo"
            :label="`${taskCount} tarefa(s)`"
          />
          <UBadge
            v-if="processCount"
            color="neutral"
            variant="subtle"
            icon="i-lucide-folder-kanban"
            :label="`${processCount} processo(s)`"
          />
        </div>

        <UFormField
          v-if="scopeItems.length > 1"
          label="Aplicar em"
          description="Escolha se a ação vale para tarefas ou processos da seleção."
        >
          <URadioGroup
            v-model="scope"
            :items="scopeItems"
            class="w-full"
            data-testid="work-bulk-scope"
          />
        </UFormField>

        <UFormField
          v-if="scope === 'tasks'"
          label="Ação"
          required
        >
          <USelect
            v-model="taskAction"
            :items="taskActionItems"
            value-key="value"
            class="w-full"
            data-testid="work-bulk-task-action"
          />
        </UFormField>

        <UFormField
          v-else
          label="Ação"
          required
        >
          <USelect
            v-model="processAction"
            :items="processActionItems"
            value-key="value"
            class="w-full"
            data-testid="work-bulk-process-action"
          />
        </UFormField>

        <UAlert
          v-if="actionAlert"
          :color="actionAlert.color"
          variant="subtle"
          :icon="actionAlert.icon"
          :title="actionAlert.title"
          :description="actionAlert.description"
        />

        <UFormField
          v-if="needsReason"
          label="Motivo"
          required
          description="Obrigatório para impedir tarefas."
        >
          <UTextarea
            v-model="reason"
            :rows="3"
            autoresize
            :maxrows="6"
            maxlength="2000"
            placeholder="Descreva o impedimento…"
            class="w-full"
            data-testid="work-bulk-reason"
          />
        </UFormField>

        <UFormField
          v-if="needsAssignee"
          label="Responsável"
          required
        >
          <USelectMenu
            v-model="assigneeMembershipId"
            :items="memberItems"
            value-key="value"
            class="w-full"
            placeholder="Selecionar membro"
            :search-input="{ placeholder: 'Buscar membro…', icon: 'i-lucide-search' }"
            data-testid="work-bulk-assignee"
          />
        </UFormField>

        <UFormField
          v-if="needsDueDate"
          label="Prazo"
          required
        >
          <UInput
            v-model="dueDate"
            type="date"
            class="w-full"
            data-testid="work-bulk-due-date"
          />
        </UFormField>

        <UFormField
          v-if="needsDepartment"
          label="Departamento"
          required
        >
          <USelect
            v-model="departmentId"
            :items="departmentItems"
            value-key="value"
            class="w-full"
            placeholder="Selecionar departamento"
            data-testid="work-bulk-department"
          />
        </UFormField>

        <div
          v-if="activeItems.length"
          class="overflow-hidden rounded-lg border border-default"
        >
          <div class="flex items-center justify-between gap-2 border-b border-default bg-elevated/50 px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
              <UIcon
                :name="scope === 'tasks' ? 'i-lucide-list-todo' : 'i-lucide-folder-kanban'"
                class="size-4 shrink-0 text-muted"
              />
              <p class="truncate text-sm font-medium text-highlighted">
                {{ scope === 'tasks' ? 'Tarefas na seleção' : 'Processos na seleção' }}
              </p>
            </div>
            <UBadge
              color="neutral"
              variant="subtle"
              :label="String(activeItems.length)"
            />
          </div>
          <ul class="max-h-44 divide-y divide-default overflow-y-auto">
            <li
              v-for="item in previewItems"
              :key="`${scope}-${item.id}`"
              class="truncate px-3 py-2 text-sm text-toned"
              :title="item.label"
            >
              {{ item.label }}
            </li>
          </ul>
          <p
            v-if="activeItems.length > previewItems.length"
            class="border-t border-default px-3 py-2 text-xs text-muted"
          >
            … e mais {{ activeItems.length - previewItems.length }}
          </p>
        </div>

        <UAlert
          v-else
          color="neutral"
          variant="subtle"
          icon="i-lucide-list-checks"
          title="Nenhum item neste escopo"
          description="Ajuste a seleção na lista ou troque o escopo da ação."
        />
      </div>
    </template>
  </ShellFormModal>
</template>
