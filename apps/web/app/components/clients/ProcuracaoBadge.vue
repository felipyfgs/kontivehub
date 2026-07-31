<script setup lang="ts">
/**
 * Coluna / chip de Procuração (estados oficiais sincronizados).
 */
import { procuracaoActionHint, procuracaoChipLabel, procuracaoLabel, procuracaoTone, procuracaoValidityLabel } from '~/utils/procuracao'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = defineProps<{
  status?: string | null
  validTo?: string | null
  checkedAt?: string | null
  showHint?: boolean
  compact?: boolean
}>()

const label = computed(() => props.compact
  ? procuracaoChipLabel(props.status, props.validTo)
  : procuracaoLabel(props.status))
const tone = computed(() => procuracaoTone(props.status))
const hint = computed(() => props.showHint ? procuracaoActionHint(props.status) : null)
const validity = computed(() => procuracaoValidityLabel(props.status, props.validTo))
</script>

<template>
  <div
    class="min-w-0"
    :class="compact ? 'w-full' : undefined"
    data-testid="client-procuracao-badge"
  >
    <UBadge
      :color="tone"
      variant="subtle"
      :size="compact ? 'md' : 'sm'"
      :class="compact ? TABLE_CELL_BADGE_CLASS : undefined"
      :ui="compact ? TABLE_CELL_BADGE_UI : undefined"
      :aria-label="`Procuração: ${label}`"
    >
      {{ label }}
    </UBadge>
    <p
      v-if="validity && !compact"
      class="mt-0.5 text-[10px] text-muted"
    >
      {{ validity }}
    </p>
    <p
      v-if="checkedAt && !compact"
      class="mt-0.5 text-[10px] text-muted"
    >
      Verificado {{ formatDateTime(checkedAt) }}
    </p>
    <p
      v-if="hint && !compact"
      class="mt-0.5 text-[10px] text-muted"
    >
      {{ hint }}
    </p>
  </div>
</template>
