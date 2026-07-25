<script setup lang="ts">
import type { MonitoringInsightsPending } from '~/types/monitoring-insights'
import { formatDateTime } from '~/utils/format'

defineProps<{
  data: MonitoringInsightsPending | null
  loading?: boolean
  error?: string | null
}>()

const severityLabels: Record<string, string> = {
  CRITICAL: 'Crítica',
  HIGH: 'Alta',
  MEDIUM: 'Média',
  LOW: 'Baixa'
}

function severityLabel(severity: string): string {
  return severityLabels[severity] ?? severity
}
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-pending-card"
    :ui="{ body: 'space-y-3' }"
  >
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs uppercase text-muted">
            Prioridades fiscais
          </p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-highlighted">
            {{ loading && !data ? '…' : (data?.total ?? '—') }}
          </p>
        </div>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Ver todas"
          to="/monitoring/sitfis"
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
      v-else-if="loading && !data"
      class="py-8 text-center text-sm text-muted"
    >
      Carregando…
    </div>
    <MonitoringTableEmptyState
      v-else-if="!data?.items?.length"
      kind="empty"
      title="Sem pendências abertas"
      description="Nenhuma pendência OPEN no escritório."
    />
    <div
      v-else-if="data"
      class="flex flex-wrap gap-2"
      aria-label="Pendências por severidade"
    >
      <UBadge
        v-for="(count, severity) in data.by_severity"
        :key="severity"
        size="sm"
        variant="subtle"
        :color="severity === 'CRITICAL' || severity === 'HIGH' ? 'error' : 'warning'"
        :label="`${severityLabel(severity)}: ${count}`"
      />
    </div>
    <ul
      v-if="!error && data?.items?.length"
      class="divide-y divide-default"
    >
      <li
        v-for="item in data.items"
        :key="item.id"
        class="flex items-start justify-between gap-3 py-2.5"
      >
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-highlighted">
            {{ item.title || item.code || `Pendência #${item.id}` }}
          </p>
          <p class="truncate text-xs text-muted">
            {{ item.detail || '—' }}
          </p>
        </div>
        <div class="shrink-0 text-right">
          <UBadge
            v-if="item.severity"
            size="sm"
            variant="subtle"
            :color="item.severity === 'CRITICAL' || item.severity === 'HIGH' ? 'error' : 'warning'"
            :label="severityLabel(item.severity)"
          />
          <p
            v-if="item.created_at || item.due_at"
            class="mt-1 text-[10px] text-muted"
          >
            {{ formatDateTime(item.due_at || item.created_at) }}
          </p>
        </div>
      </li>
    </ul>
  </UPageCard>
</template>
