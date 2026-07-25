<script setup lang="ts">
/**
 * Painel de consumo do tenant (15.9).
 * Sem fatura global, custo interno de outros offices ou orçamento da software house.
 */
import type { TableColumn } from '@nuxt/ui'
import type { OfficeUsageEntry, OfficeUsageSummary } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { normalizeListTablePerPage } from '~/utils/table-ui'

const api = useApi()
const { sessionEpoch } = useDashboard()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)
const loading = ref(false)
const loadError = ref<string | null>(null)
const usage = ref<OfficeUsageSummary | null>(null)
const entries = ref<OfficeUsageEntry[]>([])
const entriesTotal = ref(0)
const page = ref(1)
const perPage = ref(20)

const summary = computed(() => usage.value?.summary || null)
const byServiceRows = computed(() =>
  (usage.value?.by_service || []) as unknown as Record<string, unknown>[]
)
const servicePage = reactive(useLocalTablePagination(byServiceRows))

const serviceColumns: TableColumn<Record<string, unknown>>[] = [
  { accessorKey: 'service_code', header: 'Serviço' },
  { accessorKey: 'system_code', header: 'Sistema' },
  { accessorKey: 'consumption_class', header: 'Classe' },
  { accessorKey: 'total_quantity', header: 'Quantidade' },
  { accessorKey: 'entry_count', header: 'Lançamentos' },
  { accessorKey: 'billable_attempt_count', header: 'Tentativas faturáveis' }
]

const entryColumns: TableColumn<OfficeUsageEntry>[] = [
  {
    accessorKey: 'occurred_at',
    header: 'Quando',
    cell: ({ row }) => formatDateTime(row.original.occurred_at)
  },
  {
    id: 'svc',
    header: 'Operação',
    cell: ({ row }) =>
      [row.original.system_code, row.original.service_code, row.original.operation_code]
        .filter(Boolean)
        .join(' / ') || '—'
  },
  { accessorKey: 'quantity', header: 'Qtd' },
  { accessorKey: 'result', header: 'Resultado' },
  {
    accessorKey: 'client_id',
    header: 'Cliente',
    cell: ({ row }) => row.original.client_id ?? '—'
  },
  {
    id: 'provenance',
    header: 'Origem',
    cell: ({ row }) => row.original.consumption_class || (row.original.is_billable_attempt ? 'billable' : '—')
  }
]

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const [sumRes, entRes] = await Promise.allSettled([
      api.office.serproUsage.summary({ year: year.value, month: month.value }),
      api.office.serproUsage.entries({
        year: year.value,
        month: month.value,
        page: page.value,
        per_page: perPage.value
      })
    ])

    if (seq !== loadSeq || epoch !== sessionEpoch.value) return

    if (sumRes.status === 'fulfilled') {
      usage.value = sumRes.value.data
    } else {
      usage.value = null
      loadError.value = apiErrorMessage(sumRes.reason, 'Falha ao carregar consumo do escritório.')
    }

    if (entRes.status === 'fulfilled') {
      const body = entRes.value as Record<string, unknown>
      entries.value = (body.data as OfficeUsageEntry[]) || []
      entriesTotal.value = Number(
        (body.total as number | undefined)
        ?? (body.meta as { total?: number } | undefined)?.total
        ?? entries.value.length
      )
    } else {
      entries.value = []
      entriesTotal.value = 0
    }
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

function setPerPage(next: number) {
  const target = normalizeListTablePerPage(next, 20)
  if (perPage.value === target) return
  perPage.value = target
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
}

watch([year, month, page], () => {
  void load()
})
watch(sessionEpoch, () => {
  usage.value = null
  entries.value = []
  entriesTotal.value = 0
  void load()
})
onMounted(load)
</script>

<template>
  <!-- Chrome: ShellSectionHeader (template settings). -->
  <div>
    <ShellSectionHeader
      title="Consumo do plano"
      description="Uso deste escritório."
      test-id="settings-usage-header"
    >
      <div class="flex w-full flex-wrap items-end gap-2 lg:w-fit">
        <UFormField label="Ano">
          <UInput
            v-model.number="year"
            type="number"
            min="2020"
            max="2100"
            class="w-28"
          />
        </UFormField>
        <UFormField label="Mês">
          <UInput
            v-model.number="month"
            type="number"
            min="1"
            max="12"
            class="w-24"
          />
        </UFormField>
        <UButton
          color="neutral"
          icon="i-lucide-refresh-cw"
          label="Atualizar"
          :loading="loading"
          class="w-fit"
          @click="load"
        />
      </div>
    </ShellSectionHeader>

    <div class="flex flex-col gap-4 sm:gap-6 lg:gap-12">
      <ShellLoadError
        v-if="loadError"
        :title="loadError"
        test-id="usage-load-error"
        @retry="load"
      />

      <div
        v-if="loading && !summary"
        class="text-sm text-muted"
      >
        Carregando…
      </div>

      <UPageGrid
        v-else-if="summary"
        class="gap-4 sm:grid-cols-2"
        data-testid="usage-kpis"
      >
        <UPageCard
          variant="subtle"
          title="Período"
        >
          <p class="text-2xl font-semibold">
            {{ summary.period_month }}/{{ summary.period_year }}
          </p>
        </UPageCard>
        <UPageCard
          variant="subtle"
          title="Usado"
        >
          <p class="text-2xl font-semibold">
            {{ summary.used_quantity }}
          </p>
        </UPageCard>
        <UPageCard
          variant="subtle"
          title="Franquia"
        >
          <p class="text-2xl font-semibold">
            {{ summary.franchise_quota ?? '—' }}
          </p>
        </UPageCard>
        <UPageCard
          variant="subtle"
          title="Saldo"
        >
          <p class="text-2xl font-semibold">
            {{ summary.remaining ?? '—' }}
          </p>
          <UBadge
            v-if="summary.alert_threshold_reached"
            color="warning"
            variant="subtle"
            class="mt-2"
          >
            Alerta de franquia
          </UBadge>
        </UPageCard>
      </UPageGrid>

      <UAlert
        v-else-if="!loading"
        color="neutral"
        icon="i-lucide-inbox"
        title="Sem dados de consumo"
      />

      <UPageCard
        v-if="usage?.by_service?.length"
        variant="subtle"
        title="Por serviço"
      >
        <ShellDataTable
          ui-preset="dashboard"
          primary-column-id="service_code"
          status-column-id="consumption_class"
          :summary-column-ids="['system_code', 'total_quantity', 'entry_count']"
          :data="servicePage.rows"
          :columns="serviceColumns"
          :page="servicePage.page"
          :total="servicePage.total"
          :items-per-page="servicePage.perPage"
          per-page-aria-label="Serviços por página"
          @update:page="servicePage.setPage"
          @update:items-per-page="servicePage.setPerPage"
        />
      <!-- Intencionalmente sem coluna de preço global / fatura consolidada -->
      </UPageCard>

      <UPageCard
        variant="subtle"
        title="Lançamentos do período"
        description="Operações deste escritório."
      >
        <div class="mb-3 flex flex-wrap gap-2">
          <SerproProvenanceBadge code="estimado" />
          <SerproProvenanceBadge code="real" />
          <SerproProvenanceBadge code="simulado" />
          <span class="text-xs text-muted">Origem dos dados.</span>
        </div>
        <UEmpty
          v-if="!entries.length"
          icon="i-lucide-receipt"
          title="Nenhum lançamento no período"
        />
        <ShellDataTable
          v-else
          ui-preset="dashboard"
          primary-column-id="occurred_at"
          status-column-id="result"
          :summary-column-ids="['svc', 'quantity', 'provenance']"
          :data="entries"
          :columns="entryColumns"
          :page="page"
          :total="entriesTotal"
          :items-per-page="perPage"
          per-page-aria-label="Lançamentos por página"
          @update:page="page = $event"
          @update:items-per-page="setPerPage"
        >
          <template #provenance-cell="{ row }">
            <SerproProvenanceBadge
              :consumption-class="row.original.consumption_class"
              :is-billable-attempt="row.original.is_billable_attempt"
              :result="row.original.result"
            />
          </template>
          <template #footer>
            <span class="tabular-nums">{{ entriesTotal }}</span> lançamento(s)
          </template>
        </ShellDataTable>
      </UPageCard>
    </div>
  </div>
</template>
