<script setup lang="ts">
/**
 * Badge simulado | real | estimado | conciliado (e variantes de bilhetagem).
 */
import { provenanceBadgeMeta, resolveProvenanceBadge } from '~/utils/serpro-badges'
import type { SerproProvenanceBadge } from '~/types/api'

const props = withDefaults(defineProps<{
  /** Código explícito do badge. */
  code?: SerproProvenanceBadge | string | null
  /** Campos brutos da API para derivar o badge. */
  sourceProvenance?: string | null
  isSimulated?: boolean | null
  verificationState?: string | null
  consumptionClass?: string | null
  reconciliationStatus?: string | null
  isBillableAttempt?: boolean | null
  result?: string | null
  size?: 'xs' | 'sm' | 'md'
}>(), {
  size: 'sm'
})

const meta = computed(() => {
  if (props.code) {
    return provenanceBadgeMeta(props.code)
  }
  return resolveProvenanceBadge({
    source_provenance: props.sourceProvenance,
    is_simulated: props.isSimulated,
    verification_state: props.verificationState,
    consumption_class: props.consumptionClass,
    reconciliation_status: props.reconciliationStatus,
    is_billable_attempt: props.isBillableAttempt,
    result: props.result
  })
})
</script>

<template>
  <UBadge
    :color="meta.color"
    variant="subtle"
    :size="size"
    class="font-normal"
    data-testid="serpro-provenance-badge"
    :aria-label="`${meta.label}. ${meta.description}`"
  >
    <UIcon
      :name="meta.icon"
      class="size-3.5 shrink-0"
      aria-hidden="true"
    />
    <span>{{ meta.label }}</span>
  </UBadge>
</template>
