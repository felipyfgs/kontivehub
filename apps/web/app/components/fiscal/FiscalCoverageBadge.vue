<script setup lang="ts">
/**
 * Badge de cobertura (FULL / PARTIAL / UNSUPPORTED…) — texto + ícone, não só cor.
 * Em tabelas (`fill`, default): ocupa a célula sem inflar o padding do td.
 */
import { coverageMeta } from '~/utils/fiscal-status'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = withDefaults(defineProps<{
  coverage?: string | null
  size?: 'xs' | 'sm' | 'md'
  showDescription?: boolean
  /** Preenche a célula da grade (ligar nas UTable). */
  fill?: boolean
}>(), {
  size: 'xs',
  showDescription: false,
  fill: false
})

const meta = computed(() => coverageMeta(props.coverage))
const aria = computed(() =>
  [meta.value.label, meta.value.description].filter(Boolean).join('. ')
)
</script>

<template>
  <span
    :class="fill
      ? 'block w-full min-w-0'
      : 'inline-flex max-w-full items-center gap-1.5'"
    :aria-label="aria"
    data-testid="fiscal-coverage-badge"
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
      <span class="truncate">{{ meta.label }}</span>
    </UBadge>
    <span
      v-if="showDescription && !fill"
      class="truncate text-xs text-muted"
    >
      {{ meta.description }}
    </span>
  </span>
</template>
