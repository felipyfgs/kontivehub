<script setup lang="ts">
/**
 * Menu ellipsis de ações por linha (padrão customers.vue).
 */
import type { DropdownMenuItem } from '@nuxt/ui'

withDefaults(defineProps<{
  items: DropdownMenuItem[][] | DropdownMenuItem[]
  ariaLabel?: string
  testId?: string
}>(), {
  ariaLabel: 'Ações da linha',
  testId: 'shell-row-actions'
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'end' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      icon="i-lucide-ellipsis-vertical"
      size="xs"
      square
      :aria-label="ariaLabel"
      :data-testid="testId"
    />
  </UDropdownMenu>
</template>
