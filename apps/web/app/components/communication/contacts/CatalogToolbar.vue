<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { ContactSortField } from '~/types/communication/contacts'
import type { Inbox } from '~/types/communication/inboxes'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'

const props = defineProps<{
  q: string
  inboxId: number | null
  inboxes: readonly Inbox[]
  inboxesLoading: boolean
  inboxesError: string | null
  definitions: readonly DataTableFilterDefinition[]
  models: readonly DataTableFilterModel[]
  loading: boolean
  resetKey: number
  sort: ContactSortField | null
  sortDirection: 'asc' | 'desc' | null
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:q': [value: string]
  'update:inboxId': [value: number | null]
  'update:models': [models: DataTableFilterModel[]]
  'update:sorting': [sorting: { id: string, desc: boolean }[]]
  'clear': []
  'create': []
  'retryInboxes': []
}>()

const qDraft = ref(props.q)
let searchDebounce: ReturnType<typeof setTimeout> | null = null

watch(() => props.q, (value) => {
  if (value !== qDraft.value) qDraft.value = value
})

function updateSearch(value: string | number) {
  qDraft.value = String(value ?? '')
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => emit('update:q', qDraft.value), 300)
}

function submitSearch() {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = null
  emit('update:q', qDraft.value)
}

onBeforeUnmount(() => {
  if (searchDebounce) clearTimeout(searchDebounce)
})

const sortOptions: Array<{ label: string, value: ContactSortField }> = [
  { label: 'Nome', value: 'name' },
  { label: 'Data de criação', value: 'created_at' },
  { label: 'Identificador', value: 'id' }
]

const sortItems = computed<DropdownMenuItem[][]>(() => [
  [
    { type: 'label', label: 'Ordenar por' },
    ...sortOptions.map(option => ({
      label: option.label,
      icon: props.sort === option.value ? 'i-lucide-check' : undefined,
      onSelect: () => emit('update:sorting', [{
        id: option.value,
        desc: props.sortDirection === 'desc'
      }])
    }))
  ],
  [
    {
      label: 'Crescente',
      icon: props.sortDirection !== 'desc' ? 'i-lucide-check' : undefined,
      onSelect: () => emit('update:sorting', [{ id: props.sort ?? 'name', desc: false }])
    },
    {
      label: 'Decrescente',
      icon: props.sortDirection === 'desc' ? 'i-lucide-check' : undefined,
      onSelect: () => emit('update:sorting', [{ id: props.sort ?? 'name', desc: true }])
    }
  ]
])

const moreItems = computed<DropdownMenuItem[]>(() => props.canManage
  ? [{ label: 'Novo contato', icon: 'i-lucide-user-plus', onSelect: () => emit('create') }]
  : [])

const inboxItems = computed(() => {
  const items = [
    { label: 'Todas as inboxes', value: 0 },
    ...props.inboxes.map(inbox => ({ label: inbox.name, value: inbox.id }))
  ]
  if (props.inboxId && !props.inboxes.some(inbox => inbox.id === props.inboxId)) {
    items.push({ label: `Inbox #${props.inboxId}`, value: props.inboxId })
  }
  return items
})

function updateInbox(value: unknown) {
  const parsed = Number(value)
  emit('update:inboxId', Number.isInteger(parsed) && parsed > 0 ? parsed : null)
}
</script>

<template>
  <div
    class="flex w-full min-w-0 flex-wrap items-center gap-1.5 md:flex-nowrap"
    data-testid="communication-contacts-toolbar"
  >
    <UInput
      :model-value="qDraft"
      icon="i-lucide-search"
      placeholder="Buscar contatos"
      class="min-w-40 flex-[1_1_11rem] md:w-52 md:flex-none"
      size="sm"
      aria-label="Buscar contatos por nome ou telefone"
      data-testid="communication-contacts-q"
      @update:model-value="updateSearch"
      @keyup.enter="submitSearch"
    />

    <USelect
      :model-value="inboxId ?? 0"
      :items="inboxItems"
      :loading="inboxesLoading"
      icon="i-lucide-inbox"
      size="sm"
      class="min-w-40 flex-[1_1_10rem] md:w-44 md:flex-none"
      aria-label="Filtrar contatos por inbox"
      data-testid="communication-contacts-inbox"
      :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
      @update:model-value="updateInbox"
    />

    <UTooltip v-if="inboxesError" :text="inboxesError">
      <UButton
        color="warning"
        variant="ghost"
        icon="i-lucide-triangle-alert"
        square
        aria-label="Recarregar inboxes"
        data-testid="communication-contacts-inbox-retry"
        @click="emit('retryInboxes')"
      />
    </UTooltip>

    <DataTableFilterRoot
      class="min-w-40 flex-[1_1_10rem] md:max-w-72"
      :definitions="[...definitions]"
      :model-value="[...models]"
      :reset-key="resetKey"
      add-label="Filtros"
      :show-clear="models.length > 0"
      @update:model-value="emit('update:models', $event)"
      @clear="emit('clear')"
    />

    <UDropdownMenu :items="sortItems" :content="{ align: 'end' }">
      <UTooltip text="Ordenar contatos">
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-arrow-up-down"
          square
          aria-label="Ordenar contatos"
          data-testid="communication-contacts-sort"
        />
      </UTooltip>
    </UDropdownMenu>

    <UDropdownMenu v-if="moreItems.length" :items="moreItems" :content="{ align: 'end' }">
      <UTooltip text="Mais ações">
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-ellipsis-vertical"
          square
          aria-label="Mais ações de contatos"
          data-testid="communication-contacts-more"
        />
      </UTooltip>
    </UDropdownMenu>

    <span class="sr-only" role="status" aria-live="polite">
      {{ loading ? 'Atualizando contatos' : (inboxesLoading ? 'Carregando inboxes' : '') }}
    </span>
  </div>
</template>
