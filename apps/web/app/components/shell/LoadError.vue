<script setup lang="ts">
/**
 * Erro de carga de lista/página + «Tentar novamente».
 */
withDefaults(defineProps<{
  title?: string
  description?: string | null
  color?: 'error' | 'warning'
  retryLabel?: string
  testId?: string
}>(), {
  title: 'Falha ao carregar',
  description: null,
  color: 'error',
  retryLabel: 'Tentar novamente',
  testId: 'shell-load-error'
})

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <UAlert
    :class="'mb-4'"
    :color="color"
    icon="i-lucide-wifi-off"
    :title="title"
    :description="description || undefined"
    :data-testid="testId"
    :actions="[{
      label: retryLabel,
      color: 'neutral',
      variant: 'subtle',
      onClick: () => emit('retry')
    }]"
  >
    <template
      v-if="$slots.default"
      #description
    >
      <slot />
    </template>
  </UAlert>
</template>
