<script setup lang="ts">
import type { MonitoringInsightsMailbox } from '~/types/monitoring-insights'

const props = defineProps<{
  data: MonitoringInsightsMailbox | null
  loading?: boolean
  error?: string | null
}>()

const buckets = computed(() => props.data?.buckets ?? { important: 0, up_to_date: 0, other: 0 })
const total = computed(() =>
  buckets.value.important + buckets.value.up_to_date + buckets.value.other
)

function pct(n: number) {
  if (total.value <= 0) return 0
  return Math.round((n / total.value) * 100)
}
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-mailbox-card"
  >
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs uppercase text-muted">
            Mensagens e-CAC
          </p>
          <p class="mt-1 text-sm text-muted">
            Buckets: Importante · Em dia · Outros (sem “Excluído” no domínio).
          </p>
        </div>
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Caixa postal"
          to="/monitoring/mailbox"
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
      v-else-if="!data"
      kind="empty"
      title="Sem leitura da caixa postal"
      description="Nenhuma consolidação e-CAC está disponível neste momento."
    />
    <div
      v-else
      class="grid gap-4 sm:grid-cols-[1fr_auto]"
    >
      <div>
        <div
          v-if="total > 0"
          class="flex h-4 overflow-hidden rounded-full bg-elevated ring ring-inset ring-default"
          role="img"
          :aria-label="`Importante ${buckets.important}, Em dia ${buckets.up_to_date}, Outros ${buckets.other}`"
        >
          <div
            class="bg-warning"
            :style="{ width: `${pct(buckets.important)}%` }"
          />
          <div
            class="bg-success"
            :style="{ width: `${pct(buckets.up_to_date)}%` }"
          />
          <div
            class="bg-muted"
            :style="{ width: `${pct(buckets.other)}%` }"
          />
        </div>
        <p
          v-else
          class="py-4 text-sm text-muted"
        >
          Sem mensagens na amostra.
        </p>
        <div class="mt-3 flex flex-wrap gap-3 text-xs">
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2 rounded-full bg-warning" />
            Importante ({{ buckets.important }})
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2 rounded-full bg-success" />
            Em dia ({{ buckets.up_to_date }})
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="size-2 rounded-full bg-muted" />
            Outros ({{ buckets.other }})
          </span>
        </div>
      </div>
      <div
        v-if="data?.others_breakdown?.length"
        class="min-w-36"
      >
        <p class="mb-2 text-xs font-medium uppercase text-muted">
          Outros
        </p>
        <ul class="space-y-1 text-sm">
          <li
            v-for="row in data.others_breakdown"
            :key="row.label"
            class="flex justify-between gap-3"
          >
            <span class="truncate text-muted">{{ row.label }}</span>
            <span class="tabular-nums text-highlighted">{{ row.count }}</span>
          </li>
        </ul>
      </div>
    </div>
  </UPageCard>
</template>
