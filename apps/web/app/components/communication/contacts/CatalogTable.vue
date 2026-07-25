<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { CommunicationContact } from '~/types/communication'
import {
  communicationContactDisplayName,
  communicationContactLinkedClientNames,
  communicationContactPrimaryMasked,
  communicationContactStatusColor,
  communicationContactStatusLabel
} from '~/utils/communication-contacts'
import { communicationContactPath } from '~/utils/communication-routes'
import { sortHeader } from '~/utils/table-sort'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

defineProps<{
  items: CommunicationContact[]
  loading: boolean
  stale: boolean
  error: string | null
  emptyKind: 'empty' | 'filtered' | 'error'
  page: number
  total: number
  perPage: number
  sorting: { id: string, desc: boolean }[]
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:page': [page: number]
  'update:perPage': [perPage: number]
  'update:sorting': [sorting: { id: string, desc: boolean }[]]
  'open': [contact: CommunicationContact]
  'retry': []
  'clear': []
  'create': []
}>()

const columns = computed<TableColumn<CommunicationContact>[]>(() => [
  {
    accessorKey: 'name',
    header: ({ column }) => sortHeader('Nome', column),
    enableSorting: true
  },
  { id: 'phone', header: 'WhatsApp', enableSorting: false },
  { id: 'clients', header: 'Clientes', enableSorting: false },
  { id: 'status', header: 'Situação', enableSorting: false },
  {
    accessorKey: 'id',
    header: ({ column }) => sortHeader('ID', column),
    enableSorting: true,
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  { id: 'actions', header: '', enableSorting: false }
])
</script>

<template>
  <UAlert
    v-if="stale"
    color="info"
    variant="subtle"
    icon="i-lucide-refresh-cw"
    title="Atualizando contatos"
    description="A última leitura válida permanece disponível."
    data-testid="communication-contacts-stale"
  />

  <ShellLoadError
    v-else-if="error && items.length"
    color="warning"
    :title="error"
    data-testid="communication-contacts-stale-error"
    @retry="emit('retry')"
  />

  <ShellDataTable
    :sorting="sorting"
    :get-row-id="row => String(row.id)"
    test-id="communication-contacts-table"
    ui-preset="monitoring-compact"
    primary-column-id="name"
    status-column-id="status"
    :summary-column-ids="['phone', 'clients']"
    :columns="columns"
    :data="items"
    :loading="loading"
    :error="items.length ? null : error"
    :empty-kind="error ? 'error' : emptyKind"
    :page="page"
    :total="total"
    :items-per-page="perPage"
    :manual-sorting="true"
    empty-title="Nenhum contato cadastrado"
    empty-description="Crie o primeiro contato ou ajuste a busca."
    per-page-aria-label="Contatos por página"
    footer-test-id="communication-contacts-footer"
    @update:page="emit('update:page', $event)"
    @update:items-per-page="emit('update:perPage', $event)"
    @update:sorting="emit('update:sorting', $event)"
    @retry="emit('retry')"
  >
    <template #name-cell="{ row }">
      <button
        type="button"
        class="min-w-0 text-left"
        data-testid="communication-contact-row-open"
        @click="emit('open', row.original)"
      >
        <p class="truncate font-medium text-highlighted hover:text-primary">
          {{ communicationContactDisplayName(row.original) }}
        </p>
        <p
          v-if="row.original.is_provisional"
          class="truncate text-xs text-muted"
        >
          Sem nome definitivo
        </p>
      </button>
    </template>
    <template #phone-cell="{ row }">
      <span class="font-mono text-sm tabular-nums">
        {{ communicationContactPrimaryMasked(row.original) || '—' }}
      </span>
    </template>
    <template #clients-cell="{ row }">
      <span class="block truncate text-sm">
        {{ communicationContactLinkedClientNames(row.original).join(', ') || 'Sem vínculo' }}
      </span>
    </template>
    <template #status-cell="{ row }">
      <UBadge
        size="md"
        variant="subtle"
        :color="communicationContactStatusColor(row.original)"
        :label="communicationContactStatusLabel(row.original)"
        :class="TABLE_CELL_BADGE_CLASS"
        :ui="TABLE_CELL_BADGE_UI"
      />
    </template>
    <template #id-cell="{ row }">
      <span class="tabular-nums text-muted">{{ row.original.id }}</span>
    </template>
    <template #actions-cell="{ row }">
      <div class="flex justify-end">
        <UButton
          :to="communicationContactPath(row.original.id)"
          icon="i-lucide-arrow-up-right"
          color="neutral"
          variant="ghost"
          size="xs"
          aria-label="Abrir ficha do contato"
          @click.stop
        />
      </div>
    </template>

    <template #empty>
      <ShellListEmpty
        :kind="error ? 'error' : emptyKind"
        :error="error"
        :title="error
          ? 'Falha ao carregar contatos'
          : emptyKind === 'filtered' ? 'Nenhum contato encontrado' : 'Nenhum contato cadastrado'"
        :description="emptyKind === 'filtered'
          ? 'Ajuste a busca ou limpe os filtros.'
          : 'Cadastre um contato WhatsApp para vincular a clientes.'"
        test-id="communication-contacts-empty"
        @retry="emit('retry')"
      >
        <template #actions>
          <UButton
            v-if="!error && emptyKind === 'empty' && canManage"
            icon="i-lucide-plus"
            label="Novo contato"
            @click="emit('create')"
          />
          <UButton
            v-else-if="!error && emptyKind === 'filtered'"
            color="neutral"
            variant="outline"
            icon="i-lucide-filter-x"
            label="Limpar filtros"
            @click="emit('clear')"
          />
        </template>
      </ShellListEmpty>
    </template>
  </ShellDataTable>
</template>
