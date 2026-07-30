<script setup lang="ts">
/**
 * Toolbar da lista de conversas:
 * - Status em tabs (arquétipo inbox + ShellScrollableTabs)
 * - Busca + ordenação + atalhos de leitura/responsável
 * - Filtros de escopo colapsáveis (painel estreito mobile/desktop)
 */
import type {
  CommunicationConversationSortBy,
  CommunicationConversationStatus
} from '~/types/communication'
import {
  COMMUNICATION_SORT_BY_OPTIONS,
  normalizeCommunicationConversationSortBy
} from '~/utils/communication-conversation-sort'

type SelectItem = { label: string, value: number | string }
type StatusTabValue = CommunicationConversationStatus | 'ALL'

const props = defineProps<{
  search: string
  status: StatusTabValue
  inboxId: number
  assigneeId: number
  departmentId: number
  labelIds: number[]
  sortBy: CommunicationConversationSortBy
  unassignedOnly: boolean
  unreadOnly: boolean
  inboxItems: SelectItem[]
  assigneeItems: SelectItem[]
  departmentItems: SelectItem[]
  labelItems: SelectItem[]
}>()

const emit = defineEmits<{
  'update:search': [value: string]
  'update:status': [value: StatusTabValue]
  'update:inboxId': [value: number]
  'update:assigneeId': [value: number]
  'update:departmentId': [value: number]
  'update:labelIds': [value: number[]]
  'update:sortBy': [value: CommunicationConversationSortBy]
  'update:unassignedOnly': [value: boolean]
  'update:unreadOnly': [value: boolean]
  'clear-advanced': []
}>()

const statusTabItems = [
  { label: 'Abertas', value: 'OPEN' },
  { label: 'Pendentes', value: 'PENDING' },
  { label: 'Adiadas', value: 'SNOOZED' },
  { label: 'Resolvidas', value: 'RESOLVED' },
  { label: 'Todas', value: 'ALL' }
] as const

const advancedOpen = ref(false)

/** Conta só escopo colapsável (atalhos de linha ficam fora). */
const advancedActiveCount = computed(() => {
  let count = 0
  if (props.inboxId > 0) count += 1
  if (props.assigneeId > 0) count += 1
  if (props.departmentId > 0) count += 1
  if (props.labelIds.length > 0) count += 1
  return count
})

const hasAdvancedFilters = computed(() => advancedActiveCount.value > 0)

const normalizedSort = computed(() => normalizeCommunicationConversationSortBy(props.sortBy))

const sortLabel = computed(() =>
  COMMUNICATION_SORT_BY_OPTIONS.find(item => item.value === normalizedSort.value)?.label
  || 'Ordenação'
)

const searchModel = computed({
  get: () => props.search,
  set: (value: string) => emit('update:search', value)
})

const statusModel = computed({
  get: () => props.status,
  set: (value: string | number) => emit('update:status', String(value) as StatusTabValue)
})

const inboxModel = computed({
  get: () => props.inboxId,
  set: (value: number) => emit('update:inboxId', value)
})

const assigneeModel = computed({
  get: () => props.assigneeId,
  set: (value: number) => {
    emit('update:assigneeId', value)
    if (value) emit('update:unassignedOnly', false)
  }
})

const departmentModel = computed({
  get: () => props.departmentId,
  set: (value: number) => emit('update:departmentId', value)
})

const labelModel = computed({
  get: () => props.labelIds,
  set: (value: number[] | undefined) => emit('update:labelIds', Array.isArray(value) ? value : [])
})

const sortModel = computed({
  get: () => normalizedSort.value,
  set: (value: unknown) => emit(
    'update:sortBy',
    normalizeCommunicationConversationSortBy(value)
  )
})

function toggleAdvanced(): void {
  advancedOpen.value = !advancedOpen.value
}

function toggleUnread(): void {
  emit('update:unreadOnly', !props.unreadOnly)
}

function toggleUnassigned(): void {
  const next = !props.unassignedOnly
  emit('update:unassignedOnly', next)
  if (next) emit('update:assigneeId', 0)
}

function clearAdvanced(): void {
  emit('update:inboxId', 0)
  emit('update:assigneeId', 0)
  emit('update:departmentId', 0)
  emit('update:labelIds', [])
  emit('clear-advanced')
}

// Expande se o estado/URL já trouxer escopo ativo (deep-link).
watch(
  hasAdvancedFilters,
  (active) => {
    if (active) advancedOpen.value = true
  },
  { immediate: true }
)
</script>

<template>
  <div
    class="flex w-full min-w-0 flex-col gap-2 p-2"
    data-testid="communication-list-filters"
  >
    <!-- Status: filtro primário (preferência de servidor) -->
    <ShellScrollableTabs
      v-model="statusModel"
      :items="[...statusTabItems]"
      size="xs"
      color="neutral"
      variant="pill"
      aria-label="Filtrar conversas por status"
      test-id="communication-filter-status"
      class="min-w-0"
    />

    <!-- Busca sempre visível -->
    <UInput
      v-model="searchModel"
      icon="i-lucide-search"
      placeholder="Buscar contato, telefone ou mensagem"
      size="sm"
      class="w-full min-w-0"
      data-testid="communication-search"
      aria-label="Buscar conversas"
    />

    <!-- Ações compactas: ordenação + atalhos + filtros avançados -->
    <div class="flex min-w-0 items-center gap-1.5">
      <USelectMenu
        v-model="sortModel"
        :items="COMMUNICATION_SORT_BY_OPTIONS"
        value-key="value"
        size="sm"
        icon="i-lucide-arrow-up-down"
        :search-input="false"
        class="min-w-0 flex-1"
        data-testid="communication-filter-sort"
        :aria-label="`Ordenação: ${sortLabel}`"
      />

      <div
        class="flex shrink-0 items-center gap-0.5 rounded-md bg-elevated p-0.5"
        role="group"
        aria-label="Atalhos de filtro"
      >
        <UTooltip text="Somente não lidas">
          <UButton
            icon="i-lucide-mail"
            size="xs"
            square
            :color="unreadOnly ? 'primary' : 'neutral'"
            :variant="unreadOnly ? 'solid' : 'ghost'"
            :aria-pressed="unreadOnly"
            aria-label="Filtrar somente conversas não lidas"
            data-testid="communication-filter-unread"
            @click="toggleUnread"
          />
        </UTooltip>

        <UTooltip text="Somente sem responsável">
          <UButton
            icon="i-lucide-user-round-x"
            size="xs"
            square
            :color="unassignedOnly ? 'primary' : 'neutral'"
            :variant="unassignedOnly ? 'solid' : 'ghost'"
            :aria-pressed="unassignedOnly"
            aria-label="Filtrar somente conversas sem responsável"
            data-testid="communication-filter-unassigned"
            @click="toggleUnassigned"
          />
        </UTooltip>

        <UTooltip text="Filtros de inbox, responsável, fila e marcadores">
          <UButton
            icon="i-lucide-list-filter"
            size="xs"
            square
            :color="hasAdvancedFilters || advancedOpen ? 'primary' : 'neutral'"
            :variant="hasAdvancedFilters || advancedOpen ? 'solid' : 'ghost'"
            :aria-expanded="advancedOpen"
            aria-controls="communication-filter-advanced-panel"
            :aria-label="advancedOpen ? 'Ocultar filtros avançados' : 'Mostrar filtros avançados'"
            data-testid="communication-filter-advanced-toggle"
            @click="toggleAdvanced"
          >
            <template v-if="hasAdvancedFilters" #trailing>
              <span class="text-[10px] font-semibold leading-none">
                {{ advancedActiveCount }}
              </span>
            </template>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <!-- Painel avançado full-width (colapsável) -->
    <div
      v-show="advancedOpen"
      id="communication-filter-advanced-panel"
      class="flex flex-col gap-2 rounded-md border border-default bg-elevated/50 p-2"
      data-testid="communication-filter-advanced-panel"
      role="region"
      aria-label="Filtros avançados da lista"
    >
      <div class="flex items-center justify-between gap-2">
        <p class="truncate text-xs font-medium text-toned">
          Filtros de escopo
        </p>
        <UButton
          v-if="hasAdvancedFilters"
          label="Limpar"
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-filter-x"
          data-testid="communication-filter-advanced-clear"
          @click="clearAdvanced"
        />
      </div>

      <div class="grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">
        <USelectMenu
          v-model="inboxModel"
          :items="inboxItems"
          value-key="value"
          size="sm"
          placeholder="Inbox"
          class="w-full min-w-0"
          data-testid="communication-filter-inbox"
          aria-label="Filtrar por inbox"
        />
        <USelectMenu
          v-model="assigneeModel"
          :items="assigneeItems"
          value-key="value"
          size="sm"
          placeholder="Responsável"
          class="w-full min-w-0"
          data-testid="communication-filter-assignee"
          aria-label="Filtrar por responsável"
        />
        <USelectMenu
          v-model="departmentModel"
          :items="departmentItems"
          value-key="value"
          size="sm"
          placeholder="Fila"
          class="w-full min-w-0"
          data-testid="communication-filter-department"
          aria-label="Filtrar por fila"
        />
        <USelectMenu
          v-model="labelModel"
          :items="labelItems"
          value-key="value"
          multiple
          size="sm"
          placeholder="Marcadores"
          class="w-full min-w-0"
          data-testid="communication-filter-labels"
          aria-label="Filtrar por marcadores"
        />
      </div>
    </div>
  </div>
</template>
