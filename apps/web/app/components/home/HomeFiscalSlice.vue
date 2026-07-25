<script setup lang="ts">
import type { OperationsSummary } from '~/types/api'
import { buildHomeFiscalKpis } from '~/utils/home-cockpit'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

const items = computed(() => buildHomeFiscalKpis(props.summary, { loading: props.loading }))
</script>

<template>
  <section
    data-testid="home-fiscal-slice"
    class="min-w-0"
    aria-labelledby="home-fiscal-heading"
  >
    <div class="mb-2 flex min-w-0 items-center justify-between gap-2">
      <h2
        id="home-fiscal-heading"
        class="text-xs font-normal uppercase text-muted"
      >
        Fiscal (resumo)
      </h2>
      <UButton
        to="/monitoring"
        color="neutral"
        variant="ghost"
        size="xs"
        icon="i-lucide-layout-dashboard"
        label="Dashboard fiscal"
      />
    </div>
    <ShellKpiStrip
      test-id="home-fiscal-kpi-cards"
      :items="items"
      :loading="loading"
      :columns="3"
    />
  </section>
</template>
