<script setup lang="ts">
/**
 * Botão refresh ghost padronizado (navbar / toolbar).
 */
withDefaults(defineProps<{
  loading?: boolean
  label?: string
  ariaLabel?: string
  /** square = só ícone (mobile); default ghost ícone. */
  square?: boolean
  testId?: string
}>(), {
  loading: false,
  label: undefined,
  ariaLabel: 'Atualizar',
  square: true,
  testId: 'shell-navbar-refresh'
})

const emit = defineEmits<{
  click: []
}>()
</script>

<template>
  <UTooltip :text="ariaLabel">
    <UButton
      color="neutral"
      variant="ghost"
      icon="i-lucide-refresh-cw"
      :label="square ? undefined : label"
      :square="square || !label"
      :loading="loading"
      :aria-label="ariaLabel"
      :data-testid="testId"
      @click="emit('click')"
    />
  </UTooltip>
</template>
