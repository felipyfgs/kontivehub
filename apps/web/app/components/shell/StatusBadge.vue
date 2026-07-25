<script setup lang="ts">
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = withDefaults(defineProps<{
  status?: string | null
  label?: string | null
  /** Preenche a célula da grade. */
  fill?: boolean
}>(), {
  fill: false
})

const color = computed(() => {
  const s = (props.status || '').toUpperCase()
  // NFS-e operacional: Autorizada (success) / Cancelada (error) / Em revisão (warning)
  if (['ACTIVE', 'SUBSTITUTE', 'JUDICIAL', 'AUTHORIZED'].includes(s)) {
    return 'success' as const
  }
  if (['CANCELLED', 'CANCELED', 'SUPERSEDED', 'REPLACED', 'DENIED', 'DENEGADA'].includes(s)) {
    return 'error' as const
  }
  if (['UNKNOWN', 'REVIEW'].includes(s)) {
    return 'warning' as const
  }
  // Demais módulos do painel
  if (['FAILED', 'ERROR', 'EXPIRED', 'BLOCKED'].includes(s)) {
    return 'error' as const
  }
  if (['PENDING', 'PROCESSING', 'RUNNING', 'WAITING'].includes(s)) {
    return 'warning' as const
  }
  if (['READY', 'COMPLETED', 'IDLE'].includes(s)) {
    return 'success' as const
  }
  return 'neutral' as const
})
</script>

<template>
  <span :class="fill ? 'block w-full min-w-0' : undefined">
    <UBadge
      :color="color"
      variant="soft"
      :size="fill ? 'md' : undefined"
      :class="fill ? TABLE_CELL_BADGE_CLASS : 'font-normal'"
      :ui="fill ? TABLE_CELL_BADGE_UI : undefined"
    >
      {{ label || statusLabel(status) }}
    </UBadge>
  </span>
</template>
