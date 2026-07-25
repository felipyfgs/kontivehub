<script setup lang="ts">
/**
 * Empty tipado de lista admin — empty / filtered / error (+ retry).
 * Domínio fiscal com kinds extras continua em MonitoringTableEmptyState.
 */
export type ShellListEmptyKind = 'empty' | 'filtered' | 'error'

const props = withDefaults(defineProps<{
  kind?: ShellListEmptyKind
  title?: string
  description?: string
  error?: string | null
  /** Ações extras (CTA primário etc.) — além do retry em error. */
  showRetry?: boolean
  retryLabel?: string
  testId?: string
}>(), {
  kind: 'empty',
  showRetry: undefined,
  retryLabel: 'Tentar novamente',
  testId: 'shell-list-empty'
})

const emit = defineEmits<{
  retry: []
}>()

const resolved = computed(() => {
  switch (props.kind) {
    case 'error':
      return {
        icon: 'i-lucide-circle-x',
        title: props.title || 'Falha ao carregar',
        description: props.error || props.description || 'Tente de novo.',
        retry: props.showRetry !== false
      }
    case 'filtered':
      return {
        icon: 'i-lucide-filter-x',
        title: props.title || 'Sem resultados',
        description: props.description || 'Ajuste os filtros.',
        retry: props.showRetry === true
      }
    default:
      return {
        icon: 'i-lucide-inbox',
        title: props.title || 'Nenhum registro',
        description: props.description || 'Ainda não há dados nesta lista.',
        retry: props.showRetry === true
      }
  }
})
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-3 py-10 text-center"
    :data-testid="`${testId}-${kind}`"
    role="status"
  >
    <UIcon
      :name="resolved.icon"
      class="size-8 text-dimmed"
      aria-hidden="true"
    />
    <p class="font-medium text-highlighted">
      {{ resolved.title }}
    </p>
    <p
      v-if="resolved.description"
      class="max-w-md text-sm text-muted"
    >
      {{ resolved.description }}
    </p>
    <div class="flex flex-wrap items-center justify-center gap-2">
      <UButton
        v-if="resolved.retry"
        size="sm"
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        :label="retryLabel"
        :data-testid="`${testId}-retry`"
        @click="emit('retry')"
      />
      <slot name="actions" />
    </div>
  </div>
</template>
