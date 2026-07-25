<script setup lang="ts">
/**
 * Visão operacional fiscal do escritório.
 * Fonte única: GET /api/v1/fiscal/monitoring/insights (read models locais, fail-closed).
 */
import type { MonitoringInsightsPayload } from '~/types/monitoring-insights'
import { formatDateTime } from '~/utils/format'
import { buildMonitoringKpis } from '~/utils/monitoring-insights'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const loadError = ref<string | null>(null)
const insights = ref<MonitoringInsightsPayload | null>(null)

const partialErrors = computed(() => insights.value?.partial_errors ?? [])
const lastValidAt = computed(() => insights.value?.as_of ?? null)
const initialLoadError = computed(() => Boolean(loadError.value && !insights.value))
const kpis = computed(() => buildMonitoringKpis(insights.value, { loading: loading.value }))

const sectionLabels: Record<string, string> = {
  portfolio: 'empresas',
  pending: 'pendências',
  findings: 'achados',
  rbt12: 'RBT12',
  mailbox: 'caixa postal',
  notifications: 'atividade recente',
  declarations_absence: 'declarações',
  sitfis: 'situação fiscal',
  obligations_progress: 'obrigações'
}

const partialErrorLabel = computed(() => partialErrors.value
  .map(key => sectionLabels[key] ?? key)
  .join(', '))

function sectionError(key: string): string | null {
  if (!partialErrors.value.includes(key)) return null
  return 'Esta seção está temporariamente indisponível.'
}

async function load() {
  const epochAtStart = sessionEpoch.value
  loading.value = true
  loadError.value = null

  try {
    const res = await api.fiscal.monitoringInsights()
    if (epochAtStart !== sessionEpoch.value) return
    insights.value = res.data
  } catch {
    if (epochAtStart !== sessionEpoch.value) return
    loadError.value = insights.value
      ? 'A atualização falhou. A última leitura confirmada continua visível.'
      : 'Não foi possível carregar a visão fiscal. Nenhum indicador foi estimado.'
    toast.add({
      title: loadError.value,
      color: insights.value ? 'warning' : 'error'
    })
  } finally {
    if (epochAtStart === sessionEpoch.value) loading.value = false
  }
}

watch(sessionEpoch, () => {
  insights.value = null
  void load()
})

onMounted(load)
</script>

<template>
  <UDashboardPanel
    id="monitoring-dashboard"
    :ui="{ body: 'overflow-x-hidden' }"
  >
    <template #header>
      <UDashboardNavbar
        title="Dashboard"
        data-testid="page-navbar"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UTooltip text="Atualizar leitura">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-refresh-cw"
              square
              aria-label="Atualizar visão fiscal"
              :loading="loading"
              @click="load"
            />
          </UTooltip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <section
        class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        data-testid="monitoring-by-company-entry"
      >
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold text-highlighted sm:text-2xl">
              Visão geral do escritório
            </h1>
            <UBadge
              v-if="lastValidAt"
              color="neutral"
              variant="subtle"
              icon="i-lucide-database"
              label="Dados locais"
            />
          </div>
          <p class="mt-1 max-w-3xl text-sm text-muted">
            Prioridades, cobertura e atividade consolidadas dos módulos fiscais — sem iniciar consultas externas.
          </p>
          <p
            v-if="lastValidAt"
            class="mt-2 text-xs text-dimmed"
          >
            Leitura confirmada em {{ formatDateTime(lastValidAt) }}
          </p>
        </div>

        <UButton
          to="/clients"
          color="neutral"
          variant="outline"
          icon="i-lucide-building-2"
          label="Por empresa"
          class="shrink-0"
          data-testid="monitoring-by-company-link"
        />
      </section>

      <UAlert
        v-if="initialLoadError"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-x"
        :title="loadError ?? undefined"
        description="Confira sua conexão e tente carregar novamente."
        data-testid="insights-load-error"
      >
        <template #actions>
          <UButton
            size="xs"
            color="neutral"
            variant="outline"
            label="Tentar novamente"
            @click="load"
          />
        </template>
      </UAlert>

      <template v-else>
        <UAlert
          v-if="loadError"
          color="warning"
          variant="subtle"
          icon="i-lucide-refresh-cw-off"
          :title="loadError"
          data-testid="insights-refresh-error"
        >
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Atualizar novamente"
              @click="load"
            />
          </template>
        </UAlert>

        <UAlert
          v-if="partialErrors.length"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Leitura parcial"
          :description="`Indisponível agora: ${partialErrorLabel}. Os demais números foram preservados.`"
          data-testid="insights-partial-error"
        />

        <ShellKpiStrip
          test-id="fiscal-kpis"
          legend="Indicadores confirmados"
          :items="kpis"
          :loading="loading"
          :columns="4"
        />

        <section
          class="space-y-3"
          data-testid="monitoring-priorities-section"
        >
          <div>
            <h2 class="text-base font-semibold text-highlighted">
              O que exige atenção
            </h2>
            <p class="text-sm text-muted">
              Pendências abertas e movimentações recentes do escritório.
            </p>
          </div>
          <div class="grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-12 xl:gap-6">
            <MonitoringInsightsPendingCard
              class="min-w-0 xl:col-span-7"
              :data="insights?.pending ?? null"
              :loading="loading"
              :error="sectionError('pending')"
            />
            <MonitoringInsightsNotificationsFeed
              class="min-w-0 xl:col-span-5"
              :items="insights?.notifications?.items ?? null"
              :loading="loading"
              :error="sectionError('notifications')"
            />
          </div>
        </section>

        <section
          class="space-y-3"
          data-testid="monitoring-health-section"
        >
          <div>
            <h2 class="text-base font-semibold text-highlighted">
              Saúde das carteiras
            </h2>
            <p class="text-sm text-muted">
              Cobertura consolidada por situação e obrigação fiscal.
            </p>
          </div>
          <div class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
            <MonitoringInsightsSitfisDonutCard
              class="min-w-0 lg:col-span-4"
              :data="insights?.sitfis ?? null"
              :loading="loading"
              :error="sectionError('sitfis')"
            />
            <MonitoringInsightsDeclarationsAbsenceCard
              class="min-w-0 lg:col-span-3"
              :data="insights?.declarations_absence ?? null"
              :loading="loading"
              :error="sectionError('declarations_absence')"
            />
            <MonitoringInsightsObligationsProgressCard
              class="min-w-0 lg:col-span-5"
              :items="insights?.obligations_progress ?? null"
              :loading="loading"
              :error="sectionError('obligations_progress')"
            />
          </div>
        </section>

        <section
          class="space-y-3"
          data-testid="monitoring-context-section"
        >
          <div>
            <h2 class="text-base font-semibold text-highlighted">
              Contexto fiscal
            </h2>
            <p class="text-sm text-muted">
              Receita acumulada e triagem das comunicações oficiais já persistidas.
            </p>
          </div>
          <div class="grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-2 xl:gap-6">
            <MonitoringInsightsRbt12ChartCard
              class="min-w-0"
              :data="insights?.rbt12 ?? null"
              :loading="loading"
              :error="sectionError('rbt12')"
            />
            <MonitoringInsightsMailboxBucketsCard
              class="min-w-0"
              :data="insights?.mailbox ?? null"
              :loading="loading"
              :error="sectionError('mailbox')"
            />
          </div>
        </section>

        <ShellPanelAccordion
          :items="[{ label: 'Consulta manual', icon: 'i-lucide-search', value: 'manual', slot: 'manual' as const }]"
          type="single"
          test-id="monitoring-manual-section"
        >
          <template #manual-body>
            <MonitoringManualConsultExplorer data-testid="monitoring-manual-consult-explorer" />
          </template>
        </ShellPanelAccordion>
      </template>
    </template>
  </UDashboardPanel>
</template>
