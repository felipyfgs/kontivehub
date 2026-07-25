<script setup lang="ts">
/**
 * Badge de origem do dado (DEMO / SIMULATED / LIVE).
 * DEMO/SIMULATED ficam sempre visíveis semanticamente.
 */
import { dataOriginMeta } from '~/utils/fiscal-status'

const props = withDefaults(defineProps<{
  origin?: string | null
  size?: 'xs' | 'sm' | 'md'
  /** Esconde badge LIVE (default true — só destaca sintético). */
  hideLive?: boolean
}>(), {
  size: 'sm',
  hideLive: true
})

const meta = computed(() => dataOriginMeta(props.origin))
const visible = computed(() => {
  if (!props.origin) return false
  if (props.hideLive && !meta.value.synthetic) return false
  return true
})
const aria = computed(() =>
  [meta.value.label, meta.value.description].filter(Boolean).join('. ')
)
</script>

<template>
  <span
    v-if="visible"
    class="inline-flex max-w-full items-center gap-1.5"
    :aria-label="aria"
    data-testid="fiscal-data-origin-badge"
  >
    <UBadge
      :color="meta.color"
      variant="subtle"
      :size="size"
      class="font-normal"
    >
      <UIcon
        :name="meta.icon"
        class="size-3.5 shrink-0"
        aria-hidden="true"
      />
      <span>{{ meta.label }}</span>
    </UBadge>
  </span>
</template>
