<script setup lang="ts">
/**
 * Grade de processos monitorados no overview empresa-first.
 * Status e última consulta só a partir de evidência local (fail-closed).
 */
import type { ClientMonitoringProcessCard } from '~/utils/client-monitoring-overview'
import { formatDateTime } from '~/utils/format'

defineProps<{
  cards: ClientMonitoringProcessCard[]
  loading?: boolean
}>()
</script>

<template>
  <div
    class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3"
    data-testid="client-monitoring-process-overview"
  >
    <template v-if="loading && !cards.length">
      <div
        v-for="i in 6"
        :key="`sk-${i}`"
        class="h-24 animate-pulse rounded-lg bg-elevated"
      />
    </template>

    <NuxtLink
      v-for="card in cards"
      :key="card.key"
      :to="card.to"
      class="group flex flex-col gap-3 rounded-lg border border-default bg-default p-4 transition-colors hover:border-primary/40 hover:bg-elevated/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      :data-testid="`client-monitoring-process-${card.key}`"
    >
      <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
          <UIcon
            :name="card.icon"
            class="size-4 shrink-0 text-muted"
          />
          <span class="truncate font-medium text-highlighted">
            {{ card.label }}
          </span>
        </div>
        <UIcon
          name="i-lucide-chevron-right"
          class="size-4 shrink-0 text-dimmed transition-transform group-hover:translate-x-0.5 group-hover:text-primary"
        />
      </div>

      <div class="mt-auto flex flex-wrap items-center gap-2">
        <FiscalStatusBadge
          v-if="card.situation"
          :status="card.situation"
        />
        <UBadge
          v-else
          color="neutral"
          variant="subtle"
          size="xs"
          label="Sem evidência local"
        />
        <span
          v-if="card.lastObservedAt"
          class="text-xs text-muted"
        >
          {{ formatDateTime(card.lastObservedAt) }}
        </span>
      </div>
    </NuxtLink>
  </div>
</template>
