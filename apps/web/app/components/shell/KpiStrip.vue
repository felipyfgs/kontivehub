<script setup lang="ts">
/**
 * Faixa de KPIs canônica do painel (arquétipo HomeStats do template).
 *
 * - UPageGrid colado + UPageCard subtle (sem carrossel)
 * - Painéis secundários empilhados: ShellPanelAccordion (não esta faixa)
 *
 * Fonte: `.local/reference/nuxt-dashboard-template/app/components/home/HomeStats.vue`
 */
import type { DashboardKpiItem, KpiTone } from '~/utils/kpi-ui'
import {
  kpiDisplayTitle,
  kpiDisplayValue,
  kpiPageCardUi,
  kpiValueClass
} from '~/utils/kpi-ui'

const props = withDefaults(defineProps<{
  items: DashboardKpiItem[]
  loading?: boolean
  legend?: string | null
  testId?: string
  columns?: number | null
  activeKey?: string | null
  interactive?: boolean
}>(), {
  loading: false,
  legend: null,
  testId: 'dashboard-kpi-strip',
  columns: null,
  activeKey: null,
  interactive: false
})

const emit = defineEmits<{
  select: [key: string]
}>()

const cols = computed(() => {
  if (props.columns != null && props.columns > 0) return Math.min(props.columns, 6)
  return Math.min(Math.max(props.items.length || 4, 2), 6)
})

const gridClass = computed(() => {
  switch (cols.value) {
    case 2: return 'grid-cols-2 lg:grid-cols-2 gap-2 sm:gap-4 lg:gap-px'
    case 3: return 'grid-cols-2 lg:grid-cols-3 gap-2 sm:gap-4 lg:gap-px'
    case 5: return 'grid-cols-2 lg:grid-cols-5 gap-2 sm:gap-4 lg:gap-px'
    case 6: return 'grid-cols-2 lg:grid-cols-6 gap-2 sm:gap-4 lg:gap-px'
    default: return 'grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4 lg:gap-px'
  }
})

function isActive(key: string) {
  return props.activeKey != null && props.activeKey === key
}

function onActivate(item: DashboardKpiItem) {
  if (!props.interactive && !item.to) return
  emit('select', item.key)
}

function toneOf(item: DashboardKpiItem): KpiTone {
  return (item.tone || 'default') as KpiTone
}

function cardUi(item: DashboardKpiItem) {
  return kpiPageCardUi(toneOf(item), isActive(item.key))
}

function valueClass(item: DashboardKpiItem) {
  const tone = toneOf(item)
  const alert = Boolean(item.critical && Number(item.value) > 0)
  return kpiValueClass(tone, alert)
}

function isClickable(item: DashboardKpiItem) {
  return Boolean(props.interactive || item.to)
}
</script>

<template>
  <div
    :data-testid="testId"
    class="w-full min-w-0 shrink-0"
  >
    <div
      v-if="legend || loading"
      class="mb-2 flex items-center justify-between gap-2"
    >
      <p
        v-if="legend"
        class="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-muted"
      >
        {{ legend }}
      </p>
      <p
        v-if="loading"
        class="shrink-0 text-xs text-dimmed"
      >
        Atualizando…
      </p>
    </div>

    <UPageGrid :class="gridClass">
      <UPageCard
        v-for="item in items"
        :key="item.key"
        :icon="item.icon"
        :title="kpiDisplayTitle(item.title)"
        :to="item.to"
        variant="subtle"
        :highlight="isActive(item.key)"
        highlight-color="primary"
        :ui="cardUi(item)"
        class="min-w-0 overflow-hidden lg:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1"
        :class="isClickable(item) ? 'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary' : ''"
        :role="interactive ? 'button' : undefined"
        :tabindex="interactive ? 0 : undefined"
        :aria-pressed="interactive ? isActive(item.key) : undefined"
        :aria-label="item.ariaLabel || item.title"
        :data-testid="`kpi-${item.key}`"
        @click="onActivate(item)"
        @keydown.enter.prevent="onActivate(item)"
        @keydown.space.prevent="onActivate(item)"
      >
        <div class="flex min-w-0 max-w-full items-center gap-1.5">
          <span :class="valueClass(item)">
            {{ kpiDisplayValue(item.value) }}
          </span>
          <UIcon
            v-if="item.critical && Number(item.value) > 0"
            name="i-lucide-triangle-alert"
            class="size-3.5 shrink-0 text-error sm:size-4"
            aria-label="Requer atenção"
          />
        </div>
      </UPageCard>
    </UPageGrid>
  </div>
</template>
