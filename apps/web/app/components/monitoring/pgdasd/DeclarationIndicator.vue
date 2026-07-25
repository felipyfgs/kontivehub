<script setup lang="ts">
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = defineProps<{
  period?: string | null
  state?: string | null
  reason?: string | null
  /** Tooltip completo (entrega + PA + consulta); se omitido, monta label + reason. */
  tooltipText?: string | null
}>()

const meta = computed(() => pgdasdDeclarationMeta(props.state))
const tooltip = computed(() => {
  if (props.tooltipText?.trim()) {
    return props.tooltipText.trim()
  }
  return [
    meta.value.label,
    props.reason?.trim() || meta.value.description
  ].join(': ')
})
</script>

<template>
  <UTooltip :text="tooltip" :content="{ side: 'top' }">
    <div class="block w-full min-w-0">
      <UBadge
        :label="period || '—'"
        :icon="meta.icon"
        :color="meta.color"
        size="md"
        variant="subtle"
        :class="TABLE_CELL_BADGE_CLASS"
        :ui="TABLE_CELL_BADGE_UI"
        :aria-label="`${period || 'Período não informado'}: ${meta.label}`"
        data-testid="pgdasd-declaration-indicator"
      />
    </div>
  </UTooltip>
</template>
