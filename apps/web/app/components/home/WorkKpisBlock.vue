<script setup lang="ts">
/**
 * Bloco Trabalho da Home — KPIs e carga por departamento.
 * Não mistura sinais fiscais nem de infraestrutura.
 */
import type { WorkDepartment, WorkKpis } from '~/types/work'
import type { DashboardKpiItem } from '~/utils/kpi-ui'
import { apiErrorMessage } from '~/utils/api-error'
import { formatDueDate, workRiskLabel } from '~/utils/work-labels'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const data = ref<WorkKpis | null>(null)
const lastGood = ref<WorkKpis | null>(null)
const departments = ref<WorkDepartment[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const stale = ref(false)

async function load() {
  const epoch = sessionEpoch.value
  const had = !!lastGood.value
  loading.value = !had
  if (!had) error.value = null

  try {
    const [kpisRes, deptRes] = await Promise.allSettled([
      api.work.kpis(),
      api.work.departments.list({ per_page: 100, is_active: true })
    ])

    if (epoch !== sessionEpoch.value) return

    if (kpisRes.status === 'fulfilled') {
      data.value = kpisRes.value.data
      lastGood.value = kpisRes.value.data
      error.value = null
      stale.value = false
    } else {
      const message = apiErrorMessage(kpisRes.reason, 'KPIs de trabalho indisponíveis.')
      error.value = message
      if (lastGood.value) {
        data.value = lastGood.value
        stale.value = true
      } else {
        toast.add({ title: message, color: 'error' })
      }
    }

    if (deptRes.status === 'fulfilled') {
      const payload = deptRes.value as { data?: WorkDepartment[] }
      departments.value = payload.data || []
    }
  } finally {
    if (epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

onMounted(load)
watch(sessionEpoch, () => {
  data.value = null
  lastGood.value = null
  departments.value = []
  error.value = null
  stale.value = false
  void load()
})

const cards = computed((): DashboardKpiItem[] => {
  const k = data.value?.kpis
  if (!k) return []
  return [
    { key: 'open', title: 'Abertas', value: k.total_open, to: '/work/tasks', icon: 'i-lucide-inbox' },
    { key: 'atrasadas', title: 'Atrasadas', value: k.atrasadas, to: '/work/tasks?tab=atrasadas', icon: 'i-lucide-clock-alert', tone: 'warning' },
    { key: 'em_multa', title: 'Em multa', value: k.em_multa, to: '/work/tasks?tab=atrasadas', icon: 'i-lucide-siren', tone: 'error' },
    { key: 'vence_hoje', title: 'Vencem hoje', value: k.vence_hoje, to: '/work/tasks?tab=hoje', icon: 'i-lucide-calendar-days' },
    { key: 'em_progresso', title: 'Em progresso', value: k.em_progresso, to: '/work/tasks', icon: 'i-lucide-loader', tone: 'info' },
    { key: 'sem_responsavel', title: 'Sem responsável', value: k.sem_responsavel, to: '/work/tasks', icon: 'i-lucide-user-x' }
  ]
})

const deptName = (id: number | null) => {
  if (id == null) return 'Sem departamento'
  return departments.value.find(d => d.id === id)?.name || `Departamento #${id}`
}

const departmentRows = computed(() => {
  const rows = data.value?.by_department || []
  return rows
    .map((row) => {
      const open = row.open ?? row.total ?? 0
      return {
        id: row.work_department_id,
        name: deptName(row.work_department_id),
        open,
        completed: row.completed ?? 0,
        overdue: row.overdue ?? 0,
        fine: row.fine ?? 0,
        unassigned: row.unassigned ?? 0,
        completedPercent: row.completed_percent ?? 0,
        to: row.work_department_id != null
          ? `/work/tasks?department_id=${row.work_department_id}`
          : '/work/tasks',
        overdueTo: row.work_department_id != null
          ? `/work/tasks?tab=atrasadas&department_id=${row.work_department_id}`
          : '/work/tasks?tab=atrasadas'
      }
    })
    .sort((a, b) => b.open - a.open)
})

const workPanelItems = computed(() => {
  const items: Array<{ label: string, icon: string, value: string, slot: string }> = []
  if (departmentRows.value.length) {
    items.push({ label: 'Departamentos', icon: 'i-lucide-building-2', value: 'departments', slot: 'departments' })
  }
  if (data.value?.top_risks?.length) {
    items.push({ label: 'Riscos', icon: 'i-lucide-triangle-alert', value: 'risks', slot: 'risks' })
  }
  return items
})

const lastUpdated = computed(() => {
  const raw = data.value?.generated_at
  if (!raw) return null
  try {
    return new Date(raw).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  } catch {
    return null
  }
})
</script>

<template>
  <section
    data-testid="home-work-kpis"
    class="space-y-4"
    aria-labelledby="home-work-heading"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2
          id="home-work-heading"
          class="text-base font-semibold text-highlighted"
        >
          Trabalho
        </h2>
        <p class="min-w-0 truncate text-xs text-muted">
          Fila e prazos
          <span v-if="lastUpdated"> · {{ lastUpdated }}</span>
          <span v-if="data?.today"> · {{ formatDueDate(data.today) }}</span>
        </p>
      </div>
      <UButton
        size="sm"
        color="neutral"
        variant="ghost"
        to="/work"
        label="Visão estratégica"
        trailing-icon="i-lucide-arrow-right"
      />
    </div>

    <UAlert
      v-if="stale && error"
      color="warning"
      variant="subtle"
      icon="i-lucide-wifi-off"
      :title="error"
      :actions="[{
        label: 'Tentar novamente',
        color: 'neutral',
        variant: 'subtle',
        onClick: () => load()
      }]"
    />

    <div
      v-if="loading"
      class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6"
    >
      <USkeleton
        v-for="i in 6"
        :key="i"
        class="h-20 w-full rounded-lg"
      />
    </div>
    <UAlert
      v-else-if="error && !data"
      color="error"
      variant="subtle"
      :title="error"
      :actions="[{
        label: 'Tentar novamente',
        color: 'neutral',
        variant: 'subtle',
        onClick: () => load()
      }]"
    />
    <ShellKpiStrip
      v-else
      test-id="home-work-kpi-cards"
      :items="cards"
      :columns="6"
    />

    <ShellPanelAccordion
      v-if="departmentRows.length || data?.top_risks?.length"
      :items="workPanelItems"
      type="multiple"
      :default-value="departmentRows.length ? ['departments'] : ['risks']"
      test-id="home-work-panels"
    >
      <template
        v-if="departmentRows.length"
        #departments-body
      >
        <ul class="grid gap-2 sm:grid-cols-2">
          <li
            v-for="row in departmentRows"
            :key="String(row.id)"
            class="rounded-lg border border-default px-3 py-2"
          >
            <div class="mb-1 flex items-center justify-between gap-2 text-sm">
              <NuxtLink
                :to="row.to"
                class="truncate font-medium text-highlighted hover:underline"
              >
                {{ row.name }}
              </NuxtLink>
              <span class="shrink-0 text-xs text-muted">
                {{ row.completedPercent }}%
              </span>
            </div>
            <UProgress
              :model-value="row.completedPercent"
              color="primary"
              size="sm"
              :aria-label="`${row.name}: ${row.completedPercent}%`"
            />
            <div class="mt-2 flex flex-wrap gap-2 text-xs text-muted">
              <NuxtLink
                :to="row.to"
                class="hover:underline"
              >
                {{ row.open }} abertas
              </NuxtLink>
              <NuxtLink
                :to="row.overdueTo"
                class="text-warning hover:underline"
              >
                {{ row.overdue }} atrasadas
              </NuxtLink>
              <span class="text-error">{{ row.fine }} multa</span>
              <span>{{ row.unassigned }} s/ resp.</span>
            </div>
          </li>
        </ul>
      </template>

      <template
        v-if="data?.top_risks?.length"
        #risks-body
      >
        <ul class="space-y-1 text-sm">
          <li
            v-for="r in data.top_risks.slice(0, 5)"
            :key="r.task_id"
          >
            <NuxtLink
              :to="`/work/tasks/${r.task_id}`"
              class="flex justify-between gap-2 rounded-md border border-default px-3 py-2 hover:bg-elevated/40"
            >
              <span class="truncate">{{ r.title }}</span>
              <span class="shrink-0 text-xs text-muted">
                {{ (r.risks || []).map(workRiskLabel).join(' · ') }}
              </span>
            </NuxtLink>
          </li>
        </ul>
      </template>
    </ShellPanelAccordion>
  </section>
</template>
