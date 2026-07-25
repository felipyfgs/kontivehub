<script setup lang="ts">
/**
 * Dashboard de clientes — arquétipo Home do template Nuxt UI Dashboard.
 * Fonte: .local/reference/nuxt-dashboard-template/app/pages/index.vue
 *   → HomeStats + HomeChart + HomeSales
 * Demo: https://dashboard-template.nuxt.dev/
 *
 * Dados reais do escritório (sem %/séries inventadas do mock).
 */
import type { TableColumn } from '@nuxt/ui'
import type { Client, ClientListStats } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { COMPACT_DASHBOARD_TABLE_UI } from '~/utils/table-ui'
import { truncateText } from '~/utils/format'
import {
  format,
  isValid,
  parseISO
} from 'date-fns'
import { ptBR } from 'date-fns/locale'
import {
  VisXYContainer,
  VisLine,
  VisArea,
  VisAxis,
  VisCrosshair,
  VisTooltip
} from '@unovis/vue'

const props = defineProps<{
  clients: Client[]
  stats: ClientListStats
  loading?: boolean
}>()

const UBadge = resolveComponent('UBadge')

// ─── Stats (HomeStats) ───────────────────────────────────────────────────────

const statsCards = computed(() => {
  return [
    {
      title: 'Clientes',
      icon: 'i-lucide-users',
      value: props.stats.total
    },
    {
      title: 'Ativos',
      icon: 'i-lucide-circle-check',
      value: props.stats.active
    },
    {
      title: 'A1 OK',
      icon: 'i-lucide-badge-check',
      value: props.stats.credential_ok ?? 0
    },
    {
      title: 'A vencer / sem A1',
      icon: 'i-lucide-badge-alert',
      value: (props.stats.credential_expiring_30d || 0)
        + (props.stats.without_credential || 0)
    }
  ]
})

// ─── Chart (HomeChart) — acumulado mensal real ───────────────────────────────

type DataRecord = { date: Date, amount: number }

const chartCard = useTemplateRef<HTMLElement | null>('chartCard')
const { width: measuredWidth } = useElementSize(chartCard)
/** Evita gráfico branco: Unovis com width=0 não desenha. */
const chartWidth = computed(() => Math.max(measuredWidth.value || 0, 320))
const chartReady = computed(() => (measuredWidth.value || 0) > 40)

const chartData = computed((): DataRecord[] => {
  return (props.stats.client_growth_12m || [])
    .map(({ month, total }) => ({ date: parseISO(`${month}-01`), amount: total }))
    .filter(record => isValid(record.date))
})

const chartTotal = computed(() => props.stats.total)

const x = (_: DataRecord, i: number) => i
const y = (d: DataRecord) => d.amount

const formatDate = (date: Date) => format(date, 'MMM yy', { locale: ptBR })

const xTicks = (i: number) => {
  if (!chartData.value[i]) return ''
  const step = Math.max(1, Math.floor((chartData.value.length - 1) / 5))
  if (i === 0 || i === chartData.value.length - 1 || i % step === 0) {
    return formatDate(chartData.value[i]!.date)
  }
  return ''
}

const template = (d: DataRecord) =>
  `${formatDate(d.date)}: ${d.amount} cliente(s)`

// ─── Sales table → últimos clientes (HomeSales) ──────────────────────────────

type RecentRow = {
  id: number
  name: string
  cnpj: string
  status: 'active' | 'inactive'
  date: string | null
  a1: string
}

const recentRows = computed((): RecentRow[] => {
  return props.clients
    .slice(0, 8)
    .map((c) => {
      const s = c.credential_summary
      let a1 = 'Sem A1'
      if (s) {
        const validTo = s.valid_to ? new Date(s.valid_to) : null
        const expired = s.status === 'EXPIRED'
          || !!(validTo && validTo < new Date())
        const expiring = !!(validTo && validTo <= new Date(Date.now() + 30 * 24 * 60 * 60 * 1000))
        if (expired) a1 = 'Vencido'
        else if (expiring || s.expires_alert_1 || s.expires_alert_7 || s.expires_alert_30) a1 = 'A vencer'
        else a1 = 'OK'
      }
      return {
        id: c.id,
        name: c.legal_name || c.name,
        cnpj: formatCnpj(c.cnpj || c.root_cnpj),
        status: c.is_active ? 'active' : 'inactive',
        date: c.created_at || null,
        a1
      }
    })
})

const columns: TableColumn<RecentRow>[] = [
  {
    accessorKey: 'id',
    header: 'ID',
    cell: ({ row }) => `#${row.original.id}`
  },
  {
    accessorKey: 'date',
    header: 'Cadastro',
    cell: ({ row }) => {
      if (!row.original.date) return '—'
      return formatDateTime(row.original.date)
    }
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    cell: ({ row }) => {
      const active = row.original.status === 'active'
      return h(UBadge, {
        class: 'capitalize',
        variant: 'subtle',
        color: active ? 'success' : 'neutral'
      }, () => (active ? 'Ativo' : 'Inativo'))
    }
  },
  {
    accessorKey: 'name',
    header: 'Cliente',
    cell: ({ row }) => {
      const name = row.original.name || '—'
      return h('span', {
        class: 'block min-w-0 max-w-xs truncate font-medium text-highlighted',
        title: name
      }, truncateText(name, 40) || name)
    }
  },
  {
    accessorKey: 'cnpj',
    header: 'CNPJ',
    cell: ({ row }) => h('span', { class: 'font-mono text-sm' }, row.original.cnpj)
  },
  {
    accessorKey: 'a1',
    header: () => h('div', { class: 'text-right' }, 'A1'),
    cell: ({ row }) => {
      const color = {
        'OK': 'success' as const,
        'A vencer': 'warning' as const,
        'Vencido': 'error' as const,
        'Sem A1': 'neutral' as const
      }[row.original.a1] || 'neutral' as const
      return h('div', { class: 'text-right' }, [
        h(UBadge, { variant: 'subtle', color }, () => row.original.a1)
      ])
    }
  }
]
</script>

<template>
  <div
    class="flex w-full flex-col gap-4 sm:gap-6 lg:gap-8"
    data-testid="clients-dashboard"
  >
    <!-- HomeStats -->
    <UPageGrid class="grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4 lg:gap-px">
      <UPageCard
        v-for="(stat, index) in statsCards"
        :key="index"
        :icon="stat.icon"
        :title="stat.title"
        variant="subtle"
        :ui="{
          container: 'min-w-0 gap-y-1.5 p-3 sm:p-6',
          wrapper: 'min-w-0 items-start',
          leading: 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col',
          title: 'w-full truncate font-normal text-muted text-xs uppercase'
        }"
        class="min-w-0 lg:rounded-none first:rounded-l-lg last:rounded-r-lg"
      >
        <div class="flex items-center gap-2">
          <span class="text-2xl font-semibold text-highlighted tabular-nums">
            {{ loading && !clients.length ? '…' : stat.value }}
          </span>
        </div>
      </UPageCard>
    </UPageGrid>

    <!-- HomeChart (Unovis) -->
    <UPageCard
      ref="chartCard"
      class="shrink-0"
      variant="subtle"
      :ui="{ root: 'overflow-visible', body: 'px-0! pt-0! pb-3!' }"
    >
      <template #header>
        <div>
          <p class="text-xs text-muted uppercase mb-1.5">
            Base de clientes
          </p>
          <p class="text-3xl text-highlighted font-semibold tabular-nums">
            {{ loading && !clients.length ? '…' : chartTotal }}
          </p>
        </div>
      </template>

      <ClientOnly>
        <VisXYContainer
          v-if="chartReady && chartData.length"
          :data="chartData"
          :padding="{ top: 40, left: 16, right: 16 }"
          class="h-96 w-full"
          :width="chartWidth"
        >
          <VisLine
            :x="x"
            :y="y"
            color="var(--ui-primary)"
          />
          <VisArea
            :x="x"
            :y="y"
            color="var(--ui-primary)"
            :opacity="0.12"
          />
          <VisAxis
            type="x"
            :x="x"
            :tick-format="xTicks"
          />
          <VisAxis type="y" />
          <VisCrosshair
            color="var(--ui-primary)"
            :template="template"
          />
          <VisTooltip />
        </VisXYContainer>
        <div
          v-else
          class="flex h-96 items-center justify-center text-sm text-muted"
        >
          {{ loading ? 'Carregando…' : (chartData.length ? 'Montando gráfico…' : 'Sem datas de cadastro para o gráfico.') }}
        </div>
        <template #fallback>
          <div class="h-96" />
        </template>
      </ClientOnly>
    </UPageCard>

    <!-- HomeSales → últimos clientes -->
    <ShellDataTable
      :data="recentRows"
      :columns="columns"
      :loading="loading"
      :page="1"
      :total="recentRows.length"
      :items-per-page="recentRows.length || 1"
      :show-footer="false"
      :ui="COMPACT_DASHBOARD_TABLE_UI"
    />
  </div>
</template>

<style scoped>
.unovis-xy-container {
  --vis-crosshair-line-stroke-color: var(--ui-primary);
  --vis-crosshair-circle-stroke-color: var(--ui-bg);

  --vis-axis-grid-color: var(--ui-border);
  --vis-axis-tick-color: var(--ui-border);
  --vis-axis-tick-label-color: var(--ui-text-dimmed);

  --vis-tooltip-background-color: var(--ui-bg);
  --vis-tooltip-border-color: var(--ui-border);
  --vis-tooltip-text-color: var(--ui-text-highlighted);
}
</style>
