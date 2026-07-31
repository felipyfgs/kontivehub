<script setup lang="ts">
import type { WorkDepartment, WorkKpis } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import {
  buildWorkDashboardKpis,
  buildWorkDepartmentRows,
  workQueueIntentForKpi,
  workCompletionPercent,
  workOperationalLevel
} from '~/utils/work-strategic-dashboard'
import type { DashboardKpiItem } from '~/utils/kpi-ui'
import {
  formatCompetence,
  formatDueDate,
  workRiskColor,
  workRiskLabel,
  type SemanticColor
} from '~/utils/work-labels'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const data = ref<WorkKpis | null>(null)
const lastGood = ref<WorkKpis | null>(null)
const departments = ref<WorkDepartment[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const stale = ref(false)

async function load() {
  const epoch = sessionEpoch.value
  const hadSnapshot = lastGood.value !== null
  loading.value = true

  if (!hadSnapshot) error.value = null

  try {
    const [kpisResult, departmentsResult] = await Promise.allSettled([
      api.work.kpis(),
      api.work.departments.list({ per_page: 100, is_active: true })
    ])

    if (epoch !== sessionEpoch.value) return

    if (kpisResult.status === 'fulfilled') {
      data.value = kpisResult.value.data
      lastGood.value = kpisResult.value.data
      error.value = null
      stale.value = false
    } else {
      const message = apiErrorMessage(kpisResult.reason, 'Não foi possível carregar a visão estratégica.')
      error.value = message

      if (lastGood.value) {
        data.value = lastGood.value
        stale.value = true
      } else {
        data.value = null
        toast.add({ title: message, color: 'error' })
      }
    }

    if (departmentsResult.status === 'fulfilled') {
      departments.value = departmentsResult.value.data || []
    }
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(sessionEpoch, () => {
  data.value = null
  lastGood.value = null
  departments.value = []
  error.value = null
  stale.value = false
  void load()
})

const kpiCards = computed(() => data.value ? buildWorkDashboardKpis(data.value) : [])
function openQueue(filters: Record<string, unknown> = {}) {
  publishSurfaceNavigationIntent(WORK_SURFACES.queue, filters)
  void navigateTo('/work/tasks')
}

const completionPercent = computed(() => data.value ? workCompletionPercent(data.value) : 0)
const departmentRows = computed(() => data.value
  ? buildWorkDepartmentRows(data.value, departments.value)
  : [])
const operationalLevel = computed(() => data.value ? workOperationalLevel(data.value) : null)
const relevantTaskTotal = computed(() => data.value
  ? data.value.kpis.total_open + data.value.kpis.concluidas
  : 0)
const performanceKpis = computed<DashboardKpiItem[]>(() => {
  if (!data.value) return []

  const cards = new Map(kpiCards.value.map(card => [card.key, card]))
  const completed: DashboardKpiItem = {
    key: 'completed',
    title: 'Concluídas',
    value: data.value.kpis.concluidas,
    to: '/work/tasks',
    icon: 'i-lucide-circle-check-big',
    tone: 'success'
  }

  return [
    cards.get('open'),
    cards.get('progress'),
    completed,
    cards.get('overdue'),
    cards.get('today'),
    cards.get('fine')
  ].filter((card): card is DashboardKpiItem => Boolean(card))
})
const operationalSummary = computed(() => {
  if (!data.value) return []

  return [
    {
      key: 'open',
      label: 'Abertas',
      value: data.value.kpis.total_open,
      to: '/work/tasks',
      color: 'neutral' as const
    },
    {
      key: 'progress',
      label: 'Em progresso',
      value: data.value.kpis.em_progresso,
      to: '/work/tasks',
      color: 'info' as const
    },
    {
      key: 'completed',
      label: 'Concluídas',
      value: data.value.kpis.concluidas,
      to: '/work/tasks',
      color: 'success' as const
    },
    {
      key: 'unassigned',
      label: 'Sem responsável',
      value: data.value.kpis.sem_responsavel,
      to: '/work/tasks',
      color: data.value.kpis.sem_responsavel > 0 ? 'warning' as const : 'neutral' as const
    }
  ]
})
const lastUpdated = computed(() => {
  if (!data.value?.generated_at) return null
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: data.value.tenant_timezone
    }).format(new Date(data.value.generated_at))
  } catch {
    return null
  }
})

function departmentProgressColor(row: { fine: number, overdue: number }): SemanticColor {
  if (row.fine > 0) return 'error'
  if (row.overdue > 0) return 'warning'
  return 'primary'
}
</script>

<template>
  <ShellPagePanel
    id="work-overview"
    test-id="work-strategic-dashboard"
    body-class="gap-5"
  >
    <template #header>
      <ShellPageNavbar title="Trabalho">
        <template #right>
          <UButton
            to="/work/tasks"
            label="Abrir tarefas"
            icon="i-lucide-list-checks"
            class="hidden sm:inline-flex"
          />
          <ShellNavbarRefresh
            :loading="loading"
            aria-label="Atualizar visão estratégica"
            test-id="work-dashboard-refresh"
            @click="load"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <section
        class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        aria-labelledby="work-strategic-heading"
      >
        <div class="min-w-0">
          <h1
            id="work-strategic-heading"
            class="text-xl font-semibold text-highlighted"
          >
            Visão estratégica
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted">
            Compare carga, risco e responsabilidade para direcionar a operação do escritório.
          </p>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
          <UBadge
            v-if="data?.today"
            color="neutral"
            variant="subtle"
            icon="i-lucide-calendar-check"
            :label="`Posição em ${formatDueDate(data.today)}`"
          />
          <span v-if="lastUpdated">Atualizado {{ lastUpdated }}</span>
        </div>
      </section>

      <UAlert
        v-if="stale && error"
        color="warning"
        variant="subtle"
        icon="i-lucide-wifi-off"
        title="Mostrando o último snapshot disponível"
        :description="error"
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => load()
        }]"
        data-testid="work-dashboard-stale"
      />

      <template v-if="loading && !data">
        <div
          class="grid min-w-0 grid-cols-2 gap-2 lg:grid-cols-6"
          data-testid="work-dashboard-loading"
          role="status"
          aria-busy="true"
          aria-label="Carregando indicadores estratégicos"
        >
          <USkeleton
            v-for="index in 6"
            :key="index"
            class="h-24 rounded-lg"
          />
        </div>
        <div class="grid min-w-0 gap-5 lg:grid-cols-2">
          <USkeleton class="h-72 rounded-lg" />
          <USkeleton class="h-72 rounded-lg" />
        </div>
      </template>

      <UAlert
        v-else-if="error && !data"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-x"
        title="Visão estratégica indisponível"
        :description="error"
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => load()
        }]"
        data-testid="work-dashboard-error"
      />

      <template v-else-if="data">
        <section class="min-w-0" data-testid="work-dashboard-performance" aria-labelledby="work-performance-heading">
          <div class="mb-3">
            <h2 id="work-performance-heading" class="text-sm font-semibold text-highlighted">
              Carga consolidada
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Posição dos processos e tarefas neste snapshot.
            </p>
          </div>
          <ShellKpiStrip
            :items="performanceKpis"
            :loading="loading"
            :columns="6"
            legend="Situação das tarefas"
            test-id="work-dashboard-kpis"
            @select="(key) => openQueue(workQueueIntentForKpi(key))"
          />
        </section>

        <section
          class="grid min-w-0 divide-y divide-default border-y border-default md:grid-cols-2 md:divide-x md:divide-y-0"
          aria-label="Leitura do desempenho"
        >
          <div class="min-w-0 py-3 md:pe-5" data-testid="work-dashboard-completion">
            <div class="flex items-baseline justify-between gap-3">
              <h2 class="text-sm font-medium text-highlighted">
                Conclusão da carga
              </h2>
              <strong class="text-lg font-semibold tabular-nums text-highlighted">{{ completionPercent }}%</strong>
            </div>
            <p class="mt-1 text-xs text-muted">
              {{ data.kpis.concluidas }} de {{ relevantTaskTotal }} tarefas no consolidado.
            </p>
          </div>

          <div
            v-if="operationalLevel"
            class="min-w-0 py-3 md:ps-5"
            data-testid="work-dashboard-level"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="text-sm font-medium text-highlighted">
                Nível operacional
              </h2>
              <UBadge :color="operationalLevel.tone" variant="subtle" :label="operationalLevel.label" />
            </div>
            <p class="mt-1 text-xs leading-5 text-muted">
              {{ operationalLevel.description }}
            </p>
            <p v-if="relevantTaskTotal === 0" class="mt-1 text-xs font-medium text-muted">
              Sem carga no snapshot
            </p>
            <p v-else-if="operationalLevel.nextLabel" class="mt-1 text-xs font-medium text-toned">
              Faltam {{ operationalLevel.remainingToNext }} conclusões para {{ operationalLevel.nextLabel }}.
            </p>
            <p v-else class="mt-1 text-xs font-medium text-success">
              Faixa mais alta alcançada.
            </p>
          </div>
        </section>

        <div class="grid min-w-0 gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,2fr)_minmax(16rem,0.8fr)]">
          <section class="min-w-0" data-testid="work-dashboard-departments" aria-labelledby="work-departments-heading">
            <div class="mb-3">
              <h2 id="work-departments-heading" class="text-sm font-semibold text-highlighted">
                Carga por departamento
              </h2>
              <p class="mt-0.5 text-xs text-muted">
                Volume, risco e avanço agrupados por área.
              </p>
            </div>

            <template v-if="departmentRows.length">
              <div class="hidden md:block" data-testid="work-dashboard-departments-table">
                <table class="w-full table-fixed text-left text-sm">
                  <caption class="sr-only">
                    Carga operacional dos departamentos no snapshot atual
                  </caption>
                  <thead class="border-y border-default text-xs text-muted">
                    <tr>
                      <th scope="col" class="w-[28%] px-3 py-2 font-medium">
                        Departamento
                      </th>
                      <th scope="col" class="w-[34%] px-3 py-2 font-medium">
                        Carga e risco
                      </th>
                      <th scope="col" class="w-[28%] px-3 py-2 font-medium">
                        Conclusão
                      </th>
                      <th scope="col" class="w-[10%] px-3 py-2 text-right font-medium">
                        Ação
                      </th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-default">
                    <tr v-for="row in departmentRows" :key="String(row.id)">
                      <th scope="row" class="min-w-0 px-3 py-2.5 font-normal">
                        <NuxtLink
                          :to="row.to"
                          class="block truncate font-medium text-highlighted hover:text-primary hover:underline"
                          @click.prevent="openQueue(row.filters)"
                        >
                          {{ row.name }}
                        </NuxtLink>
                        <span class="mt-0.5 block text-xs text-muted">{{ row.open }} abertas · {{ row.completed }} concluídas</span>
                      </th>
                      <td class="px-3 py-2.5">
                        <div class="flex flex-wrap gap-1">
                          <UBadge
                            v-if="row.overdue"
                            color="warning"
                            variant="subtle"
                            size="sm"
                            :label="`${row.overdue} atrasadas`"
                          />
                          <UBadge
                            v-if="row.fine"
                            color="error"
                            variant="subtle"
                            size="sm"
                            :label="`${row.fine} em multa`"
                          />
                          <UBadge
                            v-if="row.unassigned"
                            color="info"
                            variant="subtle"
                            size="sm"
                            :label="`${row.unassigned} sem responsável`"
                          />
                          <span v-if="!row.overdue && !row.fine && !row.unassigned" class="text-xs text-muted">Sem exceções</span>
                        </div>
                      </td>
                      <td class="px-3 py-2.5">
                        <div class="flex items-center gap-2">
                          <UProgress
                            :model-value="row.completedPercent"
                            :color="departmentProgressColor(row)"
                            size="sm"
                            :aria-label="`${row.name}: ${row.completedPercent}% concluído`"
                          />
                          <span class="w-10 shrink-0 text-right text-xs font-semibold tabular-nums text-highlighted">{{ row.completedPercent }}%</span>
                        </div>
                      </td>
                      <td class="px-3 py-2.5 text-right">
                        <UButton
                          :to="row.to"
                          color="neutral"
                          variant="ghost"
                          icon="i-lucide-arrow-up-right"
                          square
                          :aria-label="`Abrir fila de ${row.name}`"
                          @click.prevent="openQueue(row.filters)"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <ul
                class="divide-y divide-default border-y border-default md:hidden"
                aria-label="Carga dos departamentos"
                data-testid="work-dashboard-departments-mobile"
              >
                <li v-for="row in departmentRows" :key="String(row.id)" class="min-w-0 py-3">
                  <div class="flex min-w-0 items-start justify-between gap-3">
                    <div class="min-w-0">
                      <NuxtLink
                        :to="row.to"
                        class="block truncate font-medium text-highlighted hover:text-primary hover:underline"
                        @click.prevent="openQueue(row.filters)"
                      >
                        {{ row.name }}
                      </NuxtLink>
                      <p class="mt-0.5 text-xs text-muted">
                        {{ row.open }} abertas · {{ row.completed }} concluídas
                      </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums text-highlighted">{{ row.completedPercent }}%</span>
                  </div>
                  <UProgress
                    class="mt-2"
                    :model-value="row.completedPercent"
                    :color="departmentProgressColor(row)"
                    size="sm"
                    :aria-label="`${row.name}: ${row.completedPercent}% concluído`"
                  />
                  <div class="mt-2 flex min-w-0 flex-wrap items-center gap-1.5">
                    <UBadge
                      v-if="row.overdue"
                      color="warning"
                      variant="subtle"
                      size="sm"
                      :label="`${row.overdue} atrasadas`"
                    />
                    <UBadge
                      v-if="row.fine"
                      color="error"
                      variant="subtle"
                      size="sm"
                      :label="`${row.fine} em multa`"
                    />
                    <UBadge
                      v-if="row.unassigned"
                      color="info"
                      variant="subtle"
                      size="sm"
                      :label="`${row.unassigned} sem responsável`"
                    />
                    <UButton
                      :to="row.to"
                      color="neutral"
                      variant="link"
                      size="xs"
                      label="Abrir fila"
                      trailing-icon="i-lucide-arrow-right"
                      class="ms-auto"
                      @click.prevent="openQueue(row.filters)"
                    />
                  </div>
                </li>
              </ul>
            </template>

            <ShellListEmpty
              v-else
              title="Sem atividade por departamento"
              description="As áreas aparecerão aqui quando houver tarefas operacionais no escritório."
              test-id="work-dashboard-departments-empty"
            />
          </section>

          <aside class="min-w-0" aria-labelledby="work-summary-heading" data-testid="work-dashboard-operational-summary">
            <div class="mb-3">
              <h2 id="work-summary-heading" class="text-sm font-semibold text-highlighted">
                Situação operacional
              </h2>
              <p class="mt-0.5 text-xs text-muted">
                Atalhos para comparar o estoque de tarefas.
              </p>
            </div>
            <ul class="divide-y divide-default border-y border-default">
              <li v-for="item in operationalSummary" :key="item.key">
                <NuxtLink
                  :to="item.to"
                  class="flex min-w-0 items-center justify-between gap-3 py-2.5 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  @click.prevent="openQueue(workQueueIntentForKpi(item.key))"
                >
                  <span class="text-sm font-medium text-highlighted">{{ item.label }}</span>
                  <UBadge :color="item.color" variant="subtle" :label="String(item.value)" />
                </NuxtLink>
              </li>
            </ul>
          </aside>
        </div>

        <div class="grid min-w-0 gap-x-8 gap-y-6 lg:grid-cols-2" aria-label="Exceções operacionais">
          <section class="min-w-0" data-testid="work-dashboard-risks" aria-labelledby="work-risks-heading">
            <div class="mb-3 flex min-w-0 items-end justify-between gap-3">
              <div class="min-w-0">
                <h2 id="work-risks-heading" class="text-sm font-semibold text-highlighted">
                  Prioridades
                </h2>
                <p class="mt-0.5 text-xs text-muted">
                  Tarefas com os sinais de risco mais relevantes.
                </p>
              </div>
              <UButton
                to="/work/tasks"
                color="neutral"
                variant="ghost"
                size="xs"
                label="Ver fila"
                trailing-icon="i-lucide-arrow-right"
                @click.prevent="openQueue({ tab: 'atrasadas' })"
              />
            </div>

            <ul v-if="data.top_risks.length" class="divide-y divide-default border-y border-default">
              <li v-for="risk in data.top_risks.slice(0, 6)" :key="risk.task_id">
                <NuxtLink :to="`/work/tasks/${risk.task_id}`" class="group block min-w-0 py-2.5">
                  <span class="block truncate text-sm font-medium text-highlighted group-hover:text-primary group-hover:underline">{{ risk.title }}</span>
                  <span class="mt-1 flex min-w-0 flex-wrap items-center gap-1">
                    <UBadge
                      v-for="item in risk.risks"
                      :key="item"
                      :color="workRiskColor(item)"
                      variant="subtle"
                      size="xs"
                      :label="workRiskLabel(item)"
                    />
                    <span class="text-xs text-muted">Prazo {{ formatDueDate(risk.effective_due_date) }}</span>
                  </span>
                </NuxtLink>
              </li>
            </ul>

            <ShellListEmpty
              v-else
              title="Nenhum risco ativo"
              description="Não há tarefas sinalizadas com risco neste snapshot."
              test-id="work-dashboard-risks-empty"
            />
          </section>

          <section
            class="min-w-0"
            data-testid="work-dashboard-unassigned-processes"
            aria-labelledby="work-unassigned-heading"
          >
            <div class="mb-3 flex min-w-0 items-end justify-between gap-3">
              <div class="min-w-0">
                <h2 id="work-unassigned-heading" class="text-sm font-semibold text-highlighted">
                  Processos sem responsável
                </h2>
                <p class="mt-0.5 text-xs text-muted">
                  Pendências que precisam de atribuição.
                </p>
              </div>
              <UButton
                to="/work/processes"
                color="neutral"
                variant="ghost"
                size="xs"
                label="Ver todos"
                trailing-icon="i-lucide-arrow-right"
              />
            </div>

            <ul v-if="data.processes_without_owner.length" class="divide-y divide-default border-y border-default">
              <li v-for="process in data.processes_without_owner.slice(0, 5)" :key="process.id">
                <NuxtLink :to="`/work/processes/${process.id}`" class="group block min-w-0 py-2.5">
                  <span class="block truncate text-sm font-medium text-highlighted group-hover:text-primary group-hover:underline">{{ process.title }}</span>
                  <span class="mt-0.5 block text-xs text-muted">
                    {{ formatCompetence(process.competence) }} · {{ formatDueDate(process.due_date) }}
                  </span>
                </NuxtLink>
              </li>
            </ul>

            <ShellListEmpty
              v-else
              title="Responsabilidade definida"
              description="Não há processos abertos sem responsável."
              test-id="work-dashboard-processes-empty"
            />
          </section>
        </div>
      </template>
    </template>
  </ShellPagePanel>
</template>
