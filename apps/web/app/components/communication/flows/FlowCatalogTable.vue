<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Flow } from '~/types/communication/flows'
import type { CommunicationFlowsCatalog } from '~/composables/useCommunicationFlowsCatalog'
import {
  communicationFlowStatusColor,
  communicationFlowStatusLabel
} from '~/utils/communication-flows'
import { communicationFlowEditorPath } from '~/utils/communication-routes'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'

const { catalog } = defineProps<{
  catalog: CommunicationFlowsCatalog
}>()

const page = catalog.page

const columns: TableColumn<Flow>[] = [
  { accessorKey: 'name', header: 'Nome', enableSorting: false },
  { id: 'status', header: 'Situação', enableSorting: false },
  {
    id: 'updated',
    header: 'Atualizado',
    enableSorting: false,
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  { id: 'actions', header: '', enableSorting: false }
]

function formatUpdated(value?: string | null): string {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(new Date(value))
  } catch {
    return value
  }
}
</script>

<template>
  <div class="flex min-w-0 flex-col gap-3">
    <UAlert
      v-if="catalog.stale.value"
      :color="catalog.loadError.value ? 'warning' : 'neutral'"
      variant="subtle"
      :icon="catalog.loadError.value ? 'i-lucide-triangle-alert' : 'i-lucide-refresh-cw'"
      :title="catalog.loadError.value || 'Atualizando fluxos…'"
      :description="catalog.loadError.value
        ? 'Os dados anteriores continuam visíveis, mas as mutações permanecem bloqueadas até a flag ser confirmada novamente.'
        : 'Os dados anteriores permanecem disponíveis durante a atualização.'"
      data-testid="communication-flows-stale"
    >
      <template
        v-if="catalog.loadError.value"
        #actions
      >
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Tentar novamente"
          :loading="catalog.loading.value"
          @click="catalog.load"
        />
      </template>
    </UAlert>

    <ShellDataTable
      :get-row-id="(row) => String(row.id)"
      test-id="communication-flows-table"
      ui-preset="monitoring-compact"
      primary-column-id="name"
      status-column-id="status"
      :summary-column-ids="['updated']"
      :columns="columns"
      :data="catalog.items.value"
      :loading="catalog.loading.value"
      :error="catalog.loadError.value"
      :empty-kind="catalog.emptyKind.value"
      :page="page"
      :total="catalog.total.value"
      :items-per-page="catalog.perPage.value"
      empty-title="Nenhum fluxo"
      empty-description="Crie o primeiro fluxo ou ajuste a busca."
      per-page-aria-label="Fluxos por página"
      footer-test-id="communication-flows-footer"
      @update:page="page = $event"
      @update:items-per-page="catalog.setPerPage"
      @retry="catalog.load"
    >
      <template #name-cell="{ row }">
        <button
          type="button"
          class="min-w-0 text-left"
          data-testid="communication-flow-row-open"
          @click="catalog.openFlow(row.original)"
        >
          <p class="truncate text-sm font-medium text-highlighted hover:text-primary">
            {{ row.original.name }}
          </p>
        </button>
      </template>
      <template #status-cell="{ row }">
        <UBadge
          size="md"
          variant="subtle"
          :color="communicationFlowStatusColor(row.original)"
          :label="communicationFlowStatusLabel(row.original)"
          :class="TABLE_CELL_BADGE_CLASS"
          :ui="TABLE_CELL_BADGE_UI"
        />
      </template>
      <template #updated-cell="{ row }">
        <span class="text-xs text-muted">
          {{ formatUpdated(row.original.updated_at) }}
        </span>
      </template>
      <template #actions-cell="{ row }">
        <div class="flex justify-end gap-0.5">
          <UButton
            icon="i-lucide-eye"
            color="neutral"
            variant="ghost"
            size="xs"
            aria-label="Abrir fluxo"
            @click="catalog.openFlow(row.original)"
          />
          <UButton
            icon="i-lucide-workflow"
            color="neutral"
            variant="ghost"
            size="xs"
            aria-label="Abrir editor visual"
            :to="communicationFlowEditorPath(row.original.id)"
            data-testid="communication-flow-row-editor"
          />
          <UButton
            v-if="catalog.canManage.value && row.original.status !== 'paused'"
            icon="i-lucide-pause"
            color="neutral"
            variant="ghost"
            size="xs"
            aria-label="Pausar fluxo"
            :disabled="Boolean(catalog.mutationBlocked.value)"
            @click="catalog.openPause(row.original)"
          />
        </div>
      </template>

      <template #empty>
        <ShellListEmpty
          :kind="catalog.loadError.value ? 'error' : catalog.emptyKind.value"
          :title="catalog.loadError.value
            ? 'Não foi possível carregar os fluxos'
            : catalog.emptyKind.value === 'filtered'
              ? 'Nenhum fluxo encontrado'
              : 'Nenhum fluxo'"
          :description="catalog.loadError.value
            ? catalog.loadError.value
            : catalog.emptyKind.value === 'filtered'
              ? 'Ajuste a busca ou limpe os filtros.'
              : 'Crie um fluxo, monte o grafo no editor visual, publique e vincule a uma inbox.'"
          :error="catalog.loadError.value"
          test-id="communication-flows-empty"
          @retry="catalog.load"
        >
          <template
            v-if="!catalog.loadError.value"
            #actions
          >
            <UButton
              v-if="catalog.emptyKind.value === 'empty' && catalog.canManage.value"
              icon="i-lucide-plus"
              label="Novo fluxo"
              :disabled="Boolean(catalog.mutationBlocked.value)"
              @click="catalog.openCreate"
            />
            <UButton
              v-else-if="catalog.emptyKind.value === 'filtered'"
              color="neutral"
              variant="outline"
              icon="i-lucide-filter-x"
              label="Limpar filtros"
              @click="catalog.clearFilters"
            />
          </template>
        </ShellListEmpty>
      </template>
    </ShellDataTable>
  </div>
</template>
