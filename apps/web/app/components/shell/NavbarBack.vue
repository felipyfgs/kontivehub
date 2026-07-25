<script setup lang="ts">
/**
 * Voltar responsive — label no desktop, square no mobile.
 */
withDefaults(defineProps<{
  to: string
  label?: string
  ariaLabel?: string
  testId?: string
}>(), {
  label: 'Voltar',
  ariaLabel: undefined,
  testId: 'shell-navbar-back'
})
</script>

<template>
  <UButton
    :to="to"
    color="neutral"
    variant="ghost"
    icon="i-lucide-arrow-left"
    :label="label"
    size="sm"
    class="hidden sm:inline-flex"
    :aria-label="ariaLabel || label"
    :data-testid="testId"
  />
  <UButton
    :to="to"
    color="neutral"
    variant="ghost"
    icon="i-lucide-arrow-left"
    square
    size="sm"
    class="sm:hidden"
    :aria-label="ariaLabel || label"
    :data-testid="`${testId}-mobile`"
  />
</template>
