<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { CommunicationCannedResponse } from '~/types/communication'
import type { CommunicationQuickResponsesCatalog } from '~/composables/useCommunicationQuickResponsesCatalog'
import {
  cannedResponseStatusColor,
  cannedResponseStatusLabel
} from '~/utils/communication-quick-responses'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'

const { catalog } = defineProps<{
  catalog: CommunicationQuickResponsesCatalog
}>()

const page = catalog.page

const columns: TableColumn<CommunicationCannedResponse>[] = [
  { accessorKey: 'shortcut', header: 'Atalho', enableSorting: false },
  { accessorKey: 'title', header: 'Título', enableSorting: false },
  {
    id: 'preview',
    header: 'Prévia',
    enableSorting: false,
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  { id: 'status', header: 'Situação', enableSorting: false },
  { id: 'actions', header: '', enableSorting: false }
]
</script>

<template>
  <div class="flex min-w-0 flex-col gap-3">
    <UAlert
      v-if="catalog.stale.value"
      :color="catalog.loadError.value ? 'warning' : 'neutral'"
      variant="subtle"
      :icon="catalog.loadError.value ? 'i-lucide-triangle-alert' : 'i-lucide-refresh-cw'"
      :title="catalog.loadError.value || 'Atualizando respostas rápidas…'"
      :description="catalog.loadError.value
        ? 'Os dados anteriores continuam visíveis. Tente atualizar novamente.'
        : 'Os dados anteriores permanecem disponíveis durante a atualização.'"
      data-testid="communication-quick-responses-stale"
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
      test-id="communication-quick-responses-table"
      ui-preset="monitoring-compact"
      primary-column-id="shortcut"
      status-column-id="status"
      :summary-column-ids="['title', 'preview']"
      :columns="columns"
      :data="catalog.items.value"
      :loading="catalog.loading.value"
      :error="catalog.loadError.value"
      :empty-kind="catalog.emptyKind.value"
      :page="page"
      :total="catalog.total.value"
      :items-per-page="catalog.perPage.value"
      :show-pagination="catalog.canManage.value"
      :show-per-page="catalog.canManage.value"
      empty-title="Nenhuma resposta rápida"
      empty-description="Crie a primeira resposta ou ajuste a busca."
      per-page-aria-label="Respostas por página"
      footer-test-id="communication-quick-responses-footer"
      @update:page="page = $event"
      @update:items-per-page="catalog.setPerPage"
      @retry="catalog.load"
    >
      <template #shortcut-cell="{ row }">
        <button
          type="button"
          class="min-w-0 text-left"
          data-testid="communication-quick-response-row-open"
          :disabled="!catalog.canManage.value"
          @click="catalog.openEdit(row.original)"
        >
          <p class="font-mono text-sm font-medium text-highlighted hover:text-primary">
            /{{ row.original.shortcut }}
          </p>
        </button>
      </template>
      <template #title-cell="{ row }">
        <span class="block truncate text-sm">{{ row.original.title }}</span>
      </template>
      <template #preview-cell="{ row }">
        <span class="line-clamp-2 text-xs text-muted">{{ row.original.body }}</span>
      </template>
      <template #status-cell="{ row }">
        <UBadge
          size="md"
          variant="subtle"
          :color="cannedResponseStatusColor(row.original)"
          :label="cannedResponseStatusLabel(row.original)"
          :class="TABLE_CELL_BADGE_CLASS"
          :ui="TABLE_CELL_BADGE_UI"
        />
      </template>
      <template #actions-cell="{ row }">
        <div
          v-if="catalog.canManage.value"
          class="flex justify-end gap-0.5"
        >
          <UButton
            icon="i-lucide-pencil"
            color="neutral"
            variant="ghost"
            size="xs"
            aria-label="Editar resposta rápida"
            @click="catalog.openEdit(row.original)"
          />
          <UButton
            icon="i-lucide-copy"
            color="neutral"
            variant="ghost"
            size="xs"
            aria-label="Duplicar resposta rápida"
            @click="catalog.openDuplicate(row.original)"
          />
          <UButton
            v-if="row.original.is_active"
            icon="i-lucide-ban"
            color="error"
            variant="ghost"
            size="xs"
            aria-label="Desativar resposta rápida"
            @click="catalog.openDeactivate(row.original)"
          />
        </div>
      </template>
      <template #empty>
        <ShellListEmpty
          :kind="catalog.loadError.value ? 'error' : catalog.emptyKind.value"
          :title="catalog.loadError.value
            ? 'Não foi possível carregar respostas rápidas'
            : catalog.emptyKind.value === 'filtered'
              ? 'Nenhuma resposta encontrada'
              : 'Nenhuma resposta rápida'"
          :description="catalog.loadError.value
            ? catalog.loadError.value
            : catalog.emptyKind.value === 'filtered'
              ? 'Ajuste a busca ou limpe os filtros.'
              : 'Cadastre textos reutilizáveis com atalho para o composer.'"
          :error="catalog.loadError.value"
          test-id="communication-quick-responses-empty"
          @retry="catalog.load"
        >
          <template
            v-if="!catalog.loadError.value"
            #actions
          >
            <UButton
              v-if="catalog.emptyKind.value === 'empty' && catalog.canManage.value"
              icon="i-lucide-plus"
              label="Nova resposta"
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
