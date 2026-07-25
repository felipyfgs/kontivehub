<script setup lang="ts">
/**
 * Preços/orçamento global e conciliação (PLATFORM_ADMIN).
 * Sem detalhe fiscal de tenant — apenas agregados e office_id opaco.
 */
import type { TableColumn } from '@nuxt/ui'
import type { SerproUsageConsolidation, SerproUsageReconciliation } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)
const loading = ref(false)
const loadError = ref<string | null>(null)
const consolidation = ref<SerproUsageConsolidation | null>(null)
const budgetRows = ref<SerproBudgetRow[]>([])
const budgetLoaded = ref(false)
const budgetMissing = ref(false)
const periodLoaded = ref(false)
const reconOpen = ref(false)

interface SerproBudgetRow {
  id?: number
  scope?: string
  office_id?: number | null
  environment?: string
  limit_micros?: number
  reserved_micros?: number
  consumed_micros?: number
  remaining_micros?: number
  cycle_code?: string
  operation_key?: string | null
  is_canary?: boolean
}

const reconForm = reactive({
  official_total_cost_micros: 0,
  official_reference: '',
  notes: ''
})
const saving = ref(false)

function formatMicros(value?: number | null) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—'
  return new Intl.NumberFormat('pt-BR').format(Number(value))
}

function reconciliationColor(status?: string | null) {
  const normalized = String(status || '').toUpperCase()
  if (['MATCHED', 'RECONCILED', 'OK'].includes(normalized)) return 'success' as const
  if (['DIVERGENT', 'MISMATCH', 'FAILED'].includes(normalized)) return 'error' as const
  if (normalized) return 'warning' as const
  return 'neutral' as const
}

const budgetColumns: TableColumn<SerproBudgetRow>[] = [
  {
    id: 'scope',
    header: 'Escopo',
    cell: ({ row }) => [
      row.original.scope,
      row.original.office_id ? `Office #${row.original.office_id}` : null,
      row.original.is_canary ? 'Canário' : null
    ].filter(Boolean).join(' · ') || '—'
  },
  { accessorKey: 'environment', header: 'Ambiente' },
  { accessorKey: 'cycle_code', header: 'Ciclo' },
  {
    id: 'limit',
    header: 'Limite (µBRL)',
    cell: ({ row }) => formatMicros(row.original.limit_micros)
  },
  {
    id: 'consumed',
    header: 'Consumido (µBRL)',
    cell: ({ row }) => formatMicros(row.original.consumed_micros)
  },
  {
    id: 'remaining',
    header: 'Disponível (µBRL)',
    cell: ({ row }) => formatMicros(row.original.remaining_micros)
  }
]

const tenantColumns: TableColumn<{ office_id: number, entry_count?: number, total_quantity?: number, total_estimated_cost_micros?: number }>[] = [
  { accessorKey: 'office_id', header: 'Office ID' },
  { accessorKey: 'entry_count', header: 'Lançamentos' },
  { accessorKey: 'total_quantity', header: 'Quantidade' },
  {
    id: 'cost',
    header: 'Custo est. (µBRL)',
    cell: ({ row }) => formatMicros(row.original.total_estimated_cost_micros)
  }
]

const reconColumns: TableColumn<SerproUsageReconciliation>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'status', header: 'Status' },
  {
    id: 'official',
    header: 'Oficial (µBRL)',
    cell: ({ row }) => formatMicros(row.original.official_total_cost_micros)
  },
  {
    id: 'estimated',
    header: 'Estimado (µBRL)',
    cell: ({ row }) => formatMicros(row.original.estimated_total_cost_micros)
  },
  {
    id: 'difference',
    header: 'Diferença (µBRL)',
    cell: ({ row }) => formatMicros(row.original.difference_micros)
  },
  { accessorKey: 'official_reference', header: 'Referência' }
]

const tenants = computed(() => consolidation.value?.by_tenant || [])
const reconciliations = computed(() => consolidation.value?.reconciliations || [])

const budgetPage = reactive(useLocalTablePagination(budgetRows))
const tenantPage = reactive(useLocalTablePagination(tenants))
const reconPage = reactive(useLocalTablePagination(reconciliations))
const periodValid = computed(() => (
  Number.isInteger(Number(year.value))
  && Number(year.value) >= 2020
  && Number(year.value) <= 2100
  && Number.isInteger(Number(month.value))
  && Number(month.value) >= 1
  && Number(month.value) <= 12
))

let loadSeq = 0

function clearPeriodSnapshot() {
  loading.value = false
  consolidation.value = null
  budgetRows.value = []
  budgetLoaded.value = false
  budgetMissing.value = false
  periodLoaded.value = false
  loadError.value = null
  reconOpen.value = false
  budgetPage.resetPage()
  tenantPage.resetPage()
  reconPage.resetPage()
}

async function load() {
  if (!periodValid.value) {
    toast.add({ title: 'Informe um ano entre 2020 e 2100 e um mês entre 1 e 12.', color: 'warning' })
    return
  }

  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  clearPeriodSnapshot()
  loading.value = true
  try {
    const [consRes, budgetRes] = await Promise.allSettled([
      api.platform.serpro.usage.consolidation({ year: year.value, month: month.value }),
      api.platform.serpro.budgets.show({ year: year.value, month: month.value })
    ])
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return

    if (consRes.status === 'fulfilled') {
      consolidation.value = consRes.value.data
      periodLoaded.value = true
    } else {
      consolidation.value = null
      periodLoaded.value = false
      loadError.value = apiErrorMessage(consRes.reason, 'Falha ao carregar consolidação.')
    }

    if (budgetRes.status === 'fulfilled') {
      budgetRows.value = Array.isArray(budgetRes.value.data)
        ? budgetRes.value.data as SerproBudgetRow[]
        : []
      budgetLoaded.value = true
      budgetMissing.value = false
    } else {
      budgetRows.value = []
      budgetLoaded.value = false
      budgetMissing.value = true
    }
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function recompute() {
  if (!periodLoaded.value) return
  saving.value = true
  try {
    await api.platform.serpro.usage.recompute({ year: year.value, month: month.value })
    toast.add({ title: 'Recomputação solicitada', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao recomputar.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

async function registerRecon() {
  if (!periodLoaded.value) return
  if (reconForm.official_total_cost_micros < 0) {
    toast.add({ title: 'Informe o total oficial em micros de BRL.', color: 'warning' })
    return
  }
  saving.value = true
  try {
    await api.platform.serpro.usage.registerReconciliation({
      year: year.value,
      month: month.value,
      official_total_cost_micros: reconForm.official_total_cost_micros,
      official_reference: reconForm.official_reference || undefined,
      notes: reconForm.notes || undefined
    })
    toast.add({ title: 'Conciliação registrada', color: 'success' })
    reconForm.official_reference = ''
    reconForm.notes = ''
    reconOpen.value = false
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao registrar conciliação.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

watch([year, month], () => {
  loadSeq++
  clearPeriodSnapshot()
})
watch(sessionEpoch, () => {
  clearPeriodSnapshot()
  void load()
})
onMounted(load)
</script>

<template>
  <div
    class="flex flex-col gap-4 sm:gap-6"
    data-testid="admin-serpro-usage"
  >
    <UPageCard
      title="Consumo e conciliação"
      variant="naked"
      orientation="horizontal"
    >
      <UButton
        class="w-fit lg:ms-auto"
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        label="Aplicar período"
        :disabled="!periodValid"
        :loading="loading"
        @click="load"
      />
    </UPageCard>

    <UPageCard
      variant="subtle"
      title="Período de análise"
    >
      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField label="Ano">
          <UInput
            v-model.number="year"
            type="number"
            min="2020"
            max="2100"
            :disabled="loading || saving"
            class="w-full"
            aria-label="Ano da consolidação"
          />
        </UFormField>
        <UFormField label="Mês">
          <UInput
            v-model.number="month"
            type="number"
            min="1"
            max="12"
            :disabled="loading || saving"
            class="w-full"
            aria-label="Mês da consolidação"
          />
        </UFormField>
      </div>
    </UPageCard>

    <UAlert
      v-if="loadError"
      color="error"
      icon="i-lucide-circle-x"
      :title="loadError"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
    />

    <section>
      <UPageCard
        title="Orçamentos ativos"
        variant="naked"
        class="mb-4"
      />

      <UPageCard
        v-if="budgetRows.length"
        variant="subtle"
        :ui="{ container: 'p-0 sm:p-0 gap-y-0' }"
      >
        <ShellDataTable
          ui-preset="dashboard"
          horizontal-scroll
          table-class="w-full min-w-0"
          primary-column-id="scope"
          status-column-id="environment"
          :summary-column-ids="['cycle_code', 'limit', 'consumed', 'remaining']"
          :data="budgetPage.rows"
          :loading="loading"
          :columns="budgetColumns"
          :page="budgetPage.page"
          :total="budgetPage.total"
          :items-per-page="budgetPage.perPage"
          per-page-aria-label="Orçamentos por página"
          @update:page="budgetPage.setPage"
          @update:items-per-page="budgetPage.setPerPage"
        />
      </UPageCard>

      <UEmpty
        v-else-if="budgetMissing"
        icon="i-lucide-server-off"
        title="Orçamentos indisponíveis"
      />

      <UEmpty
        v-else-if="budgetLoaded"
        icon="i-lucide-wallet-cards"
        title="Nenhum orçamento ativo"
      />
    </section>

    <section>
      <UPageCard
        title="Consumo por Office"
        variant="naked"
        orientation="horizontal"
        class="mb-4"
      >
        <UButton
          class="w-fit lg:ms-auto"
          color="neutral"
          variant="soft"
          icon="i-lucide-calculator"
          label="Recomputar consolidação"
          :disabled="!periodLoaded || loading"
          :loading="saving"
          @click="recompute"
        />
      </UPageCard>

      <UPageCard
        v-if="loading || tenants.length"
        variant="subtle"
        :ui="{ container: 'p-0 sm:p-0 gap-y-0' }"
      >
        <template #header>
          <div class="flex items-center gap-2 px-4 py-3">
            <SerproProvenanceBadge code="estimado" />
            <span class="text-xs text-muted">Valores em micros de BRL.</span>
          </div>
        </template>
        <ShellDataTable
          ui-preset="dashboard"
          horizontal-scroll
          table-class="w-full min-w-0"
          primary-column-id="office_id"
          :summary-column-ids="['entry_count', 'total_quantity', 'cost']"
          :data="tenantPage.rows"
          :loading="loading"
          :columns="tenantColumns"
          :page="tenantPage.page"
          :total="tenantPage.total"
          :items-per-page="tenantPage.perPage"
          per-page-aria-label="Offices por página"
          @update:page="tenantPage.setPage"
          @update:items-per-page="tenantPage.setPerPage"
        />
      </UPageCard>

      <UEmpty
        v-else-if="periodLoaded"
        icon="i-lucide-chart-no-axes-column"
        title="Sem consumo no período"
      />
    </section>

    <section>
      <UPageCard
        title="Conciliação oficial"
        variant="naked"
        orientation="horizontal"
        class="mb-4"
      >
        <UButton
          class="w-fit lg:ms-auto"
          icon="i-lucide-scale"
          label="Registrar fatura"
          :disabled="!periodLoaded || loading"
          @click="() => { reconOpen = true }"
        />
      </UPageCard>

      <ShellFormModal
        v-model:open="reconOpen"
        title="Registrar fatura oficial"
        submit-label="Registrar conciliação"
        submit-icon="i-lucide-scale"
        :loading="saving"
        @submit="registerRecon"
      >
        <template #body>
          <div class="space-y-4">
            <UFormField
              label="Total oficial (micros BRL)"
              required
            >
              <UInput
                v-model.number="reconForm.official_total_cost_micros"
                type="number"
                min="0"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Referência oficial">
              <UInput
                v-model="reconForm.official_reference"
                class="w-full"
                placeholder="Nº fatura / ciclo"
                autocomplete="off"
              />
            </UFormField>
            <UFormField label="Notas">
              <UTextarea
                v-model="reconForm.notes"
                class="w-full"
                :rows="3"
              />
            </UFormField>
          </div>
        </template>
      </ShellFormModal>

      <UPageCard
        v-if="loading || reconciliations.length"
        variant="subtle"
        :ui="{ container: 'p-0 sm:p-0 gap-y-0' }"
      >
        <template #header>
          <div class="flex items-center gap-2 px-4 py-3">
            <SerproProvenanceBadge code="conciliado" />
          </div>
        </template>
        <ShellDataTable
          ui-preset="dashboard"
          horizontal-scroll
          table-class="w-full min-w-0"
          primary-column-id="id"
          status-column-id="status"
          :summary-column-ids="['official', 'estimated', 'difference', 'official_reference']"
          :data="reconPage.rows"
          :loading="loading"
          :columns="reconColumns"
          :page="reconPage.page"
          :total="reconPage.total"
          :items-per-page="reconPage.perPage"
          per-page-aria-label="Conciliações por página"
          @update:page="reconPage.setPage"
          @update:items-per-page="reconPage.setPerPage"
        >
          <template #status-cell="{ row }">
            <UBadge
              :color="reconciliationColor(row.original.status)"
              variant="subtle"
            >
              {{ row.original.status || '—' }}
            </UBadge>
          </template>
        </ShellDataTable>
      </UPageCard>

      <UEmpty
        v-else-if="periodLoaded"
        icon="i-lucide-scale"
        title="Nenhuma conciliação registrada"
      />
    </section>
  </div>
</template>
