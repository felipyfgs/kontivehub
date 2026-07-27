<script setup lang="ts">
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import type { ClientCategory } from '~/types/api'
import type {
  GenerationBatch,
  ProcessAudienceRules,
  WorkProcessTemplate,
  WorkProcessTemplateCatalogItem,
  WorkProcessTemplateTask,
  RecurrenceFrequency,
  RecurrencePeriodOffset,
  WorkDepartment,
  WorkMonitoringModuleKey
} from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import { truncateText } from '~/utils/format'
import { canCreateWorkProcesses, canManageWorkCatalog } from '~/utils/permissions'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'
import { sortHeader } from '~/utils/table-sort'
import {
  buildGenerationSelection,
  cloneProcessAudienceRules,
  emptyProcessAudienceRules,
  generationItemClientLabel,
  generationItemClientMeta,
  monitoringModuleLabel,
  WORK_MONITORING_MODULES,
  WORK_TAX_REGIMES
} from '~/utils/work-orchestration'
import {
  RECURRENCE_FREQUENCY_ITEMS,
  RECURRENCE_MAX_GENERATION_DAY,
  RECURRENCE_MIN_GENERATION_DAY,
  RECURRENCE_PERIOD_OFFSET_ITEMS,
  defaultRecurrenceConfig,
  emptyRecurrenceConfig,
  formatNextRunAt,
  recurrenceFrequencyLabel,
  recurrenceFromTemplate,
  recurrencePatchPayload,
  recurrencePeriodOffsetLabel
} from '~/utils/work-routine-recurrence'
import {
  workGenerationFormSchema,
  workTemplateFormSchema,
  type WorkGenerationFormSchema,
  type WorkTemplateFormSchema
} from '~/utils/work-routine-forms'
import { createWorkFormSubmissionGuard } from '~/utils/work-form-submission'
import ShellDataTable from '~/components/shell/DataTable.vue'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'

type ViewMode = 'library' | 'tenant'
type TenantTemplateSort = 'name' | 'is_active' | 'id'

const TENANT_TEMPLATE_SORTS = new Set<TenantTemplateSort>(['name', 'is_active', 'id'])

interface TemplateFormState {
  id: number | null
  lockVersion: number | null
  name: string
  description: string
  defaultDepartmentId: number | null
  dueDay: number
  monitoringModuleKey: WorkMonitoringModuleKey | null
  audienceRules: ProcessAudienceRules
  isActive: boolean
  recurrenceEnabled: boolean
  recurrenceFrequency: RecurrenceFrequency | null
  generationDay: number
  periodOffset: RecurrencePeriodOffset
  nextRunAt: string | null
  tasks: WorkProcessTemplateTask[]
}

const api = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const { me, sessionEpoch } = useDashboard()

const view = ref<ViewMode>(String(route.query.view) === 'tenant' ? 'tenant' : 'library')
const query = ref(String(route.query.q || ''))
const page = ref(Math.max(1, Number(route.query.page) || 1))
const perPage = ref(20)
const initialSort = String(route.query.sort || '')
const tenantSort = ref<TenantTemplateSort | null>(
  TENANT_TEMPLATE_SORTS.has(initialSort as TenantTemplateSort)
    ? initialSort as TenantTemplateSort
    : null
)
const tenantSortDirection = ref<'asc' | 'desc'>(
  String(route.query.direction) === 'desc' ? 'desc' : 'asc'
)
const total = ref(0)
const loading = ref(false)
const catalogLoading = ref(false)
const templatesError = ref<string | null>(null)
const catalogError = ref<string | null>(null)
const catalog = ref<WorkProcessTemplateCatalogItem[]>([])
const templates = ref<WorkProcessTemplate[]>([])
const departments = ref<WorkDepartment[]>([])
const categories = ref<ClientCategory[]>([])
const installingKey = ref<string | null>(null)

const editorOpen = ref(false)
const editorBusy = ref(false)
const editorSubmitLocked = ref(false)
const editorError = ref<string | null>(null)
const editorSnapshot = ref('')
const editorDiscardOpen = ref(false)
const editor = reactive<TemplateFormState>(emptyTemplateForm())

const generationOpen = ref(false)
const generationStep = ref<1 | 2 | 4>(1)
const generationTemplate = ref<WorkProcessTemplate | null>(null)
const generationCompetence = ref('')
const generationRules = ref<ProcessAudienceRules>(emptyProcessAudienceRules())
const generationIncludeIds = ref<number[]>([])
const generationExcludeIds = ref<number[]>([])
const generationBusy = ref(false)
const generationPreviewLocked = ref(false)
const generationConfirmLocked = ref(false)
const generationBatch = ref<GenerationBatch | null>(null)
const generationError = ref<string | null>(null)
const generationSnapshot = ref('')
const generationDiscardOpen = ref(false)

const generationFormState = computed(() => ({
  competence: generationCompetence.value,
  rules: generationRules.value,
  includeIds: generationIncludeIds.value,
  excludeIds: generationExcludeIds.value
}))

const editorSubmitGuard = createWorkFormSubmissionGuard(editorSubmitLocked, editorBusy)
const generationPreviewGuard = createWorkFormSubmissionGuard(
  generationPreviewLocked,
  generationBusy
)
const generationConfirmGuard = createWorkFormSubmissionGuard(
  generationConfirmLocked,
  generationBusy
)
const editorSubmitting = computed(() => editorSubmitLocked.value || editorBusy.value)
const generationSubmitting = computed(() =>
  generationPreviewLocked.value || generationConfirmLocked.value || generationBusy.value
)

const canManageCatalog = computed(() => canManageWorkCatalog(me.value))
const canGenerateProcesses = computed(() => canCreateWorkProcesses(me.value))
const canAccessRotinas = computed(() => canManageCatalog.value || canGenerateProcesses.value)

if (!canAccessRotinas.value) {
  await navigateTo('/work')
}

if (!canManageCatalog.value && view.value === 'library') {
  view.value = 'tenant'
}

const catalogColumns: TableColumn<WorkProcessTemplateCatalogItem>[] = [
  { accessorKey: 'name', header: 'Rotina', enableSorting: false },
  { accessorKey: 'department_role', header: 'Departamento', enableSorting: false },
  { accessorKey: 'tasks', header: 'Tarefas', enableSorting: false },
  { accessorKey: 'coverage', header: 'Cobertura', enableSorting: false },
  { accessorKey: 'installed', header: 'Instalação', enableSorting: false },
  { accessorKey: 'actions', header: '', enableSorting: false }
]

const tenantColumns: TableColumn<WorkProcessTemplate>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) => sortHeader('Rotina', column),
    enableSorting: true
  },
  { accessorKey: 'audience', header: 'Público padrão', enableSorting: false },
  { accessorKey: 'department', header: 'Departamento', enableSorting: false },
  { accessorKey: 'agenda', header: 'Agenda', enableSorting: false },
  {
    accessorKey: 'is_active',
    header: ({ column }) => sortHeader('Status', column),
    enableSorting: true
  },
  { accessorKey: 'tasks', header: 'Tarefas', enableSorting: false },
  { accessorKey: 'actions', header: '', enableSorting: false }
]

const departmentItems = computed(() => [
  { label: 'Sem departamento padrão', value: null },
  ...departments.value.map(department => ({ label: department.name, value: department.id }))
])

const categoryItems = computed(() => categories.value.map(category => ({
  id: category.id,
  label: category.name
})))

const filteredCatalog = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase('pt-BR')
  if (!needle) return catalog.value
  return catalog.value.filter(item => [item.name, item.description, item.department_role]
    .some(value => String(value || '').toLocaleLowerCase('pt-BR').includes(needle)))
})

const tenantSortingState = computed(() =>
  tenantSort.value
    ? [{ id: tenantSort.value, desc: tenantSortDirection.value === 'desc' }]
    : []
)

const catalogEmptyKind = computed<'empty' | 'filtered' | 'error'>(() => {
  if (catalogError.value && !catalog.value.length) return 'error'
  return query.value.trim() ? 'filtered' : 'empty'
})

const tenantEmptyKind = computed<'empty' | 'filtered' | 'error'>(() => {
  if (templatesError.value && !templates.value.length) return 'error'
  return query.value.trim() ? 'filtered' : 'empty'
})

const generationSteps = [
  { title: 'Configurar', description: 'Competência e público' },
  { title: 'Pré-visualizar', description: 'Empresas e alertas' },
  { title: 'Confirmar', description: 'Gerar processos' },
  { title: 'Acompanhar', description: 'Resultado' }
]

function emptyTask(sortOrder: number): WorkProcessTemplateTask {
  return {
    sort_order: sortOrder,
    title: '',
    due_rule_type: 'DAYS_BEFORE_PROCESS_DUE',
    due_rule_value: 0,
    default_department_id: null,
    default_assignee_membership_id: null,
    is_required: true,
    is_critical: false,
    requires_evidence: false
  }
}

function emptyTemplateForm(): TemplateFormState {
  const recurrence = emptyRecurrenceConfig()
  return {
    id: null,
    lockVersion: null,
    name: '',
    description: '',
    defaultDepartmentId: null,
    dueDay: 20,
    monitoringModuleKey: null,
    audienceRules: emptyProcessAudienceRules(),
    isActive: true,
    recurrenceEnabled: recurrence.recurrence_enabled,
    recurrenceFrequency: recurrence.recurrence_frequency,
    generationDay: recurrence.generation_day,
    periodOffset: recurrence.period_offset,
    nextRunAt: recurrence.next_run_at,
    tasks: [emptyTask(1)]
  }
}

function onRecurrenceEnabledChange(enabled: boolean | undefined): void {
  const next = enabled === true
  editor.recurrenceEnabled = next
  if (next && !editor.recurrenceFrequency) {
    const defaults = defaultRecurrenceConfig('MONTHLY')
    editor.recurrenceFrequency = defaults.recurrence_frequency
    editor.generationDay = defaults.generation_day
    editor.periodOffset = defaults.period_offset
  }
}

function agendaLabel(template: WorkProcessTemplate): string {
  if (!template.recurrence_enabled) return 'Manual'
  const frequency = recurrenceFrequencyLabel(template.recurrence_frequency)
  const offset = recurrencePeriodOffsetLabel(template.period_offset)
  return `${frequency} · dia ${template.generation_day ?? 1} · ${offset}`
}

function replaceEditor(next: TemplateFormState): void {
  Object.assign(editor, next)
}

function editorFingerprint(): string {
  return JSON.stringify(editor)
}

function generationFingerprint(): string {
  return JSON.stringify(generationFormState.value)
}

const editorDirty = computed(() =>
  editorOpen.value && editorSnapshot.value !== '' && editorFingerprint() !== editorSnapshot.value
)

const generationDirty = computed(() =>
  generationOpen.value
  && generationSnapshot.value !== ''
  && generationFingerprint() !== generationSnapshot.value
)

const editorModalOpen = computed({
  get: () => editorOpen.value,
  set: (open: boolean) => {
    if (open) {
      editorOpen.value = true
      return
    }
    requestEditorClose()
  }
})

const generationModalOpen = computed({
  get: () => generationOpen.value,
  set: (open: boolean) => {
    if (open) {
      generationOpen.value = true
      return
    }
    requestGenerationClose()
  }
})

function setView(nextView: ViewMode): void {
  if (nextView === 'library' && !canManageCatalog.value) {
    view.value = 'tenant'
    return
  }
  view.value = nextView
}

function clearQuery(): void {
  query.value = ''
  page.value = 1
}

function onQueryUpdate(value: string): void {
  query.value = value
  page.value = 1
}

function onTenantSortingUpdate(next: Array<{ id: string, desc: boolean }>): void {
  const first = next[0]
  if (!first || !TENANT_TEMPLATE_SORTS.has(first.id as TenantTemplateSort)) {
    tenantSort.value = null
    tenantSortDirection.value = 'asc'
    page.value = 1
    return
  }
  tenantSort.value = first.id as TenantTemplateSort
  tenantSortDirection.value = first.desc ? 'desc' : 'asc'
  page.value = 1
}

function closeGeneration(): void {
  generationOpen.value = false
  generationSnapshot.value = ''
}

function requestGenerationClose(): void {
  if (generationSubmitting.value) return
  if (generationDirty.value) {
    generationDiscardOpen.value = true
    return
  }
  closeGeneration()
}

function discardGeneration(): void {
  generationDiscardOpen.value = false
  closeGeneration()
}

function closeEditor(): void {
  editorOpen.value = false
  editorSnapshot.value = ''
  editorError.value = null
}

function requestEditorClose(): void {
  if (editorSubmitting.value) return
  if (editorDirty.value) {
    editorDiscardOpen.value = true
    return
  }
  closeEditor()
}

function discardEditor(): void {
  editorDiscardOpen.value = false
  closeEditor()
}

function openCreate(): void {
  if (!canManageCatalog.value) return
  replaceEditor(emptyTemplateForm())
  editorError.value = null
  editorSnapshot.value = editorFingerprint()
  editorOpen.value = true
}

function openEdit(template: WorkProcessTemplate): void {
  if (!canManageCatalog.value) return
  const recurrence = recurrenceFromTemplate(template)
  replaceEditor({
    id: template.id,
    lockVersion: template.lock_version,
    name: template.name,
    description: template.description || '',
    defaultDepartmentId: template.default_department_id || null,
    dueDay: template.default_due_rule_value ?? 20,
    monitoringModuleKey: template.monitoring_module_key || null,
    audienceRules: cloneProcessAudienceRules(template.audience_rules),
    isActive: template.is_active,
    recurrenceEnabled: recurrence.recurrence_enabled,
    recurrenceFrequency: recurrence.recurrence_frequency,
    generationDay: recurrence.generation_day,
    periodOffset: recurrence.period_offset,
    nextRunAt: recurrence.next_run_at,
    tasks: (template.tasks || []).map((task, index) => ({
      ...task,
      sort_order: index + 1,
      default_department_id: task.default_department_id || null
    }))
  })
  editorError.value = null
  editorSnapshot.value = editorFingerprint()
  editorOpen.value = true
}

function addTask(): void {
  editor.tasks.push(emptyTask(editor.tasks.length + 1))
}

function removeTask(index: number): void {
  if (editor.tasks.length <= 1) return
  editor.tasks.splice(index, 1)
  normalizeTaskOrder()
}

function moveTask(index: number, direction: -1 | 1): void {
  const target = index + direction
  if (target < 0 || target >= editor.tasks.length) return
  const [task] = editor.tasks.splice(index, 1)
  if (!task) return
  editor.tasks.splice(target, 0, task)
  normalizeTaskOrder()
}

function normalizeTaskOrder(): void {
  editor.tasks.forEach((task, index) => {
    task.sort_order = index + 1
  })
}

function templatePayload(): Record<string, unknown> {
  normalizeTaskOrder()
  return {
    name: editor.name.trim(),
    description: editor.description.trim() || null,
    default_department_id: editor.defaultDepartmentId,
    default_due_rule_type: 'FIXED_DAY_OF_COMPETENCE',
    default_due_rule_value: Number(editor.dueDay),
    monitoring_module_key: editor.monitoringModuleKey,
    audience_rules: cloneProcessAudienceRules(editor.audienceRules),
    is_active: editor.isActive,
    tasks: editor.tasks.map(task => ({
      sort_order: task.sort_order,
      title: task.title.trim(),
      description: task.description?.trim() || null,
      due_rule_type: task.due_rule_type || 'DAYS_BEFORE_PROCESS_DUE',
      due_rule_value: Number(task.due_rule_value || 0),
      default_department_id: task.default_department_id || null,
      default_assignee_membership_id: task.default_assignee_membership_id || null,
      is_required: task.is_required,
      is_critical: task.is_critical,
      requires_evidence: task.requires_evidence
    }))
  }
}

async function saveTemplate(): Promise<void> {
  await editorSubmitGuard.submit(async () => {
    editorError.value = null
    try {
      const wasUpdate = Boolean(editor.id)
      let templateId = editor.id
      let lockVersion = editor.lockVersion

      if (templateId && lockVersion) {
        const updated = await api.work.templates.update(templateId, {
          ...templatePayload(),
          lock_version: lockVersion
        })
        templateId = updated.data.id
        lockVersion = updated.data.lock_version
        editor.id = templateId
        editor.lockVersion = lockVersion
      } else {
        const created = await api.work.templates.create(templatePayload())
        templateId = created.data.id
        lockVersion = created.data.lock_version
        // Persistir id/lock localmente antes da recorrência para retry sem duplicar
        editor.id = templateId
        editor.lockVersion = lockVersion
      }

      if (templateId && lockVersion) {
        const recurrenceResponse = await api.work.templates.updateRecurrence(templateId, {
          ...recurrencePatchPayload({
            recurrence_enabled: editor.recurrenceEnabled,
            recurrence_frequency: editor.recurrenceFrequency,
            generation_day: editor.generationDay,
            anchor_month: null,
            period_offset: editor.periodOffset,
            next_run_at: editor.nextRunAt,
            recurrence_owner_membership_id: null
          }),
          lock_version: lockVersion
        })
        editor.lockVersion = recurrenceResponse.data.lock_version
        editor.nextRunAt = recurrenceResponse.data.next_run_at
      }

      toast.add({
        title: wasUpdate ? 'Rotina atualizada.' : 'Rotina criada.',
        color: 'success'
      })
      closeEditor()
      view.value = 'tenant'
      await Promise.all([loadTemplates(), loadCatalog()])
    } catch (error) {
      editorError.value = apiErrorMessage(error, 'Não foi possível salvar a rotina.')
      toast.add({ title: editorError.value, color: 'error' })
    }
  })
}

function submitEditorForm(): void {
  const form = document.getElementById('work-template-editor-form') as HTMLFormElement | null
  editorSubmitGuard.requestSubmit(form)
}

function onEditorSubmit(_event: FormSubmitEvent<WorkTemplateFormSchema>): void {
  void saveTemplate()
}

function onEditorValidationError(): void {
  editorSubmitGuard.validationError()
}

async function loadTemplates(): Promise<void> {
  const epoch = sessionEpoch.value
  loading.value = true
  templatesError.value = null
  try {
    const response = await api.work.templates.list({
      page: page.value,
      per_page: perPage.value,
      q: query.value || undefined,
      sort: tenantSort.value || undefined,
      direction: tenantSort.value ? tenantSortDirection.value : undefined
    })
    if (epoch !== sessionEpoch.value) return
    templates.value = response.data
    total.value = response.meta?.total ?? response.data.length
  } catch (error) {
    if (epoch !== sessionEpoch.value) return
    templatesError.value = apiErrorMessage(error, 'Falha ao listar rotinas.')
    toast.add({ title: templatesError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

async function loadCatalog(): Promise<void> {
  const epoch = sessionEpoch.value
  catalogLoading.value = true
  catalogError.value = null
  try {
    const response = await api.work.templates.catalog()
    if (epoch !== sessionEpoch.value) return
    catalog.value = response.data
  } catch (error) {
    if (epoch !== sessionEpoch.value) return
    catalogError.value = apiErrorMessage(error, 'Falha ao carregar a biblioteca.')
    toast.add({ title: catalogError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) catalogLoading.value = false
  }
}

async function loadOptions(): Promise<void> {
  const [departmentsResult, categoriesResult] = await Promise.allSettled([
    api.work.departments.list({ per_page: 100, is_active: true }),
    api.clientCategories.list()
  ])
  departments.value = departmentsResult.status === 'fulfilled' ? departmentsResult.value.data : []
  categories.value = categoriesResult.status === 'fulfilled' ? categoriesResult.value.data : []
}

async function installCatalogItem(item: WorkProcessTemplateCatalogItem): Promise<void> {
  if (item.installed) {
    view.value = 'tenant'
    query.value = ''
    return
  }
  if (!canManageCatalog.value) return
  installingKey.value = item.key
  try {
    await api.work.templates.installCatalog(item.key)
    toast.add({
      title: `${item.name} adicionada às rotinas do escritório.`,
      description: 'A cópia já pode ser personalizada sem alterar a biblioteca.',
      color: 'success'
    })
    await Promise.all([loadCatalog(), loadTemplates()])
  } catch (error) {
    toast.add({ title: apiErrorMessage(error, 'Não foi possível adicionar a rotina.'), color: 'error' })
  } finally {
    installingKey.value = null
  }
}

function openGeneration(template: WorkProcessTemplate): void {
  if (!canGenerateProcesses.value) return
  generationTemplate.value = template
  generationCompetence.value = new Date().toISOString().slice(0, 7)
  generationRules.value = cloneProcessAudienceRules(template.audience_rules)
  generationIncludeIds.value = []
  generationExcludeIds.value = []
  generationBatch.value = null
  generationError.value = null
  generationStep.value = 1
  generationSnapshot.value = generationFingerprint()
  generationOpen.value = true
}

async function previewGeneration(): Promise<void> {
  if (!generationTemplate.value) return
  await generationPreviewGuard.submit(async () => {
    generationError.value = null
    try {
      const response = await api.work.templates.preview(generationTemplate.value!.id, {
        competence: generationCompetence.value,
        selection: buildGenerationSelection(
          generationRules.value,
          generationIncludeIds.value,
          generationExcludeIds.value
        )
      })
      generationBatch.value = response.data
      generationStep.value = 2
    } catch (error) {
      generationError.value = apiErrorMessage(error, 'Falha ao pré-visualizar a geração.')
      toast.add({ title: generationError.value, color: 'error' })
    }
  })
}

function submitGenerationForm(): void {
  const form = document.getElementById('work-generation-form') as HTMLFormElement | null
  generationPreviewGuard.requestSubmit(form)
}

function onGenerationSubmit(_event: FormSubmitEvent<WorkGenerationFormSchema>): void {
  void previewGeneration()
}

function onGenerationValidationError(): void {
  generationPreviewGuard.validationError()
}

async function confirmGeneration(): Promise<void> {
  if (!generationBatch.value) return
  await generationConfirmGuard.submit(async () => {
    generationError.value = null
    try {
      const response = await api.work.generation.confirm(generationBatch.value!.id)
      generationBatch.value = response.data
      generationStep.value = 4
      generationSnapshot.value = generationFingerprint()
      toast.add({ title: 'Processos enviados para geração.', color: 'success' })
    } catch (error: unknown) {
      generationError.value = apiErrorMessage(error, 'Confirmação recusada.')
      const statusCode = (error as { statusCode?: number })?.statusCode
      toast.add({
        title: statusCode === 409
          ? 'O preview expirou ou a rotina foi alterada. Gere uma nova prévia.'
          : generationError.value,
        color: statusCode === 409 ? 'warning' : 'error'
      })
    }
  })
}

async function refreshBatch(): Promise<void> {
  if (!generationBatch.value || generationSubmitting.value) return
  generationBusy.value = true
  try {
    const response = await api.work.generation.get(generationBatch.value.id)
    generationBatch.value = response.data
  } catch (error) {
    toast.add({ title: apiErrorMessage(error, 'Falha ao atualizar o lote.'), color: 'error' })
  } finally {
    generationBusy.value = false
  }
}

function audienceLabel(template: WorkProcessTemplate): string {
  const rules = cloneProcessAudienceRules(template.audience_rules)
  const parts: string[] = []
  if (rules.tax_regimes.length) parts.push(`${rules.tax_regimes.length} regime(s)`)
  if (rules.category_ids.length) parts.push(`${rules.category_ids.length} tag(s)`)
  if (rules.excluded_category_ids.length) parts.push(`${rules.excluded_category_ids.length} exclusão(ões)`)
  return parts.join(' · ') || 'Todos os clientes ativos'
}

function departmentLabel(id?: number | null): string {
  return departments.value.find(department => department.id === id)?.name || 'Sem departamento'
}

function setPerPage(next: number): void {
  const allowed = [10, 20, 50]
  perPage.value = allowed.includes(Number(next)) ? Number(next) : 20
  if (page.value !== 1) page.value = 1
  else void loadTemplates()
}

watch([view, query, page, tenantSort, tenantSortDirection], ([nextView]) => {
  void router.replace({
    query: {
      view: nextView === 'tenant' ? 'tenant' : undefined,
      q: query.value || undefined,
      page: nextView === 'tenant' && page.value > 1 ? String(page.value) : undefined,
      sort: nextView === 'tenant' ? tenantSort.value || undefined : undefined,
      direction: nextView === 'tenant' && tenantSort.value
        ? tenantSortDirection.value
        : undefined
    }
  })
  if (nextView === 'tenant') void loadTemplates()
})

watch(sessionEpoch, () => {
  catalog.value = []
  templates.value = []
  catalogError.value = null
  templatesError.value = null
  page.value = 1
  total.value = 0
  void Promise.all([loadCatalog(), loadTemplates(), loadOptions()])
})

onMounted(() => {
  void Promise.all([loadCatalog(), loadTemplates(), loadOptions()])
})
</script>

<template>
  <ShellPagePanel id="work-templates" data-testid="work-templates-panel">
    <template #header>
      <ShellPageNavbar title="Rotinas">
        <template #right>
          <UButton
            v-if="canManageCatalog"
            icon="i-lucide-plus"
            label="Nova rotina"
            @click="openCreate"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #toolbar>
      <UDashboardToolbar>
        <div class="flex w-full min-w-0 flex-col gap-3 py-1">
          <UFieldGroup class="self-start">
            <UButton
              v-if="canManageCatalog"
              label="Biblioteca"
              icon="i-lucide-library"
              :variant="view === 'library' ? 'solid' : 'outline'"
              :color="view === 'library' ? 'primary' : 'neutral'"
              @click="setView('library')"
            />
            <UButton
              label="Minhas rotinas"
              icon="i-lucide-files"
              :variant="view === 'tenant' ? 'solid' : 'outline'"
              :color="view === 'tenant' ? 'primary' : 'neutral'"
              @click="setView('tenant')"
            />
          </UFieldGroup>

          <ShellListFilterToolbar
            :q="query"
            :search-placeholder="view === 'library' ? 'Buscar na biblioteca…' : 'Buscar minhas rotinas…'"
            :search-aria-label="view === 'library' ? 'Buscar na biblioteca de rotinas' : 'Buscar nas rotinas do escritório'"
            :loading="view === 'library' ? catalogLoading : loading"
            :show-total="true"
            :total="view === 'library' ? filteredCatalog.length : total"
            test-id-prefix="work-templates"
            @update:q="onQueryUpdate"
            @clear="clearQuery"
            @refresh="view === 'library' ? loadCatalog() : loadTemplates()"
          />
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <h1 class="sr-only">
        Rotinas de processo
      </h1>

      <section v-if="view === 'library'" class="flex min-h-0 flex-1 flex-col gap-4" aria-labelledby="template-library-title">
        <div>
          <h2 id="template-library-title" class="text-lg font-semibold text-highlighted">
            Biblioteca de rotinas contábeis
          </h2>
          <p class="mt-1 text-sm text-muted">
            Escolha um padrão do catálogo. O escritório recebe uma cópia própria (Rotina) e pode alterar tarefas, departamento e público.
          </p>
        </div>

        <ShellLoadError
          v-if="catalogError && catalog.length"
          title="Não foi possível atualizar a biblioteca"
          :description="`${catalogError} A última leitura válida foi preservada.`"
          test-id="work-template-catalog-stale-error"
          @retry="loadCatalog"
        />

        <!--
          O catálogo é uma coleção local curta e não paginada pela API.
          ShellDataTable preserva tabela/cards/estado/footer sem simular paginação ou sorting server-side.
        -->
        <ShellDataTable
          test-id="work-template-catalog-table"
          mobile-cards-test-id="work-template-catalog-mobile-cards"
          ui-preset="dashboard"
          primary-column-id="name"
          status-column-id="installed"
          :summary-column-ids="['department_role', 'tasks', 'coverage']"
          :column-labels="{
            name: 'Rotina',
            department_role: 'Departamento',
            tasks: 'Tarefas',
            coverage: 'Cobertura',
            installed: 'Instalação'
          }"
          :get-row-id="item => item.key"
          :columns="catalogColumns"
          :data="filteredCatalog"
          :loading="catalogLoading"
          :error="catalog.length ? null : catalogError"
          :empty-kind="catalogEmptyKind"
          :page="1"
          :total="filteredCatalog.length"
          :items-per-page="50"
          :show-per-page="false"
          :show-pagination="false"
          @retry="loadCatalog"
        >
          <template #name-cell="{ row }">
            <div class="min-w-0" :data-testid="`work-catalog-${row.original.key}`">
              <p class="truncate font-medium text-highlighted" :title="row.original.name">
                {{ truncateText(row.original.name, 42) || row.original.name }}
              </p>
              <p class="line-clamp-2 text-xs text-muted">
                {{ row.original.description || 'Sem descrição' }} · v{{ row.original.version }}
              </p>
            </div>
          </template>
          <template #department_role-cell="{ row }">
            <span class="text-sm">
              {{ row.original.department_role || 'Rotina geral' }}
            </span>
          </template>
          <template #tasks-cell="{ row }">
            <span class="text-sm tabular-nums">
              {{ row.original.tasks.length }}
            </span>
          </template>
          <template #coverage-cell="{ row }">
            <div class="flex min-w-0 flex-wrap gap-1">
              <UBadge
                v-if="row.original.monitoring_module_key"
                icon="i-lucide-activity"
                color="info"
                variant="subtle"
                :label="monitoringModuleLabel(row.original.monitoring_module_key)"
              />
              <UBadge
                v-for="regime in row.original.audience_rules.tax_regimes"
                :key="regime"
                color="neutral"
                variant="subtle"
                :label="WORK_TAX_REGIMES.find(option => option.value === regime)?.label || regime"
              />
              <span
                v-if="!row.original.monitoring_module_key && !row.original.audience_rules.tax_regimes.length"
                class="text-sm text-muted"
              >
                Todos os clientes ativos
              </span>
            </div>
          </template>
          <template #installed-cell="{ row }">
            <UBadge
              :color="row.original.update_available ? 'info' : row.original.installed ? 'success' : 'neutral'"
              variant="subtle"
              :label="row.original.update_available ? 'Atualização disponível' : row.original.installed ? 'Adicionada' : 'Disponível'"
              :class="TABLE_CELL_BADGE_CLASS"
              :ui="TABLE_CELL_BADGE_UI"
            />
          </template>
          <template #actions-cell="{ row }">
            <UButton
              size="xs"
              :color="row.original.installed ? 'neutral' : 'primary'"
              :variant="row.original.installed ? 'outline' : 'soft'"
              :icon="row.original.installed ? 'i-lucide-arrow-right' : 'i-lucide-plus'"
              :label="row.original.installed ? 'Abrir minhas rotinas' : 'Adicionar ao escritório'"
              :aria-label="`${row.original.installed ? 'Abrir minhas rotinas para' : 'Adicionar ao escritório'} ${row.original.name}`"
              :loading="installingKey === row.original.key"
              :disabled="!canManageCatalog && !row.original.installed"
              @click="installCatalogItem(row.original)"
            />
          </template>
          <template #empty>
            <ShellLoadError
              v-if="catalogError && !catalog.length"
              title="Falha ao carregar a biblioteca"
              :description="catalogError"
              test-id="work-template-catalog-error"
              @retry="loadCatalog"
            />
            <UEmpty
              v-else
              icon="i-lucide-search-x"
              :title="query ? 'Nenhuma rotina corresponde à busca' : 'Biblioteca sem rotinas disponíveis'"
              :description="query ? 'Tente outro termo ou limpe a busca.' : 'Atualize para consultar novamente o catálogo.'"
            >
              <template v-if="query" #actions>
                <UButton label="Limpar busca" variant="soft" @click="clearQuery" />
              </template>
            </UEmpty>
          </template>
          <template #footer>
            <span class="tabular-nums">{{ filteredCatalog.length }}</span>
            {{ filteredCatalog.length === 1 ? 'rotina disponível' : 'rotinas disponíveis' }}
          </template>
        </ShellDataTable>
      </section>

      <section v-else class="flex min-h-0 flex-1 flex-col gap-4" aria-labelledby="tenant-templates-title">
        <div>
          <h2 id="tenant-templates-title" class="text-lg font-semibold text-highlighted">
            Rotinas do escritório
          </h2>
          <p class="mt-1 text-sm text-muted">
            Personalize o roteiro e gere um processo para cada empresa selecionada.
          </p>
        </div>

        <ShellLoadError
          v-if="templatesError && templates.length"
          title="Não foi possível atualizar as rotinas"
          :description="`${templatesError} A última leitura válida foi preservada.`"
          test-id="work-templates-stale-error"
          @retry="loadTemplates"
        />

        <ShellDataTable
          test-id="work-templates-table"
          mobile-cards-test-id="work-templates-mobile-cards"
          ui-preset="dashboard"
          primary-column-id="name"
          status-column-id="is_active"
          :summary-column-ids="['audience', 'department', 'agenda', 'tasks']"
          :column-labels="{
            name: 'Rotina',
            audience: 'Público padrão',
            department: 'Departamento',
            agenda: 'Agenda',
            is_active: 'Status',
            tasks: 'Tarefas'
          }"
          :get-row-id="template => String(template.id)"
          :columns="tenantColumns"
          :data="templates"
          :loading="loading"
          :error="templates.length ? null : templatesError"
          :empty-kind="tenantEmptyKind"
          :page="page"
          :total="total"
          :items-per-page="perPage"
          :sorting="tenantSortingState"
          :manual-sorting="true"
          per-page-aria-label="Rotinas por página"
          @update:page="page = $event"
          @update:items-per-page="setPerPage"
          @update:sorting="onTenantSortingUpdate"
          @retry="loadTemplates"
        >
          <template #name-cell="{ row }">
            <div class="min-w-0">
              <p class="truncate font-medium text-highlighted" :title="row.original.name">
                {{ truncateText(row.original.name, 42) || row.original.name }}
              </p>
              <p v-if="row.original.monitoring_module_key" class="text-xs text-muted">
                {{ monitoringModuleLabel(row.original.monitoring_module_key) }}
              </p>
            </div>
          </template>
          <template #audience-cell="{ row }">
            <span class="text-sm">{{ audienceLabel(row.original) }}</span>
          </template>
          <template #department-cell="{ row }">
            {{ departmentLabel(row.original.default_department_id) }}
          </template>
          <template #agenda-cell="{ row }">
            <span class="text-sm text-toned" :title="agendaLabel(row.original)">
              {{ agendaLabel(row.original) }}
            </span>
          </template>
          <template #is_active-cell="{ row }">
            <UBadge
              size="md"
              variant="subtle"
              :color="row.original.is_active ? 'success' : 'neutral'"
              :label="row.original.is_active ? 'Ativo' : 'Inativo'"
              :class="TABLE_CELL_BADGE_CLASS"
              :ui="TABLE_CELL_BADGE_UI"
            />
          </template>
          <template #tasks-cell="{ row }">
            {{ row.original.tasks?.length ?? 0 }}
          </template>
          <template #actions-cell="{ row }">
            <div class="flex justify-end gap-1">
              <UButton
                v-if="canManageCatalog"
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-pencil"
                aria-label="Editar rotina"
                @click="openEdit(row.original)"
              />
              <UButton
                v-if="canGenerateProcesses"
                size="xs"
                variant="soft"
                icon="i-lucide-play"
                label="Gerar"
                :disabled="!row.original.is_active"
                @click="openGeneration(row.original)"
              />
            </div>
          </template>
          <template #empty>
            <ShellLoadError
              v-if="templatesError"
              title="Falha ao listar rotinas"
              :description="templatesError"
              test-id="work-templates-error"
              @retry="loadTemplates"
            />
            <UEmpty
              v-else
              icon="i-lucide-layout-template"
              :title="query ? 'Nenhuma rotina corresponde à busca' : 'Nenhuma rotina no escritório'"
              :description="query ? 'Limpe a busca ou tente outro termo.' : 'Adicione um padrão da biblioteca ou crie sua própria rotina.'"
            >
              <template #actions>
                <UButton
                  v-if="query"
                  label="Limpar busca"
                  variant="soft"
                  @click="clearQuery"
                />
                <UButton
                  v-else-if="canManageCatalog"
                  label="Abrir biblioteca"
                  @click="setView('library')"
                />
              </template>
            </UEmpty>
          </template>
          <template #footer>
            <span class="tabular-nums">{{ total }}</span> rotina(s)
          </template>
        </ShellDataTable>
      </section>

      <ShellFormModal
        v-model:open="editorModalOpen"
        :title="editor.id ? 'Editar rotina' : 'Nova rotina'"
        content-class="max-w-4xl"
        :loading="editorSubmitting"
        :show-default-footer="false"
        @cancel="requestEditorClose"
        @submit="submitEditorForm"
      >
        <template #body>
          <UForm
            id="work-template-editor-form"
            :schema="workTemplateFormSchema"
            :state="editor"
            class="space-y-6"
            data-testid="work-template-editor-form"
            @error="onEditorValidationError"
            @submit="onEditorSubmit"
          >
            <UAlert
              v-if="editorError"
              color="error"
              :title="editorError"
              data-testid="work-template-editor-error"
            />
            <section class="grid gap-4 sm:grid-cols-2">
              <UFormField
                name="name"
                label="Nome"
                required
                class="sm:col-span-2"
              >
                <UInput
                  v-model="editor.name"
                  class="w-full"
                  placeholder="Ex.: PGDAS mensal"
                  autofocus
                />
              </UFormField>
              <UFormField name="description" label="Descrição" class="sm:col-span-2">
                <UTextarea
                  v-model="editor.description"
                  class="w-full"
                  autoresize
                  :maxrows="4"
                />
              </UFormField>
              <UFormField name="defaultDepartmentId" label="Departamento padrão">
                <USelect
                  v-model="editor.defaultDepartmentId"
                  :items="departmentItems"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>
              <UFormField name="dueDay" label="Dia de vencimento" description="Dia dentro da competência.">
                <UInputNumber
                  v-model="editor.dueDay"
                  :min="0"
                  :max="31"
                  class="w-full"
                />
              </UFormField>
              <UFormField name="monitoringModuleKey" label="Contexto no Monitoramento">
                <USelect
                  v-model="editor.monitoringModuleKey"
                  :items="WORK_MONITORING_MODULES"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>
              <UFormField name="isActive" label="Rotina ativa">
                <USwitch v-model="editor.isActive" label="Disponível para novas gerações" />
              </UFormField>
            </section>

            <section
              class="space-y-4 rounded-lg border border-default p-4"
              data-testid="work-template-recurrence"
            >
              <div>
                <h3 class="font-medium text-highlighted">
                  Agenda de recorrência
                </h3>
                <p class="text-sm text-muted">
                  Gera lotes automaticamente no fuso do escritório. Dia de geração entre
                  {{ RECURRENCE_MIN_GENERATION_DAY }} e {{ RECURRENCE_MAX_GENERATION_DAY }}.
                </p>
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField name="recurrenceEnabled" label="Recorrência habilitada" class="sm:col-span-2">
                  <USwitch
                    :model-value="editor.recurrenceEnabled"
                    label="Disparar geração automática"
                    @update:model-value="onRecurrenceEnabledChange"
                  />
                </UFormField>
                <UFormField name="recurrenceFrequency" label="Frequência">
                  <USelect
                    :model-value="editor.recurrenceFrequency ?? undefined"
                    :items="[...RECURRENCE_FREQUENCY_ITEMS]"
                    value-key="value"
                    class="w-full"
                    :disabled="!editor.recurrenceEnabled"
                    placeholder="Selecione"
                    @update:model-value="editor.recurrenceFrequency = ($event as RecurrenceFrequency | undefined) || null"
                  />
                </UFormField>
                <UFormField name="generationDay" label="Dia de geração">
                  <UInputNumber
                    v-model="editor.generationDay"
                    :min="RECURRENCE_MIN_GENERATION_DAY"
                    :max="RECURRENCE_MAX_GENERATION_DAY"
                    class="w-full"
                    :disabled="!editor.recurrenceEnabled"
                  />
                </UFormField>
                <UFormField name="periodOffset" label="Defasagem do período" class="sm:col-span-2">
                  <USelect
                    :model-value="editor.periodOffset"
                    :items="[...RECURRENCE_PERIOD_OFFSET_ITEMS]"
                    value-key="value"
                    class="w-full"
                    :disabled="!editor.recurrenceEnabled"
                    @update:model-value="editor.periodOffset = ($event as RecurrencePeriodOffset) || 'PREVIOUS'"
                  />
                </UFormField>
                <UFormField
                  v-if="editor.nextRunAt"
                  name="nextRunAt"
                  label="Próxima execução"
                  class="sm:col-span-2"
                >
                  <p class="text-sm text-toned" data-testid="work-template-next-run">
                    {{ formatNextRunAt(editor.nextRunAt) }}
                  </p>
                </UFormField>
              </div>
            </section>

            <section class="space-y-4 rounded-lg border border-default p-4">
              <div>
                <h3 class="font-medium text-highlighted">
                  Público padrão
                </h3>
                <p class="text-sm text-muted">
                  As regras são avaliadas na competência informada. Inclusões e exclusões manuais entram somente na geração.
                </p>
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField name="audienceRules.tax_regimes" label="Regimes tributários">
                  <USelectMenu
                    v-model="editor.audienceRules.tax_regimes"
                    :items="WORK_TAX_REGIMES"
                    value-key="value"
                    multiple
                    clear
                    class="w-full"
                    placeholder="Todos os regimes"
                  />
                </UFormField>
                <UFormField name="audienceRules.category_match" label="Combinação das tags">
                  <URadioGroup
                    v-model="editor.audienceRules.category_match"
                    orientation="horizontal"
                    :items="[
                      { label: 'Qualquer tag', value: 'ANY' },
                      { label: 'Todas as tags', value: 'ALL' }
                    ]"
                  />
                </UFormField>
                <UFormField name="audienceRules.category_ids" label="Tags que incluem">
                  <USelectMenu
                    v-model="editor.audienceRules.category_ids"
                    :items="categoryItems"
                    value-key="id"
                    label-key="label"
                    multiple
                    clear
                    class="w-full"
                    placeholder="Nenhuma tag obrigatória"
                  />
                </UFormField>
                <UFormField name="audienceRules.excluded_category_ids" label="Tags que excluem">
                  <USelectMenu
                    v-model="editor.audienceRules.excluded_category_ids"
                    :items="categoryItems"
                    value-key="id"
                    label-key="label"
                    multiple
                    clear
                    class="w-full"
                    placeholder="Nenhuma tag de exclusão"
                  />
                </UFormField>
              </div>
            </section>

            <section class="space-y-3">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h3 class="font-medium text-highlighted">
                    Tarefas do processo
                  </h3>
                  <p class="text-sm text-muted">
                    A ordem será preservada em cada empresa.
                  </p>
                </div>
                <UButton
                  size="sm"
                  variant="soft"
                  icon="i-lucide-plus"
                  label="Tarefa"
                  @click="addTask"
                />
              </div>

              <div
                v-for="(task, index) in editor.tasks"
                :key="`${task.id || 'new'}-${index}`"
                class="grid gap-3 rounded-lg border border-default p-3 lg:grid-cols-12"
              >
                <div class="flex items-center gap-1 lg:col-span-1">
                  <span class="w-6 text-center text-sm tabular-nums text-muted">{{ index + 1 }}</span>
                  <div class="flex lg:flex-col">
                    <UButton
                      size="xs"
                      icon="i-lucide-chevron-up"
                      color="neutral"
                      variant="ghost"
                      aria-label="Mover para cima"
                      :disabled="index === 0"
                      @click="moveTask(index, -1)"
                    />
                    <UButton
                      size="xs"
                      icon="i-lucide-chevron-down"
                      color="neutral"
                      variant="ghost"
                      aria-label="Mover para baixo"
                      :disabled="index === editor.tasks.length - 1"
                      @click="moveTask(index, 1)"
                    />
                  </div>
                </div>
                <UFormField
                  :name="`tasks.${index}.title`"
                  label="Tarefa"
                  required
                  class="lg:col-span-4"
                >
                  <UInput v-model="task.title" class="w-full" placeholder="Título da tarefa" />
                </UFormField>
                <UFormField :name="`tasks.${index}.due_rule_value`" label="Dias antes" class="lg:col-span-2">
                  <UInputNumber
                    v-model="task.due_rule_value"
                    :min="0"
                    :max="366"
                    class="w-full"
                  />
                </UFormField>
                <UFormField :name="`tasks.${index}.default_department_id`" label="Departamento" class="lg:col-span-3">
                  <USelect
                    v-model="task.default_department_id"
                    :items="departmentItems"
                    value-key="value"
                    class="w-full"
                  />
                </UFormField>
                <div class="flex flex-wrap items-end gap-3 lg:col-span-2">
                  <UFormField :name="`tasks.${index}.is_critical`">
                    <UCheckbox v-model="task.is_critical" label="Crítica" />
                  </UFormField>
                  <UFormField :name="`tasks.${index}.requires_evidence`">
                    <UCheckbox v-model="task.requires_evidence" label="Evidência" />
                  </UFormField>
                  <UButton
                    icon="i-lucide-trash"
                    color="error"
                    variant="ghost"
                    size="xs"
                    aria-label="Remover tarefa"
                    :disabled="editor.tasks.length === 1"
                    @click="removeTask(index)"
                  />
                </div>
              </div>
            </section>
          </UForm>
        </template>
        <template #footer>
          <ShellModalFooter
            :submit-label="editor.id ? 'Salvar alterações' : 'Criar rotina'"
            :loading="editorSubmitting"
            :cancel-disabled="editorSubmitting"
            @cancel="requestEditorClose"
            @submit="submitEditorForm"
          />
        </template>
      </ShellFormModal>

      <ShellFormModal
        v-model:open="generationModalOpen"
        :title="`Gerar processos — ${generationTemplate?.name || ''}`"
        content-class="max-w-3xl"
        :show-default-footer="false"
        @cancel="requestGenerationClose"
      >
        <template #body>
          <UForm
            id="work-generation-form"
            :schema="workGenerationFormSchema"
            :state="generationFormState"
            data-testid="work-generation-form"
            @error="onGenerationValidationError"
            @submit="onGenerationSubmit"
          >
            <UStepper
              :model-value="generationStep === 4 ? 3 : generationStep - 1"
              :items="generationSteps"
              class="mb-6 w-full"
              disabled
            />

            <UAlert
              v-if="generationError"
              class="mb-4"
              color="error"
              :title="generationError"
              data-testid="work-generation-error"
            />

            <div v-if="generationStep === 1" class="space-y-5">
              <UFormField
                name="competence"
                label="Competência"
                required
                description="O regime tributário será avaliado nesta competência."
              >
                <UInput
                  v-model="generationCompetence"
                  type="month"
                  class="w-full"
                  data-testid="work-gen-competence"
                  autofocus
                />
              </UFormField>

              <section class="space-y-4 rounded-lg border border-default p-4">
                <div>
                  <h3 class="font-medium text-highlighted">
                    Seleção automática
                  </h3>
                  <p class="text-sm text-muted">
                    Começa pelas regras da rotina; você pode ajustá-las somente para este lote.
                  </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                  <UFormField name="rules.tax_regimes" label="Regimes tributários">
                    <USelectMenu
                      v-model="generationRules.tax_regimes"
                      :items="WORK_TAX_REGIMES"
                      value-key="value"
                      multiple
                      clear
                      class="w-full"
                      placeholder="Todos os regimes"
                    />
                  </UFormField>
                  <UFormField name="rules.category_match" label="Combinação das tags">
                    <URadioGroup
                      v-model="generationRules.category_match"
                      orientation="horizontal"
                      :items="[
                        { label: 'Qualquer', value: 'ANY' },
                        { label: 'Todas', value: 'ALL' }
                      ]"
                    />
                  </UFormField>
                  <UFormField name="rules.category_ids" label="Tags que incluem">
                    <USelectMenu
                      v-model="generationRules.category_ids"
                      :items="categoryItems"
                      value-key="id"
                      label-key="label"
                      multiple
                      clear
                      class="w-full"
                      placeholder="Sem filtro de tags"
                    />
                  </UFormField>
                  <UFormField name="rules.excluded_category_ids" label="Tags que excluem">
                    <USelectMenu
                      v-model="generationRules.excluded_category_ids"
                      :items="categoryItems"
                      value-key="id"
                      label-key="label"
                      multiple
                      clear
                      class="w-full"
                      placeholder="Sem exclusão por tag"
                    />
                  </UFormField>
                </div>
              </section>

              <section class="grid gap-4 sm:grid-cols-2">
                <UFormField name="includeIds" label="Incluir empresas manualmente" description="Entram mesmo que não atendam aos filtros.">
                  <FiscalClientPicker
                    v-model="generationIncludeIds"
                    multiple
                    placeholder="Buscar empresas para incluir…"
                  />
                </UFormField>
                <UFormField name="excludeIds" label="Excluir empresas manualmente" description="A exclusão sempre prevalece sobre a inclusão.">
                  <FiscalClientPicker
                    v-model="generationExcludeIds"
                    multiple
                    placeholder="Buscar empresas para excluir…"
                  />
                </UFormField>
              </section>

              <UAlert
                color="info"
                variant="subtle"
                icon="i-lucide-shield-check"
                title="A prévia não cria processos"
                description="Você verá exatamente quais empresas entram, o regime utilizado, alertas e conflitos antes de confirmar."
              />
            </div>

            <div v-else-if="generationStep === 2 && generationBatch" class="space-y-4">
              <dl
                class="grid grid-cols-3 divide-x divide-default rounded-lg border border-default"
                data-testid="work-generation-preview-summary"
              >
                <div class="min-w-0 px-3 py-2.5">
                  <dt class="text-xs text-muted">
                    Selecionadas
                  </dt>
                  <dd class="mt-1 text-lg font-semibold text-highlighted tabular-nums">
                    {{ generationBatch.preview_summary?.total ?? 0 }}
                  </dd>
                </div>
                <div class="min-w-0 px-3 py-2.5">
                  <dt class="text-xs text-muted">
                    Prontas
                  </dt>
                  <dd class="mt-1 text-lg font-semibold text-success tabular-nums">
                    {{ generationBatch.preview_summary?.ready ?? 0 }}
                  </dd>
                </div>
                <div class="min-w-0 px-3 py-2.5">
                  <dt class="text-xs text-muted">
                    Bloqueadas
                  </dt>
                  <dd class="mt-1 text-lg font-semibold text-warning tabular-nums">
                    {{ generationBatch.preview_summary?.blocked ?? 0 }}
                  </dd>
                </div>
              </dl>

              <p class="text-sm text-muted">
                {{ generationBatch.preview_summary?.matched_by_rule ?? 0 }} por regra ·
                {{ generationBatch.preview_summary?.included_manually ?? 0 }} incluída(s) manualmente ·
                {{ generationBatch.preview_summary?.excluded_manually ?? 0 }} excluída(s)
              </p>

              <div class="max-h-80 space-y-2 overflow-y-auto pe-1">
                <article
                  v-for="item in generationBatch.items"
                  :key="item.id"
                  class="rounded-lg border border-default p-3"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-medium text-highlighted">
                        {{ generationItemClientLabel(item) }}
                      </p>
                      <p class="text-xs text-muted">
                        {{ generationItemClientMeta(item) }}
                      </p>
                    </div>
                    <UBadge
                      :color="item.is_blocked ? 'warning' : 'success'"
                      variant="subtle"
                      :label="item.is_blocked ? 'Bloqueada' : 'Pronta'"
                    />
                  </div>
                  <div v-if="item.preview_payload?.selection?.categories?.length" class="mt-2 flex flex-wrap gap-1">
                    <UBadge
                      v-for="category in item.preview_payload.selection.categories"
                      :key="category.id"
                      color="neutral"
                      variant="subtle"
                      :label="category.name"
                    />
                  </div>
                  <ul v-if="item.alerts?.length || item.conflicts?.length" class="mt-2 space-y-1 text-xs text-warning">
                    <li v-for="(alert, index) in item.alerts" :key="`alert-${index}`">
                      {{ alert.message || alert.code }}
                    </li>
                    <li v-for="conflict in item.conflicts" :key="conflict.code">
                      {{ conflict.message }}
                    </li>
                  </ul>
                </article>
              </div>
            </div>

            <div v-else-if="generationStep === 4 && generationBatch" class="space-y-4">
              <UAlert
                color="success"
                icon="i-lucide-circle-check"
                title="Lote confirmado"
                :description="`Status atual: ${generationBatch.status}.`"
              />
              <ul class="max-h-80 divide-y divide-default overflow-y-auto rounded-lg border border-default">
                <li v-for="item in generationBatch.items" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                  <span>{{ generationItemClientLabel(item) }}</span>
                  <UButton
                    v-if="item.created_process_id"
                    :to="`/work/processes/${item.created_process_id}`"
                    size="xs"
                    variant="soft"
                    icon="i-lucide-arrow-up-right"
                    label="Abrir processo"
                  />
                  <span v-else-if="item.error_message" class="text-error">{{ item.error_message }}</span>
                  <UBadge
                    v-else
                    color="neutral"
                    variant="subtle"
                    :label="item.status"
                  />
                </li>
              </ul>
              <div class="flex flex-wrap gap-2">
                <UButton
                  size="sm"
                  variant="soft"
                  icon="i-lucide-refresh-cw"
                  label="Atualizar status"
                  :loading="generationSubmitting"
                  @click="refreshBatch"
                />
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-folder-kanban"
                  label="Ver processos"
                  to="/work/processes"
                />
              </div>
            </div>
          </UForm>
        </template>

        <template #footer>
          <ShellModalFooter :show-submit="false">
            <UButton
              color="neutral"
              variant="ghost"
              label="Fechar"
              :disabled="generationSubmitting"
              @click="requestGenerationClose"
            />
            <UButton
              v-if="generationStep === 1"
              data-testid="work-gen-preview"
              :loading="generationSubmitting"
              label="Pré-visualizar empresas"
              @click="submitGenerationForm"
            />
            <UButton
              v-if="generationStep === 2"
              data-testid="work-gen-confirm"
              :loading="generationSubmitting"
              :disabled="(generationBatch?.preview_summary?.ready ?? 0) < 1"
              label="Confirmar geração"
              @click="confirmGeneration"
            />
          </ShellModalFooter>
        </template>
      </ShellFormModal>

      <ShellConfirmModal
        v-model:open="editorDiscardOpen"
        title="Descartar alterações da rotina?"
        description="As alterações ainda não salvas serão perdidas."
        confirm-label="Descartar alterações"
        tone="danger"
        test-id="work-template-discard-confirm"
        @cancel="editorDiscardOpen = false"
        @confirm="discardEditor"
      />

      <ShellConfirmModal
        v-model:open="generationDiscardOpen"
        title="Descartar configuração da geração?"
        description="A competência e os ajustes de público ainda não confirmados serão perdidos."
        confirm-label="Descartar configuração"
        tone="danger"
        test-id="work-generation-discard-confirm"
        @cancel="generationDiscardOpen = false"
        @confirm="discardGeneration"
      />
    </template>
  </ShellPagePanel>
</template>
