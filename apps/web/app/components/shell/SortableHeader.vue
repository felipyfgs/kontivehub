<script setup lang="ts">
/**
 * Header de coluna sortável — botão ghost acessível (customers.vue).
 */
const props = withDefaults(defineProps<{
  label: string
  sorted?: false | 'asc' | 'desc'
}>(), {
  sorted: false
})

const emit = defineEmits<{
  sort: []
}>()

const icon = computed(() => {
  if (props.sorted === 'asc') return 'i-lucide-arrow-up-narrow-wide'
  if (props.sorted === 'desc') return 'i-lucide-arrow-down-wide-narrow'
  return 'i-lucide-arrow-up-down'
})
</script>

<template>
  <UButton
    color="neutral"
    variant="ghost"
    size="sm"
    class="-mx-2.5"
    :label="label"
    :icon="icon"
    :aria-label="`Ordenar por ${label}`"
    @click="emit('sort')"
  />
</template>
