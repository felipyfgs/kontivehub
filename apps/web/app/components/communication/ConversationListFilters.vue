<script setup lang="ts">
/**
 * Filtros da lista de conversas:
 * - busca direta;
 * - três visões rápidas fixas em tabs sem rolagem;
 * - status/ordenação e filtros avançados em popovers separados.
 */
import type { TabsItem } from '@nuxt/ui'
import type {
  CommunicationConversationQuickView,
  CommunicationConversationSortBy,
  CommunicationConversationStatus
} from '~/types/communication'
import {
  COMMUNICATION_SORT_BY_OPTIONS,
  normalizeCommunicationConversationSortBy
} from '~/utils/communication-conversation-sort'
import {
  activeCommunicationQuickView,
  COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS
} from '~/utils/communication-conversation-quick-views'

type SelectItem = { label: string, value: number | string }
type StatusValue = CommunicationConversationStatus | 'ALL'
type ActiveFilterSummary = { key: string, label: string }
type QuickViewTabItem = TabsItem & {
  testId: string
  ariaLabel: string
  compactLabel?: string
}
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
  selectionActive?: boolean
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
  'apply-quick-view': [view: CommunicationConversationQuickView]
  'clear-contact': []
}>()

const statusItems = [
  { label: 'Todos os status', value: 'ALL' },
  { label: 'Em aberto', value: 'OPEN' },
  { label: 'Pendentes', value: 'PENDING' },
  { label: 'Adiadas', value: 'SNOOZED' },
  { label: 'Resolvidas', value: 'RESOLVED' }
] satisfies Array<{ label: string, value: StatusValue }>

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

const statusOptionsOpen = ref(false)
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

const sortLabel = computed(() =>
  COMMUNICATION_SORT_BY_OPTIONS.find(item => item.value === normalizedSort.value)?.label
  || 'Ordenação'
)

const activeQuickView = computed<CommunicationConversationQuickView | null>(() =>
  activeCommunicationQuickView({
    status: props.status === 'ALL' ? null : props.status,
    unreadOnly: props.unreadOnly,
    unassignedOnly: props.unassignedOnly
  }))

const quickViewTabs: QuickViewTabItem[] = COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS.map(
  item => ({
    ...item,
    testId: `communication-filter-view-${item.value.toLowerCase()}`,
    ariaLabel: item.label
  })
)
const hasCustomStatus = computed(() => props.status !== 'ALL' && activeQuickView.value === null)

const activeFilterSummaries = computed<ActiveFilterSummary[]>(() => {
  const summaries: ActiveFilterSummary[] = []

  if (props.contactFilterLabel) {
    summaries.push({ key: 'contact', label: `Contato: ${props.contactFilterLabel}` })
  }
  if (props.unreadOnly && activeQuickView.value !== 'UNREAD') {
    summaries.push({ key: 'unread', label: 'Não lidas' })
  }
  if (props.unassignedOnly) {
    if (activeQuickView.value !== 'UNASSIGNED') {
      summaries.push({ key: 'unassigned', label: 'Sem responsável' })
    }
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
const advancedActiveLabel = computed(() =>
  `${advancedActiveCount.value} ${advancedActiveCount.value === 1 ? 'ativo' : 'ativos'}`
)

const searchModel = computed({
  get: () => props.search,
  set: (value: string) => emit('update:search', value)
})

function applyQuickView(view: CommunicationConversationQuickView): void {
  emit('apply-quick-view', view)
}

function onQuickViewChange(value: string | number): void {
  if (typeof value !== 'string') return
  if (!COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS.some(item => item.value === value)) return
  applyQuickView(value as CommunicationConversationQuickView)
}

function updateSort(value: unknown): void {
  emit('update:sortBy', normalizeCommunicationConversationSortBy(value))
}

function updateStatus(value: unknown): void {
  const status = String(value) as StatusValue
  if (!statusItems.some(item => item.value === status)) return
  emit('update:status', status)
}

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
  if (open) resetAdvancedDraft()
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
    class="flex w-full min-w-0 max-w-full flex-col gap-1.5 overflow-x-hidden px-2 py-2"
    data-testid="communication-list-filters"
  >
    <div
      class="flex w-full min-w-0 items-center"
      :class="selectionActive
        ? null
        : 'pe-[4.5rem] [@media(pointer:coarse)]:min-h-11 [@media(pointer:coarse)]:pe-24'"
      data-testid="communication-search-row"
    >
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
    </div>

    <Transition
      mode="out-in"
      enter-active-class="transition-all duration-150 ease-out motion-reduce:transition-none"
      enter-from-class="-translate-y-1 opacity-0 motion-reduce:translate-y-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition-all duration-100 ease-in motion-reduce:transition-none"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="-translate-y-1 opacity-0 motion-reduce:translate-y-0"
    >
      <div
        v-if="selectionActive"
        key="selection"
        class="min-w-0"
        data-testid="communication-filter-selection-context"
      >
        <slot name="selection" />
      </div>

      <div
        v-else
        key="views"
        class="flex min-w-0 flex-col gap-1.5"
      >
        <div
          class="relative h-7 w-full min-w-0 [@media(pointer:coarse)]:h-11"
          data-testid="communication-filter-views"
        >
          <UPopover
            :open="statusOptionsOpen"
            :portal="true"
            :content="{
              align: 'end',
              side: 'bottom',
              sideOffset: 6,
              collisionPadding: 8
            }"
            :ui="{ content: 'max-w-[calc(100vw-1rem)] overflow-hidden p-0' }"
            @update:open="statusOptionsOpen = $event"
          >
            <UTooltip text="Status e ordenação">
              <UButton
                icon="i-lucide-sliders-horizontal"
                color="neutral"
                :variant="statusOptionsOpen || hasCustomStatus ? 'soft' : 'ghost'"
                size="sm"
                square
                class="absolute end-9 -top-[2.375rem] size-8 shrink-0 [@media(pointer:coarse)]:end-12 [@media(pointer:coarse)]:-top-[3.125rem] [@media(pointer:coarse)]:size-11"
                :aria-expanded="statusOptionsOpen"
                aria-controls="communication-filter-status-panel"
                :aria-label="hasCustomStatus
                  ? 'Status e ordenação: filtro de status ativo'
                  : 'Status e ordenação'"
                data-testid="communication-filter-status-options"
              />
            </UTooltip>

            <template #content>
              <section
                id="communication-filter-status-panel"
                class="w-[min(18rem,calc(100vw-1rem))] min-w-0 p-3"
                data-testid="communication-filter-status-panel"
                aria-labelledby="communication-filter-status-title"
              >
                <h2
                  id="communication-filter-status-title"
                  class="mb-3 text-sm font-semibold text-highlighted"
                >
                  Exibição da lista
                </h2>
                <div class="space-y-3">
                  <UFormField
                    label="Status"
                    size="sm"
                    class="min-w-0"
                  >
                    <USelectMenu
                      :model-value="status"
                      :items="statusItems"
                      value-key="value"
                      size="sm"
                      class="w-full min-w-0"
                      aria-label="Status das conversas"
                      data-testid="communication-filter-status"
                      @update:model-value="updateStatus"
                    />
                  </UFormField>
                  <UFormField
                    label="Ordenar"
                    size="sm"
                    class="min-w-0"
                  >
                    <USelectMenu
                      :model-value="normalizedSort"
                      :items="COMMUNICATION_SORT_BY_OPTIONS"
                      value-key="value"
                      size="sm"
                      class="w-full min-w-0"
                      :aria-label="`Ordenação: ${sortLabel}`"
                      data-testid="communication-filter-sort"
                      @update:model-value="updateSort"
                    />
                  </UFormField>
                </div>
              </section>
            </template>
          </UPopover>

          <UPopover
            :open="advancedOpen"
            :portal="true"
            :content="{
              align: 'start',
              side: 'bottom',
              sideOffset: 6,
              collisionPadding: 8
            }"
            :ui="{ content: 'max-w-[calc(100vw-1rem)] overflow-hidden' }"
            @update:open="onAdvancedOpenChange"
          >
            <UTooltip text="Filtros avançados">
              <UChip
                :show="hasAdvancedFilters"
                :text="Math.min(advancedActiveCount, 9)"
                size="3xl"
                inset
                class="absolute end-0 -top-[2.375rem] [@media(pointer:coarse)]:-top-[3.125rem]"
              >
                <UButton
                  icon="i-lucide-list-filter"
                  color="neutral"
                  :variant="hasAdvancedFilters || advancedOpen ? 'soft' : 'ghost'"
                  size="sm"
                  square
                  class="size-8 shrink-0 [@media(pointer:coarse)]:size-11"
                  :aria-expanded="advancedOpen"
                  aria-controls="communication-filter-advanced-panel"
                  :aria-label="hasAdvancedFilters
                    ? `Filtros avançados: ${advancedActiveLabel}`
                    : 'Filtros avançados'"
                  data-testid="communication-filter-advanced-trigger"
                />
              </UChip>
            </UTooltip>

            <template #content>
              <section
                id="communication-filter-advanced-panel"
                class="flex max-h-[calc(100vh-1rem)] w-[calc(100vw-1rem)] max-w-[32rem] min-w-0 flex-col overflow-hidden"
                data-testid="communication-filter-advanced-panel"
                aria-labelledby="communication-filter-advanced-title"
              >
                <header class="border-b border-default px-3 py-2.5">
                  <h2
                    id="communication-filter-advanced-title"
                    class="text-sm font-semibold text-highlighted"
                  >
                    Filtrar conversas
                  </h2>
                  <p class="mt-0.5 text-[11px] text-muted">
                    Todas as regras são combinadas com “E”.
                  </p>
                </header>

                <div class="min-w-0 overflow-y-auto overflow-x-hidden p-2.5">
                  <div
                    v-if="advancedRules.length"
                    class="flex min-w-0 flex-col gap-2"
                    role="list"
                    aria-label="Regras de filtro"
                  >
                    <template v-for="(rule, index) in advancedRules" :key="rule.id">
                      <UBadge
                        v-if="index > 0"
                        label="E"
                        color="neutral"
                        variant="soft"
                        size="xs"
                        class="ms-2 self-start text-[10px]"
                        role="presentation"
                        aria-hidden="true"
                      />

                      <div
                        class="relative grid min-w-0 grid-cols-1 gap-2 rounded-md bg-elevated/60 p-2 pe-12 [@media(pointer:coarse)]:pe-14 sm:grid-cols-[minmax(8rem,1fr)_auto_minmax(7rem,0.75fr)_minmax(9rem,1fr)] sm:items-center"
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

                        <UBadge
                          :label="advancedOperatorLabel(rule.field)"
                          color="neutral"
                          variant="outline"
                          size="md"
                          class="min-h-8 min-w-0 justify-start"
                          :data-testid="`communication-filter-rule-operator-${rule.id}`"
                        />

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

                        <UBadge
                          v-else-if="rule.field === 'unread'"
                          label="Sim"
                          color="neutral"
                          variant="outline"
                          size="md"
                          class="min-h-8 min-w-0 justify-start"
                          data-testid="communication-filter-unread"
                        />

                        <UBadge
                          v-else-if="rule.field === 'unassigned'"
                          label="Sim"
                          color="neutral"
                          variant="outline"
                          size="md"
                          class="min-h-8 min-w-0 justify-start"
                          data-testid="communication-filter-unassigned"
                        />

                        <UBadge
                          v-else
                          :label="contactFilterLabel || ''"
                          color="neutral"
                          variant="outline"
                          size="md"
                          class="min-h-8 min-w-0 justify-start"
                          :ui="{ label: 'truncate' }"
                          data-testid="communication-filter-contact-draft"
                          :title="contactFilterLabel || undefined"
                        />

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

                <footer class="flex flex-wrap items-center justify-end gap-1.5 border-t border-default p-2.5">
                  <p
                    v-if="advancedDraftHasIncompleteRule"
                    class="basis-full text-xs text-warning"
                    role="status"
                    data-testid="communication-filter-advanced-incomplete"
                  >
                    Selecione um valor para cada filtro antes de aplicar.
                  </p>
                  <UButton
                    label="Cancelar"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    data-testid="communication-filter-advanced-cancel"
                    @click="onAdvancedOpenChange(false)"
                  />
                  <UButton
                    v-if="advancedDraftCanClear"
                    label="Limpar"
                    icon="i-lucide-filter-x"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    data-testid="communication-filter-advanced-clear"
                    @click="clearAdvancedDraft"
                  />
                  <UButton
                    label="Aplicar"
                    size="sm"
                    :disabled="!advancedDraftDirty || advancedDraftHasIncompleteRule"
                    data-testid="communication-filter-advanced-apply"
                    @click="applyAdvancedFilters"
                  />
                </footer>
              </section>
            </template>
          </UPopover>

          <UTabs
            :items="quickViewTabs"
            :model-value="activeQuickView || '__none__'"
            :content="false"
            activation-mode="manual"
            variant="pill"
            size="xs"
            class="w-full min-w-0"
            :ui="{
              list: 'w-full max-w-full min-w-0 overflow-hidden [@media(pointer:coarse)]:h-11',
              trigger: 'min-w-0 flex-1 [@media(pointer:coarse)]:min-h-9',
              label: 'min-w-0 truncate'
            }"
            aria-label="Visões rápidas das conversas"
            @update:model-value="onQuickViewChange"
          >
            <template #default="{ item }">
              <span
                :data-testid="item.testId"
                class="min-w-0 truncate"
              >
                <template v-if="item.compactLabel">
                  <span class="sr-only">{{ item.ariaLabel }}</span>
                  <span aria-hidden="true" class="hidden min-[360px]:inline">
                    {{ item.label }}
                  </span>
                  <span aria-hidden="true" class="min-[360px]:hidden">
                    {{ item.compactLabel }}
                  </span>
                </template>
                <span v-else>{{ item.label }}</span>
              </span>
            </template>
          </UTabs>
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
    </Transition>
  </div>
</template>
