<script setup lang="ts">
/**
 * Skeletons padrão no body do modal enquanto fetch.
 */
withDefaults(defineProps<{
  rows?: number
  testId?: string
}>(), {
  rows: 3,
  testId: 'shell-loading-modal-body'
})
</script>

<template>
  <div
    class="space-y-3"
    :data-testid="testId"
    role="status"
    aria-label="Carregando"
  >
    <USkeleton class="h-8 w-1/3" />
    <USkeleton
      v-for="i in rows"
      :key="i"
      class="h-16 w-full"
    />
  </div>
</template>
