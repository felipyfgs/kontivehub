<script setup lang="ts">
import type { MonitoringInsightsObligationProgress } from '~/types/monitoring-insights'

const props = defineProps<{
  items: MonitoringInsightsObligationProgress[] | null
  loading?: boolean
  error?: string | null
}>()

function ratio(row: MonitoringInsightsObligationProgress): number {
  if (row.is_synthetic || row.coverage === 'UNSUPPORTED' || row.completed == null || row.total == null || row.total <= 0) {
    return 0
  }
  return Math.min(100, Math.round((row.completed / row.total) * 100))
}

function fraction(row: MonitoringInsightsObligationProgress): string {
  if (row.is_synthetic) return 'Sem dados reais'
  if (row.coverage === 'UNSUPPORTED') return 'UNSUPPORTED'
  if (row.completed == null || row.total == null) return '—'
  return `${row.completed} / ${row.total}`
}

const rows = computed(() => props.items ?? [])
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-obligations-progress-card"
  >
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs uppercase text-muted">
            Declarações
          </p>
          <p class="mt-1 text-sm text-muted">
            Progresso por obrigação (DIRF sem cobertura).
          </p>
        </div>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Hub"
          to="/monitoring/declarations"
        />
      </div>
    </template>

    <p
      v-if="error"
      class="text-sm text-error"
    >
      {{ error }}
    </p>
    <div
      v-else-if="loading && !items"
      class="py-6 text-center text-sm text-muted"
    >
      Carregando…
    </div>
    <MonitoringTableEmptyState
      v-else-if="!rows.length"
      kind="empty"
      title="Sem progresso"
      description="Nenhuma obrigação disponível."
    />
    <ul
      v-else
      class="space-y-3"
    >
      <li
        v-for="row in rows"
        :key="row.code"
        class="space-y-1.5"
      >
        <div class="flex items-center justify-between gap-2 text-sm">
          <span class="font-medium text-highlighted">{{ row.label }}</span>
          <span class="inline-flex items-center gap-1.5 tabular-nums text-muted">
            {{ fraction(row) }}
            <UIcon
              v-if="row.is_synthetic || row.coverage === 'UNSUPPORTED' || (row.error ?? 0) > 0 || (row.completed != null && row.total != null && row.completed < row.total)"
              :name="row.is_synthetic || row.coverage === 'UNSUPPORTED' ? 'i-lucide-ban' : 'i-lucide-triangle-alert'"
              class="size-3.5"
              :class="row.is_synthetic || row.coverage === 'UNSUPPORTED' ? 'text-muted' : 'text-error'"
            />
          </span>
        </div>
        <UProgress
          :model-value="ratio(row)"
          size="sm"
          :color="row.is_synthetic || row.coverage === 'UNSUPPORTED' || row.total === 0 ? 'neutral' : (ratio(row) >= 100 ? 'success' : 'warning')"
        />
      </li>
    </ul>
  </UPageCard>
</template>
