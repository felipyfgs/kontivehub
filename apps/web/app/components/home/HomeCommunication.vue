<script setup lang="ts">
import type { OperationsSummary } from '~/types/api'
import { buildHomeCommunicationKpis } from '~/utils/home-cockpit'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

const items = computed(() => buildHomeCommunicationKpis(props.summary, { loading: props.loading }))
const flagsOff = computed(() => {
  const c = props.summary?.communication
  if (!c || c.available === false) return false
  return !c.global_enabled || !c.gateway_enabled || !c.office_enabled
})
const unavailable = computed(() => props.summary?.communication?.available === false)
</script>

<template>
  <section
    data-testid="home-communication"
    class="min-w-0"
    aria-labelledby="home-communication-heading"
  >
    <div class="mb-2 flex min-w-0 items-center justify-between gap-2">
      <h2
        id="home-communication-heading"
        class="text-xs font-normal uppercase text-muted"
      >
        Atendimento
      </h2>
      <UButton
        to="/communication"
        color="neutral"
        variant="ghost"
        size="xs"
        icon="i-lucide-messages-square"
        label="Abrir"
      />
    </div>

    <UAlert
      v-if="unavailable"
      color="warning"
      variant="subtle"
      icon="i-lucide-cloud-off"
      title="Resumo de atendimento indisponível"
      class="mb-2"
    />
    <UAlert
      v-else-if="flagsOff"
      color="warning"
      variant="subtle"
      icon="i-lucide-toggle-left"
      title="Comunicação ou gateway desabilitados"
      description="Flags globais/office podem impedir o transporte WhatsApp."
      class="mb-2"
    />

    <ShellKpiStrip
      test-id="home-communication-kpi-cards"
      :items="items"
      :loading="loading"
      :columns="3"
    />
  </section>
</template>
