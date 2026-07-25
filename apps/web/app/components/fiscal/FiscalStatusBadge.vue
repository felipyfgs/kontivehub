<script setup lang="ts">
/**
 * Badge acessível de situação fiscal — texto + ícone + cor (15.8).
 * Não depende só de cor: label e aria-label explícitos.
 * Em tabelas (`fill`, default): ocupa a célula sem inflar o padding do td.
 */
import { fiscalStatusMeta } from '~/utils/fiscal-status'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = withDefaults(defineProps<{
  status?: string | null
  /** Rótulo opcional que sobrescreve o catálogo. */
  label?: string | null
  /** Exibe descrição curta (origem/limitação) ao lado. */
  showHint?: boolean
  /** Default xs — densidade inline; ignorado quando fill. */
  size?: 'xs' | 'sm' | 'md'
  /** Preenche a célula da grade (ligar nas UTable). */
  fill?: boolean
}>(), {
  size: 'xs',
  showHint: false,
  fill: false
})

const meta = computed(() => fiscalStatusMeta(props.status))
const displayLabel = computed(() => props.label || meta.value.label)
const aria = computed(() => {
  const parts = [displayLabel.value, meta.value.description]
  if (props.showHint && meta.value.sourceHint) {
    parts.push(meta.value.sourceHint)
  }
  return parts.filter(Boolean).join('. ')
})
</script>

<template>
  <span
    :class="fill
      ? 'block w-full min-w-0'
      : 'inline-flex max-w-full items-center gap-1.5'"
    :aria-label="aria"
    data-testid="fiscal-status-badge"
  >
    <UBadge
      :color="meta.color"
      variant="subtle"
      :size="fill ? 'md' : size"
      :class="fill ? TABLE_CELL_BADGE_CLASS : 'font-normal'"
      :ui="fill ? TABLE_CELL_BADGE_UI : undefined"
    >
      <UIcon
        :name="meta.icon"
        class="size-3 shrink-0"
        aria-hidden="true"
      />
      <span class="truncate">{{ displayLabel }}</span>
    </UBadge>
    <span
      v-if="showHint && meta.sourceHint && !fill"
      class="truncate text-xs text-muted"
    >
      {{ meta.sourceHint }}
    </span>
  </span>
</template>
