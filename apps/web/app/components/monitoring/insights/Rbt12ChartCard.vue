<script setup lang="ts">
/**
 * Gráfico de RBT12 por cliente (não sublimite — domínio sem limiar persistido).
 */
import { VisXYContainer, VisGroupedBar, VisAxis, VisTooltip } from '@unovis/vue'
import type { MonitoringInsightsRbt12 } from '~/types/monitoring-insights'

const props = defineProps<{
  data: MonitoringInsightsRbt12 | null
  loading?: boolean
  error?: string | null
}>()

const chartCard = useTemplateRef<HTMLElement | null>('chartCard')
const { width: measuredWidth } = useElementSize(chartCard)
const chartWidth = computed(() => Math.max(measuredWidth.value || 0, 320))

const chartData = computed(() =>
  (props.data?.clients ?? []).slice(0, 12).map(c => ({
    name: c.display_name.length > 14 ? `${c.display_name.slice(0, 12)}…` : c.display_name,
    // total_cents → milhões de reais
    value: c.total_cents / 100_000_000
  }))
)

const chartReady = computed(() => (measuredWidth.value || 0) > 40 && chartData.value.length > 0)
const x = (_: unknown, i: number) => i
const y = (d: { value: number }) => d.value
const xTicks = (_: number, i: number) => chartData.value[i]?.name ?? ''
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-rbt12-card"
    :ui="{ root: 'min-w-0 overflow-hidden', body: 'min-w-0 px-0! pt-0! pb-3!' }"
  >
    <template #header>
      <div>
        <p class="text-xs uppercase text-muted">
          RBT12 — carteira Simples
        </p>
        <p class="mt-1 text-sm text-muted">
          Receita bruta acumulada 12 meses (somente valores parseados). Não é sublimite anual.
        </p>
      </div>
    </template>

    <div
      ref="chartCard"
      class="min-w-0 overflow-hidden"
    >
      <p
        v-if="error"
        class="px-4 py-6 text-sm text-error"
      >
        {{ error }}
      </p>
      <ClientOnly>
        <VisXYContainer
          v-if="!error && chartReady"
          :data="chartData"
          :padding="{ top: 24, bottom: 8, left: 8, right: 8 }"
          class="h-64 w-full"
          :width="chartWidth"
        >
          <VisGroupedBar
            :x="x"
            :y="y"
            color="var(--ui-success)"
            :bar-padding="0.25"
          />
          <VisAxis
            type="x"
            :x="x"
            :tick-format="xTicks"
            :tick-line="false"
          />
          <VisAxis
            type="y"
            :tick-format="(v: number) => `${v.toFixed(1)} M`"
          />
          <VisTooltip />
        </VisXYContainer>
        <div
          v-else-if="!error"
          class="flex h-64 items-center justify-center px-4 text-center text-sm text-muted"
        >
          {{ loading ? 'Carregando…' : 'Sem RBT12 parseado na carteira PGDAS-D.' }}
        </div>
      </ClientOnly>
    </div>
  </UPageCard>
</template>
