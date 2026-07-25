<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OperationsSummary } from '~/types/api'
import { COMPACT_DASHBOARD_TABLE_UI } from '~/utils/table-ui'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

interface TotalRow {
  label: string
  value: number
  to: string
}

const data = computed<TotalRow[]>(() => [{
  label: 'Clientes ativos',
  value: props.summary?.clients ?? 0,
  to: '/clients'
}, {
  label: 'Documentos',
  value: props.summary?.notes ?? 0,
  to: '/docs'
}, {
  label: 'Exportações prontas',
  value: props.summary?.exports_ready ?? 0,
  to: '/exports'
}, {
  label: 'Exportações pendentes',
  value: props.summary?.exports_pending ?? 0,
  to: '/exports'
}])

const columns: TableColumn<TotalRow>[] = [{
  accessorKey: 'label',
  header: 'Indicador'
}, {
  accessorKey: 'value',
  header: 'Total'
}, {
  id: 'actions',
  header: () => h('div', { class: 'text-right' }, 'Ação')
}]
</script>

<template>
  <div
    data-testid="home-totals"
    class="min-w-0 shrink-0"
  >
    <UTable
      :data="data"
      :columns="columns"
      :loading="loading"
      class="min-w-0 shrink-0"
      :ui="COMPACT_DASHBOARD_TABLE_UI"
    >
      <template #label-cell="{ row }">
        <span class="font-medium text-highlighted">{{ row.original.label }}</span>
      </template>
      <template #value-cell="{ row }">
        {{ loading && !summary ? '…' : row.original.value }}
      </template>
      <template #actions-cell="{ row }">
        <div class="text-right">
          <UButton
            :to="row.original.to"
            color="neutral"
            variant="ghost"
            icon="i-lucide-arrow-right"
            square
            :aria-label="`Abrir ${row.original.label}`"
          />
        </div>
      </template>
    </UTable>
  </div>
</template>
