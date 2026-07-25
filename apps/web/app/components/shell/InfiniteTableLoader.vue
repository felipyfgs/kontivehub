<script setup lang="ts">
/**
 * Sentinel IntersectionObserver para listas cursor / «carregar mais».
 * Auto-import: ShellInfiniteTableLoader.
 * Nenhum consumidor ativo nesta change — disponível para docs cursor / syncs.
 */
const props = defineProps<{ loading: boolean, hasMore: boolean }>()
const emit = defineEmits<{ load: [] }>()
const sentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null
let visible = false

function requestNext() {
  if (visible && props.hasMore && !props.loading) emit('load')
}

onMounted(() => {
  observer = new IntersectionObserver(([entry]) => {
    visible = Boolean(entry?.isIntersecting)
    requestNext()
  }, { rootMargin: '240px 0px' })
  if (sentinel.value) observer.observe(sentinel.value)
})

watch(() => [props.loading, props.hasMore], requestNext)
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div
    ref="sentinel"
    class="flex min-h-8 items-center justify-center py-2 text-sm text-muted"
    aria-live="polite"
  >
    <UIcon
      v-if="loading"
      name="i-lucide-loader-circle"
      class="size-4 animate-spin"
      aria-label="Carregando mais resultados"
    />
  </div>
</template>
