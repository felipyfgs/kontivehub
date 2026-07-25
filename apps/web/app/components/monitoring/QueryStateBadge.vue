<script setup lang="ts">
import type {
  FiscalMonitoringQueryProjection,
  ManualConsultLastResultSummary
} from '~/types/fiscal-modules'
import {
  monitoringFreshnessLabel,
  monitoringQueryStateMeta
} from '~/utils/monitoring-coverage'

const props = withDefaults(defineProps<{
  projection?: FiscalMonitoringQueryProjection | ManualConsultLastResultSummary | null
  state?: string | null
  showDetails?: boolean
}>(), {
  projection: null,
  state: null,
  showDetails: false
})

const resolvedState = computed(() =>
  props.projection?.state || props.projection?.status || props.state || 'IDLE'
)
const meta = computed(() => monitoringQueryStateMeta(resolvedState.value))
const label = computed(() => props.projection?.state_label?.trim() || meta.value.label)
const freshness = computed(() => monitoringFreshnessLabel(props.projection?.freshness?.state))
const observed = computed(() => props.projection?.observed_at
  ? formatDateTime(props.projection.observed_at)
  : null)
const preserved = computed(() => props.projection?.has_preserved_snapshot === true)
const aria = computed(() => [
  label.value,
  meta.value.description,
  preserved.value ? 'O último resultado válido foi preservado.' : null
].filter(Boolean).join('. '))
</script>

<template>
  <div
    class="inline-flex min-w-0 flex-col gap-1"
    data-testid="monitoring-query-state"
  >
    <span class="inline-flex items-center gap-1.5" :aria-label="aria">
      <UBadge :color="meta.color" variant="subtle" size="sm">
        <UIcon
          :name="meta.icon"
          class="size-3.5 shrink-0"
          :class="meta.animated ? 'animate-spin' : ''"
          aria-hidden="true"
        />
        {{ label }}
      </UBadge>
      <UBadge
        v-if="preserved"
        color="warning"
        variant="outline"
        size="sm"
        label="Último resultado preservado"
      />
    </span>
    <span
      v-if="showDetails"
      class="text-xs text-muted"
    >
      {{ freshness }}<template v-if="observed"> · observado em {{ observed }}</template>
    </span>
  </div>
</template>
