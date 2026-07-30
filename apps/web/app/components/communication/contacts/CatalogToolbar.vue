<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { CommunicationContactSortField } from '~/types/communication'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'

const props = defineProps<{
  q: string
  definitions: readonly DataTableFilterDefinition[]
  models: readonly DataTableFilterModel[]
  loading: boolean
  resetKey: number
  sort: CommunicationContactSortField | null
  sortDirection: 'asc' | 'desc' | null
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:q': [value: string]
  'update:models': [models: DataTableFilterModel[]]
  'update:sorting': [sorting: { id: string, desc: boolean }[]]
  'clear': []
  'create': []
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

const sortOptions: Array<{ label: string, value: CommunicationContactSortField }> = [
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
</script>

<template>
  <div
    class="flex w-full min-w-0 items-center gap-1.5"
    data-testid="communication-contacts-toolbar"
  >
    <UInput
      :model-value="qDraft"
      icon="i-lucide-search"
      placeholder="Buscar contatos"
      class="min-w-0 flex-1 md:w-56 md:flex-none"
      size="sm"
      aria-label="Buscar contatos por nome ou telefone"
      data-testid="communication-contacts-q"
      @update:model-value="updateSearch"
      @keyup.enter="submitSearch"
    />

    <DataTableFilterRoot
      class="min-w-0 flex-1 md:max-w-72"
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

    <span class="sr-only" role="status" aria-live="polite">{{ loading ? 'Atualizando contatos' : '' }}</span>
  </div>
</template>
