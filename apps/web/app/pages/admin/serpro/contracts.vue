<script setup lang="ts">
/**
 * Histórico de contratos SERPRO (somente leitura sanitizada).
 * Ativação/cadastro direto removidos — usar Configuração versionada.
 */
import type { TableColumn } from '@nuxt/ui'
import type { SerproContractSanitized } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import {
  LIST_FILTER_ACTIONS_ROW,
  LIST_FILTER_TOOLBAR_STACK
} from '~/utils/list-filter-layout'

const api = useApi()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const loadError = ref<string | null>(null)
const rows = ref<SerproContractSanitized[]>([])
const environment = ref('TRIAL')

const {
  page,
  perPage,
  total: pageTotal,
  rows: pagedRows,
  setPage,
  setPerPage,
  resetPage
} = useLocalTablePagination(rows)

const envItems = [
  { label: 'Demonstração SERPRO', value: 'TRIAL' },
  { label: 'Produção', value: 'PRODUCTION' },
  { label: 'Todos', value: 'all' }
]

const columns: TableColumn<SerproContractSanitized>[] = [
  { accessorKey: 'id', header: 'ID', meta: { class: { th: 'w-16', td: 'w-16' } } },
  { accessorKey: 'environment', header: 'Ambiente' },
  { accessorKey: 'status', header: 'Status' },
  {
    id: 'contractor',
    header: 'Contratante',
    cell: ({ row }) =>
      `${row.original.contractor_cnpj_masked || '—'} · ${row.original.contractor_name || ''}`
  },
  {
    id: 'cert',
    header: 'Cert. até',
    cell: ({ row }) => formatDateTime(row.original.cert_valid_to)
  },
  {
    id: 'flags',
    header: 'Flags',
    cell: ({ row }) => {
      const f: string[] = []
      if (row.original.has_pfx) f.push('PFX')
      if (row.original.has_oauth) f.push('OAuth')
      if (row.original.has_cached_token) f.push('token')
      if (row.original.credentials_exposed) f.push('EXPOSTA')
      return f.join(' · ') || '—'
    }
  }
]

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.platform.serpro.contracts.list({
      environment: environment.value === 'all' ? undefined : environment.value
    })
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = res.data || []
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = []
    loadError.value = apiErrorMessage(caught, 'Falha ao listar contratos SERPRO.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

watch(environment, () => {
  resetPage()
  void load()
})
watch(sessionEpoch, () => {
  rows.value = []
  resetPage()
  void load()
})
onMounted(load)
</script>

<template>
  <div data-testid="admin-serpro-contracts">
    <UPageCard
      title="Histórico de contratos"
      variant="naked"
      orientation="horizontal"
      class="mb-6"
      data-testid="admin-serpro-contracts-redirect-hint"
    >
      <UButton
        class="w-fit lg:ms-auto"
        label="Configurar credenciais"
        to="/admin/serpro/configuration"
        icon="i-lucide-settings-2"
        data-testid="admin-serpro-contracts-go-config"
      />
    </UPageCard>

    <div :class="[LIST_FILTER_TOOLBAR_STACK, 'mb-4']">
      <p class="text-sm text-muted">
        {{ rows.length }} {{ rows.length === 1 ? 'contrato' : 'contratos' }}
      </p>
      <div :class="[LIST_FILTER_ACTIONS_ROW, 'items-end']">
        <UFormField label="Ambiente">
          <USelect
            v-model="environment"
            :items="envItems"
            value-key="value"
            class="w-40"
            aria-label="Filtrar contratos por ambiente"
          />
        </UFormField>
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-refresh-cw"
          label="Atualizar"
          :loading="loading"
          @click="load"
        />
      </div>
    </div>

    <UAlert
      v-if="loadError"
      color="error"
      icon="i-lucide-circle-x"
      :title="loadError || 'Não foi possível carregar os contratos'"
      class="mb-4"
    >
      <template #actions>
        <UButton
          label="Tentar novamente"
          color="error"
          variant="soft"
          size="sm"
          :loading="loading"
          @click="load"
        />
      </template>
    </UAlert>

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0' }"
    >
      <ShellDataTable
        ui-preset="dashboard"
        test-id="admin-serpro-contracts-table"
        primary-column-id="id"
        status-column-id="status"
        :summary-column-ids="['environment', 'contractor', 'cert', 'flags']"
        :data="pagedRows"
        :loading="loading"
        :columns="columns"
        :page="page"
        :total="pageTotal"
        :items-per-page="perPage"
        per-page-aria-label="Contratos por página"
        @update:page="setPage"
        @update:items-per-page="setPerPage"
        @retry="load"
      >
        <template #footer>
          <span class="tabular-nums">{{ pageTotal }}</span> contrato(s)
        </template>
        <template #environment-cell="{ row }">
          <UBadge color="neutral" variant="subtle">
            {{ row.original.environment === 'PRODUCTION' ? 'Produção' : 'Demonstração SERPRO' }}
          </UBadge>
        </template>

        <template #status-cell="{ row }">
          <div class="flex flex-wrap items-center gap-1">
            <UBadge
              :color="row.original.status === 'ACTIVE' ? 'success' : row.original.credentials_exposed ? 'error' : 'neutral'"
              variant="subtle"
            >
              {{ row.original.status }}
            </UBadge>
            <SerproProvenanceBadge
              v-if="row.original.credentials_exposed"
              code="possivelmente_bilhetavel"
            />
          </div>
        </template>
        <template #empty>
          <div
            class="flex min-h-40 flex-col items-center justify-center gap-3 p-6 text-center text-sm text-muted"
            role="status"
          >
            <UIcon name="i-lucide-file-search" class="size-7" aria-hidden="true" />
            Nenhum contrato neste filtro.
          </div>
        </template>
      </ShellDataTable>
    </UPageCard>
  </div>
</template>
