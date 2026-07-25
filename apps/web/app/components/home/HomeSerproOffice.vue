<script setup lang="ts">
import type { OperationsSummary } from '~/types/api'
import { buildHomeSerproKpis, homePlatformHealthLabel } from '~/utils/home-cockpit'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

const items = computed(() => buildHomeSerproKpis(props.summary, { loading: props.loading }))
const healthLabel = computed(() => homePlatformHealthLabel(props.summary?.platform_health))
const planLabel = computed(() => {
  if (!props.summary) return props.loading ? '…' : '—'
  const sub = props.summary.subscription
  if (!sub) return 'Sem assinatura'
  return [sub.plan, sub.status].filter(Boolean).join(' · ') || '—'
})
</script>

<template>
  <section
    data-testid="home-serpro-office"
    class="min-w-0"
    aria-labelledby="home-serpro-heading"
  >
    <div class="mb-2 flex min-w-0 flex-wrap items-center justify-between gap-2">
      <h2
        id="home-serpro-heading"
        class="text-xs font-normal uppercase text-muted"
      >
        SERPRO do escritório
      </h2>
      <div class="flex flex-wrap items-center gap-1.5">
        <UBadge
          color="neutral"
          variant="subtle"
          :label="`Integra: ${healthLabel}`"
        />
        <UBadge
          color="neutral"
          variant="outline"
          :label="planLabel"
        />
      </div>
    </div>
    <ShellKpiStrip
      test-id="home-serpro-kpi-cards"
      :items="items"
      :loading="loading"
      :columns="4"
    />
  </section>
</template>
