<script setup lang="ts">
/**
 * Filtros da lista de conversas:
 * - busca direta;
 * - status e ordenação em dropdowns iconográficos;
 * - escopo operacional em um popover de regras com rascunho.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type {
  CommunicationConversationSortBy,
  CommunicationConversationStatus
} from '~/types/communication'
import {
  COMMUNICATION_SORT_BY_OPTIONS,
  normalizeCommunicationConversationSortBy
} from '~/utils/communication-conversation-sort'

type SelectItem = { label: string, value: number | string }
type StatusValue = CommunicationConversationStatus | 'ALL'
type ActiveFilterSummary = { key: string, label: string }
type AdvancedFilterField
  = | 'inbox'
    | 'assignee'
    | 'department'
    | 'labels'
    | 'unread'
    | 'unassigned'
    | 'contact'

type AdvancedFilterRule = {
  id: number
  field: AdvancedFilterField
}

type AdvancedFilterDraft = {
  inboxId: number
  assigneeId: number
  departmentId: number
  labelIds: number[]
  unassignedOnly: boolean
  unreadOnly: boolean
  keepContact: boolean
}

const props = defineProps<{
  search: string
  status: StatusValue
  inboxId: number
  assigneeId: number
  departmentId: number
  labelIds: number[]
  sortBy: CommunicationConversationSortBy
  unassignedOnly: boolean
  unreadOnly: boolean
  contactFilterLabel?: string | null
  inboxItems: SelectItem[]
  assigneeItems: SelectItem[]
  departmentItems: SelectItem[]
  labelItems: SelectItem[]
}>()

const emit = defineEmits<{
  'update:search': [value: string]
  'update:status': [value: StatusValue]
  'update:inboxId': [value: number]
  'update:assigneeId': [value: number]
  'update:departmentId': [value: number]
  'update:labelIds': [value: number[]]
  'update:sortBy': [value: CommunicationConversationSortBy]
  'update:unassignedOnly': [value: boolean]
  'update:unreadOnly': [value: boolean]
  'clear-contact': []
}>()

const statusItems = [
  { label: 'Abertas', value: 'OPEN', icon: 'i-lucide-inbox' },
  { label: 'Pendentes', value: 'PENDING', icon: 'i-lucide-clock-3' },
  { label: 'Adiadas', value: 'SNOOZED', icon: 'i-lucide-alarm-clock' },
  { label: 'Resolvidas', value: 'RESOLVED', icon: 'i-lucide-circle-check' },
  { label: 'Todas', value: 'ALL', icon: 'i-lucide-layers-3' }
] as const

const advancedFilterFieldItems: Array<{
  label: string
  value: AdvancedFilterField
}> = [
  { label: 'Inbox', value: 'inbox' },
  { label: 'Responsável', value: 'assignee' },
  { label: 'Fila', value: 'department' },
  { label: 'Marcadores', value: 'labels' },
  { label: 'Não lidas', value: 'unread' },
  { label: 'Sem responsável', value: 'unassigned' },
  { label: 'Contato', value: 'contact' }
]

const advancedOpen = ref(false)
const advancedRules = ref<AdvancedFilterRule[]>([])
let nextAdvancedRuleId = 0

const advancedDraft = reactive<AdvancedFilterDraft>({
  inboxId: 0,
  assigneeId: 0,
  departmentId: 0,
  labelIds: [],
  unassignedOnly: false,
  unreadOnly: false,
  keepContact: false
})

const normalizedSort = computed(() => normalizeCommunicationConversationSortBy(props.sortBy))

function itemLabel(items: SelectItem[], value: number): string | null {
  if (value <= 0) return null
  return items.find(item => Number(item.value) === value)?.label ?? null
}

const statusLabel = computed(() =>
  statusItems.find(item => item.value === props.status)?.label || 'Status'
)

const sortLabel = computed(() =>
  COMMUNICATION_SORT_BY_OPTIONS.find(item => item.value === normalizedSort.value)?.label
  || 'Ordenação'
)

const statusMenuItems = computed<DropdownMenuItem[][]>(() => [[{
  label: 'Status das conversas',
  type: 'label'
}], statusItems.map(item => ({
  label: item.label,
  icon: props.status === item.value ? 'i-lucide-check' : item.icon,
  class: props.status === item.value ? 'font-medium text-primary' : undefined,
  onSelect: () => emit('update:status', item.value),
  ...{ 'data-testid': `communication-filter-status-option-${item.value}` }
}))])

const sortMenuItems = computed<DropdownMenuItem[][]>(() => [[{
  label: 'Ordenar conversas',
  type: 'label'
}], COMMUNICATION_SORT_BY_OPTIONS.map(item => ({
  label: item.label,
  icon: normalizedSort.value === item.value
    ? 'i-lucide-check'
    : 'i-lucide-arrow-up-down',
  class: normalizedSort.value === item.value ? 'font-medium text-primary' : undefined,
  onSelect: () => emit('update:sortBy', item.value),
  ...{ 'data-testid': `communication-filter-sort-option-${item.value}` }
}))])

const activeFilterSummaries = computed<ActiveFilterSummary[]>(() => {
  const summaries: ActiveFilterSummary[] = []

  if (props.contactFilterLabel) {
    summaries.push({ key: 'contact', label: `Contato: ${props.contactFilterLabel}` })
  }
  if (props.unreadOnly) {
    summaries.push({ key: 'unread', label: 'Não lidas' })
  }
  if (props.unassignedOnly) {
    summaries.push({ key: 'unassigned', label: 'Sem responsável' })
  } else {
    const assignee = itemLabel(props.assigneeItems, props.assigneeId)
    if (assignee) summaries.push({ key: 'assignee', label: `Responsável: ${assignee}` })
  }

  const inbox = itemLabel(props.inboxItems, props.inboxId)
  if (inbox) summaries.push({ key: 'inbox', label: `Inbox: ${inbox}` })

  const department = itemLabel(props.departmentItems, props.departmentId)
  if (department) summaries.push({ key: 'department', label: `Fila: ${department}` })

  if (props.labelIds.length === 1) {
    const label = itemLabel(props.labelItems, props.labelIds[0] ?? 0)
    summaries.push({ key: 'labels', label: label ? `Marcador: ${label}` : '1 marcador' })
  } else if (props.labelIds.length > 1) {
    summaries.push({ key: 'labels', label: `${props.labelIds.length} marcadores` })
  }

  return summaries
})

const visibleFilterSummaries = computed(() => activeFilterSummaries.value.slice(0, 2))
const hiddenFilterSummaryCount = computed(() => Math.max(
  0,
  activeFilterSummaries.value.length - visibleFilterSummaries.value.length
))
const advancedActiveCount = computed(() => activeFilterSummaries.value.length)
const hasAdvancedFilters = computed(() => advancedActiveCount.value > 0)

const searchModel = computed({
  get: () => props.search,
  set: (value: string) => emit('update:search', value)
})

function makeAdvancedRule(field: AdvancedFilterField): AdvancedFilterRule {
  nextAdvancedRuleId += 1
  return { id: nextAdvancedRuleId, field }
}

function activeAdvancedFields(): AdvancedFilterField[] {
  const fields: AdvancedFilterField[] = []
  if (props.inboxId > 0) fields.push('inbox')
  if (props.assigneeId > 0 && !props.unassignedOnly) fields.push('assignee')
  if (props.departmentId > 0) fields.push('department')
  if (props.labelIds.length > 0) fields.push('labels')
  if (props.unreadOnly) fields.push('unread')
  if (props.unassignedOnly) fields.push('unassigned')
  if (props.contactFilterLabel) fields.push('contact')
  return fields
}

function resetAdvancedDraft(): void {
  Object.assign(advancedDraft, {
    inboxId: props.inboxId,
    assigneeId: props.unassignedOnly ? 0 : props.assigneeId,
    departmentId: props.departmentId,
    labelIds: [...props.labelIds],
    unassignedOnly: props.unassignedOnly,
    unreadOnly: props.unreadOnly,
    keepContact: Boolean(props.contactFilterLabel)
  })

  advancedRules.value = activeAdvancedFields().map(makeAdvancedRule)
  if (advancedRules.value.length === 0) addAdvancedRule()
}

function availableAdvancedFields(ruleId?: number): AdvancedFilterField[] {
  const used = new Set(advancedRules.value
    .filter(rule => rule.id !== ruleId)
    .map(rule => rule.field))

  return advancedFilterFieldItems
    .map(item => item.value)
    .filter((field) => {
      if (used.has(field)) return false
      if (field === 'contact' && !props.contactFilterLabel) return false
      if (field === 'assignee' && used.has('unassigned')) return false
      if (field === 'unassigned' && used.has('assignee')) return false
      return true
    })
}

function availableAdvancedFieldItems(rule: AdvancedFilterRule) {
  const available = new Set(availableAdvancedFields(rule.id))
  available.add(rule.field)
  return advancedFilterFieldItems.filter(item => available.has(item.value))
}

const canAddAdvancedRule = computed(() => availableAdvancedFields().length > 0)

function initializeDraftField(field: AdvancedFilterField): void {
  if (field === 'unread') advancedDraft.unreadOnly = true
  if (field === 'unassigned') {
    advancedDraft.unassignedOnly = true
    advancedDraft.assigneeId = 0
  }
  if (field === 'contact') advancedDraft.keepContact = true
}

function clearDraftField(field: AdvancedFilterField): void {
  if (field === 'inbox') advancedDraft.inboxId = 0
  if (field === 'assignee') advancedDraft.assigneeId = 0
  if (field === 'department') advancedDraft.departmentId = 0
  if (field === 'labels') advancedDraft.labelIds = []
  if (field === 'unread') advancedDraft.unreadOnly = false
  if (field === 'unassigned') advancedDraft.unassignedOnly = false
  if (field === 'contact') advancedDraft.keepContact = false
}

function addAdvancedRule(): void {
  const field = availableAdvancedFields()[0]
  if (!field) return
  advancedRules.value.push(makeAdvancedRule(field))
  initializeDraftField(field)
}

function updateAdvancedRuleField(rule: AdvancedFilterRule, value: unknown): void {
  const field = String(value) as AdvancedFilterField
  if (rule.field === field || !availableAdvancedFields(rule.id).includes(field)) return
  clearDraftField(rule.field)
  rule.field = field
  initializeDraftField(field)
}

function removeAdvancedRule(rule: AdvancedFilterRule): void {
  clearDraftField(rule.field)
  advancedRules.value = advancedRules.value.filter(item => item.id !== rule.id)
}

function setDraftNumber(
  field: 'assigneeId' | 'departmentId' | 'inboxId',
  value: unknown
): void {
  advancedDraft[field] = Number(value) || 0
}

function setDraftLabels(value: unknown): void {
  advancedDraft.labelIds = Array.isArray(value) ? value.map(Number) : []
}

function selectableItems(items: SelectItem[]): SelectItem[] {
  return items.filter(item => Number(item.value) > 0)
}

function advancedOperatorLabel(field: AdvancedFilterField): string {
  if (field === 'labels') return 'Contém qualquer'
  if (field === 'unread' || field === 'unassigned') return 'É igual a'
  return 'Igual a'
}

const advancedDraftHasValues = computed(() => Boolean(
  advancedDraft.inboxId
  || advancedDraft.assigneeId
  || advancedDraft.departmentId
  || advancedDraft.labelIds.length
  || advancedDraft.unassignedOnly
  || advancedDraft.unreadOnly
  || advancedDraft.keepContact
))

const advancedDraftCanClear = computed(() =>
  advancedDraftHasValues.value || advancedRules.value.length > 0
)

const advancedDraftDirty = computed(() =>
  advancedDraft.inboxId !== props.inboxId
  || advancedDraft.assigneeId !== props.assigneeId
  || advancedDraft.departmentId !== props.departmentId
  || advancedDraft.labelIds.join(',') !== props.labelIds.join(',')
  || advancedDraft.unassignedOnly !== props.unassignedOnly
  || advancedDraft.unreadOnly !== props.unreadOnly
  || advancedDraft.keepContact !== Boolean(props.contactFilterLabel)
)

function advancedRuleHasValue(rule: AdvancedFilterRule): boolean {
  if (rule.field === 'inbox') return advancedDraft.inboxId > 0
  if (rule.field === 'assignee') return advancedDraft.assigneeId > 0
  if (rule.field === 'department') return advancedDraft.departmentId > 0
  if (rule.field === 'labels') return advancedDraft.labelIds.length > 0
  if (rule.field === 'unread') return advancedDraft.unreadOnly
  if (rule.field === 'unassigned') return advancedDraft.unassignedOnly
  return advancedDraft.keepContact && Boolean(props.contactFilterLabel)
}

const advancedDraftHasIncompleteRule = computed(() =>
  advancedRules.value.some(rule => !advancedRuleHasValue(rule))
)

function onAdvancedOpenChange(open: boolean): void {
  resetAdvancedDraft()
  advancedOpen.value = open
}

function openAdvancedFilters(): void {
  resetAdvancedDraft()
  advancedOpen.value = true
}

watch(
  () => [
    props.inboxId,
    props.assigneeId,
    props.departmentId,
    props.labelIds.join(','),
    props.unassignedOnly,
    props.unreadOnly,
    props.contactFilterLabel ?? ''
  ],
  () => {
    if (advancedOpen.value) resetAdvancedDraft()
  }
)

function clearAdvancedDraft(): void {
  Object.assign(advancedDraft, {
    inboxId: 0,
    assigneeId: 0,
    departmentId: 0,
    labelIds: [],
    unassignedOnly: false,
    unreadOnly: false,
    keepContact: false
  })
  advancedRules.value = []
}

function applyAdvancedFilters(): void {
  if (!advancedDraftDirty.value || advancedDraftHasIncompleteRule.value) return

  emit('update:inboxId', advancedDraft.inboxId)
  emit('update:assigneeId', advancedDraft.assigneeId)
  emit('update:departmentId', advancedDraft.departmentId)
  emit('update:labelIds', [...advancedDraft.labelIds])
  emit('update:unassignedOnly', advancedDraft.unassignedOnly)
  emit('update:unreadOnly', advancedDraft.unreadOnly)
  if (props.contactFilterLabel && !advancedDraft.keepContact) emit('clear-contact')
  advancedOpen.value = false
}

resetAdvancedDraft()
</script>

<template>
  <div
    class="flex w-full min-w-0 max-w-full flex-col gap-1.5 overflow-x-hidden py-2"
    data-testid="communication-list-filters"
  >
    <div class="flex w-full min-w-0 items-center gap-1">
      <UInput
        v-model="searchModel"
        icon="i-lucide-search"
        placeholder="Buscar contato, telefone ou mensagem"
        size="sm"
        class="min-w-0 flex-1"
        data-testid="communication-search"
        aria-label="Buscar conversas"
      >
        <template v-if="search" #trailing>
          <UButton
            icon="i-lucide-x"
            size="xs"
            square
            color="neutral"
            variant="link"
            aria-label="Limpar busca"
            data-testid="communication-search-clear"
            @click="emit('update:search', '')"
          />
        </template>
      </UInput>

      <div
        class="flex shrink-0 items-center gap-0.5"
        role="group"
        aria-label="Controles da lista de conversas"
      >
        <UDropdownMenu
          :items="statusMenuItems"
          :content="{
            align: 'start',
            side: 'bottom',
            sideOffset: 6,
            collisionPadding: 8
          }"
        >
          <UButton
            icon="i-lucide-inbox"
            color="neutral"
            :variant="status === 'OPEN' ? 'ghost' : 'soft'"
            size="sm"
            square
            class="[@media(pointer:coarse)]:size-11"
            :aria-label="`Status: ${statusLabel}`"
            :title="`Status: ${statusLabel}`"
            data-testid="communication-filter-status"
          />
        </UDropdownMenu>

        <UDropdownMenu
          :items="sortMenuItems"
          :content="{
            align: 'end',
            side: 'bottom',
            sideOffset: 6,
            collisionPadding: 8
          }"
        >
          <UButton
            icon="i-lucide-arrow-up-down"
            color="neutral"
            :variant="normalizedSort === 'last_activity_desc' ? 'ghost' : 'soft'"
            size="sm"
            square
            class="[@media(pointer:coarse)]:size-11"
            :aria-label="`Ordenação: ${sortLabel}`"
            :title="`Ordenação: ${sortLabel}`"
            data-testid="communication-filter-sort"
          />
        </UDropdownMenu>

        <UPopover
          :open="advancedOpen"
          :portal="true"
          :content="{
            align: 'end',
            side: 'bottom',
            sideOffset: 6,
            collisionPadding: 8
          }"
          :ui="{ content: 'max-w-[calc(100vw-1rem)] overflow-hidden' }"
          @update:open="onAdvancedOpenChange"
        >
          <UButton
            icon="i-lucide-list-filter"
            size="sm"
            square
            class="[@media(pointer:coarse)]:size-11"
            :color="hasAdvancedFilters || advancedOpen ? 'primary' : 'neutral'"
            :variant="hasAdvancedFilters || advancedOpen ? 'soft' : 'ghost'"
            :aria-expanded="advancedOpen"
            aria-controls="communication-filter-advanced-panel"
            :aria-label="hasAdvancedFilters
              ? `Editar filtros avançados: ${advancedActiveCount} ativos`
              : 'Abrir filtros avançados'"
            :title="hasAdvancedFilters
              ? `${advancedActiveCount} filtros avançados ativos`
              : 'Filtros avançados'"
            data-testid="communication-filter-advanced-toggle"
          />

          <template #content>
            <section
              id="communication-filter-advanced-panel"
              class="flex w-[calc(100vw-1rem)] max-w-[38rem] min-w-0 flex-col overflow-hidden"
              data-testid="communication-filter-advanced-panel"
              aria-labelledby="communication-filter-advanced-title"
            >
              <header class="border-b border-default px-4 py-3">
                <h2
                  id="communication-filter-advanced-title"
                  class="text-sm font-semibold text-highlighted"
                >
                  Filtrar conversas
                </h2>
                <p class="mt-0.5 text-xs text-muted">
                  Todas as regras são combinadas com “E”.
                </p>
              </header>

              <div class="max-h-[calc(100vh-12rem)] min-w-0 overflow-y-auto overflow-x-hidden p-3 sm:max-h-96">
                <div
                  v-if="advancedRules.length"
                  class="flex min-w-0 flex-col gap-2"
                  role="list"
                  aria-label="Regras de filtro"
                >
                  <template v-for="(rule, index) in advancedRules" :key="rule.id">
                    <span
                      v-if="index > 0"
                      class="ms-2 self-start rounded-md bg-elevated px-2 py-0.5 text-[10px] font-semibold text-muted"
                      role="presentation"
                      aria-hidden="true"
                    >
                      E
                    </span>

                    <div
                      class="relative grid min-w-0 grid-cols-1 gap-2 rounded-md bg-elevated/60 p-2 pe-12 sm:grid-cols-[minmax(8rem,1fr)_auto_minmax(7rem,0.75fr)_minmax(9rem,1fr)] sm:items-center"
                      role="listitem"
                      :data-testid="`communication-filter-rule-${rule.id}`"
                    >
                      <USelect
                        :model-value="rule.field"
                        :items="availableAdvancedFieldItems(rule)"
                        value-key="value"
                        size="sm"
                        class="w-full min-w-0 [@media(pointer:coarse)]:min-h-11"
                        :aria-label="`Campo da regra ${index + 1}`"
                        :data-testid="`communication-filter-rule-field-${rule.id}`"
                        @update:model-value="value => updateAdvancedRuleField(rule, value)"
                      />

                      <UIcon
                        name="i-lucide-equal"
                        class="hidden size-4 shrink-0 text-primary sm:block"
                        aria-hidden="true"
                      />

                      <div
                        class="flex min-h-8 min-w-0 items-center rounded-md px-2 text-xs font-medium text-toned ring ring-inset ring-default"
                        :data-testid="`communication-filter-rule-operator-${rule.id}`"
                      >
                        {{ advancedOperatorLabel(rule.field) }}
                      </div>

                      <USelectMenu
                        v-if="rule.field === 'inbox'"
                        :model-value="advancedDraft.inboxId || undefined"
                        :items="selectableItems(inboxItems)"
                        value-key="value"
                        placeholder="Selecione a inbox"
                        size="sm"
                        class="w-full min-w-0 [@media(pointer:coarse)]:min-h-11"
                        data-testid="communication-filter-inbox"
                        aria-label="Valor do filtro de inbox"
                        @update:model-value="value => setDraftNumber('inboxId', value)"
                      />

                      <USelectMenu
                        v-else-if="rule.field === 'assignee'"
                        :model-value="advancedDraft.assigneeId || undefined"
                        :items="selectableItems(assigneeItems)"
                        value-key="value"
                        placeholder="Selecione o responsável"
                        size="sm"
                        class="w-full min-w-0 [@media(pointer:coarse)]:min-h-11"
                        data-testid="communication-filter-assignee"
                        aria-label="Valor do filtro de responsável"
                        @update:model-value="value => setDraftNumber('assigneeId', value)"
                      />

                      <USelectMenu
                        v-else-if="rule.field === 'department'"
                        :model-value="advancedDraft.departmentId || undefined"
                        :items="selectableItems(departmentItems)"
                        value-key="value"
                        placeholder="Selecione a fila"
                        size="sm"
                        class="w-full min-w-0 [@media(pointer:coarse)]:min-h-11"
                        data-testid="communication-filter-department"
                        aria-label="Valor do filtro de fila"
                        @update:model-value="value => setDraftNumber('departmentId', value)"
                      />

                      <USelectMenu
                        v-else-if="rule.field === 'labels'"
                        :model-value="advancedDraft.labelIds"
                        :items="labelItems"
                        value-key="value"
                        multiple
                        placeholder="Selecione marcadores"
                        size="sm"
                        class="w-full min-w-0 [@media(pointer:coarse)]:min-h-11"
                        data-testid="communication-filter-labels"
                        aria-label="Valor do filtro de marcadores"
                        @update:model-value="setDraftLabels"
                      />

                      <div
                        v-else-if="rule.field === 'unread'"
                        class="flex min-h-8 min-w-0 items-center rounded-md bg-default px-2.5 text-xs text-highlighted ring ring-inset ring-default"
                        data-testid="communication-filter-unread"
                      >
                        Sim
                      </div>

                      <div
                        v-else-if="rule.field === 'unassigned'"
                        class="flex min-h-8 min-w-0 items-center rounded-md bg-default px-2.5 text-xs text-highlighted ring ring-inset ring-default"
                        data-testid="communication-filter-unassigned"
                      >
                        Sim
                      </div>

                      <div
                        v-else
                        class="flex min-h-8 min-w-0 items-center truncate rounded-md bg-default px-2.5 text-xs text-highlighted ring ring-inset ring-default"
                        data-testid="communication-filter-contact-draft"
                        :title="contactFilterLabel || undefined"
                      >
                        {{ contactFilterLabel }}
                      </div>

                      <UButton
                        icon="i-lucide-trash-2"
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        square
                        class="absolute end-2 top-2 [@media(pointer:coarse)]:size-11"
                        :aria-label="`Remover regra ${index + 1}`"
                        :data-testid="`communication-filter-rule-remove-${rule.id}`"
                        @click="removeAdvancedRule(rule)"
                      />
                    </div>
                  </template>
                </div>

                <p
                  v-else
                  class="rounded-md bg-elevated/50 px-3 py-4 text-center text-xs text-muted"
                  data-testid="communication-filter-advanced-empty"
                >
                  Nenhum filtro avançado adicionado.
                </p>

                <UButton
                  v-if="canAddAdvancedRule"
                  label="Adicionar filtro"
                  icon="i-lucide-plus"
                  color="primary"
                  variant="link"
                  size="sm"
                  class="-ms-2 mt-2 self-start"
                  data-testid="communication-filter-rule-add"
                  @click="addAdvancedRule"
                />
              </div>

              <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-default p-3">
                <p
                  v-if="advancedDraftHasIncompleteRule"
                  class="basis-full text-xs text-warning"
                  role="status"
                  data-testid="communication-filter-advanced-incomplete"
                >
                  Selecione um valor para cada filtro antes de aplicar.
                </p>
                <UButton
                  label="Limpar filtros"
                  icon="i-lucide-filter-x"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :disabled="!advancedDraftCanClear"
                  data-testid="communication-filter-advanced-clear"
                  @click="clearAdvancedDraft"
                />
                <UButton
                  label="Aplicar filtros"
                  size="sm"
                  :disabled="!advancedDraftDirty || advancedDraftHasIncompleteRule"
                  data-testid="communication-filter-advanced-apply"
                  @click="applyAdvancedFilters"
                />
              </footer>
            </section>
          </template>
        </UPopover>
      </div>
    </div>

    <div
      v-if="hasAdvancedFilters"
      class="flex min-w-0 max-w-full items-center gap-1 overflow-hidden"
      data-testid="communication-filter-active-summary"
      role="group"
      aria-label="Filtros ativos"
    >
      <UButton
        v-for="summary in visibleFilterSummaries"
        :key="summary.key"
        class="min-w-0 flex-1 justify-start overflow-hidden"
        color="neutral"
        variant="soft"
        size="xs"
        :label="summary.label"
        :title="summary.label"
        :aria-label="`Editar filtro: ${summary.label}`"
        :ui="{ label: 'truncate text-left' }"
        data-testid="communication-filter-active-chip"
        @click="openAdvancedFilters"
      />
      <UButton
        v-if="hiddenFilterSummaryCount > 0"
        class="shrink-0"
        color="neutral"
        variant="ghost"
        size="xs"
        :label="`+${hiddenFilterSummaryCount}`"
        :aria-label="`Mais ${hiddenFilterSummaryCount} filtros ativos`"
        data-testid="communication-filter-active-more"
        @click="openAdvancedFilters"
      />
    </div>
  </div>
</template>
