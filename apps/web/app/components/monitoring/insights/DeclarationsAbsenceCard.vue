<script setup lang="ts">
import type { MonitoringInsightsDeclarationsAbsence } from '~/types/monitoring-insights'

defineProps<{
  data: MonitoringInsightsDeclarationsAbsence | null
  loading?: boolean
  error?: string | null
}>()
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-absence-card"
  >
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs uppercase text-muted">
            Ausência de declarações
          </p>
          <p class="mt-1 text-sm text-muted">
            Obrigatórias em dia vs em aberto (sem split SPED).
          </p>
        </div>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Declarações"
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
      v-else-if="loading && !data"
      class="py-6 text-center text-sm text-muted"
    >
      Carregando…
    </div>
    <MonitoringTableEmptyState
      v-else-if="!data"
      kind="empty"
      title="Sem resumo de declarações"
      description="Ainda não há obrigações aplicáveis consolidadas para o escritório."
    />
    <div
      v-else
      class="grid grid-cols-2 gap-3"
    >
      <div class="rounded-lg bg-elevated/50 p-3 ring ring-inset ring-default">
        <div class="flex items-center gap-2 text-success">
          <UIcon
            name="i-lucide-circle-check"
            class="size-5"
          />
          <span class="text-xs uppercase text-muted">Em dia</span>
        </div>
        <p class="mt-2 text-2xl font-semibold tabular-nums text-highlighted">
          {{ data.up_to_date_count }}
        </p>
      </div>
      <div class="rounded-lg bg-elevated/50 p-3 ring ring-inset ring-default">
        <div class="flex items-center gap-2 text-error">
          <UIcon
            name="i-lucide-circle-x"
            class="size-5"
          />
          <span class="text-xs uppercase text-muted">Em aberto</span>
        </div>
        <p class="mt-2 text-2xl font-semibold tabular-nums text-highlighted">
          {{ data.open_count }}
        </p>
      </div>
    </div>
  </UPageCard>
</template>
