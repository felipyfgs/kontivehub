<script setup lang="ts">
/**
 * Card de seção canônico — UPageCard subtle (settings body / painel).
 * Título e descrição breves; conteúdo no slot default.
 */
withDefaults(defineProps<{
  title?: string
  description?: string
  icon?: string
  /** data-testid */
  testId?: string
}>(), {
  testId: undefined
})
</script>

<template>
  <UPageCard
    :title="title"
    :description="description"
    :icon="icon"
    variant="subtle"
    class="min-w-0 overflow-hidden"
    :data-testid="testId"
  >
    <template
      v-if="$slots.header"
      #header
    >
      <slot name="header" />
    </template>
    <slot />
    <template
      v-if="$slots.footer"
      #footer
    >
      <slot name="footer" />
    </template>
  </UPageCard>
</template>
