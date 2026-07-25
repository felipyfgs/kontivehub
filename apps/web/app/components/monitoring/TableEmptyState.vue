<script setup lang="ts">
/**
 * Empty / error / unsupported / blocked distintos — nunca reutiliza screenshot de sucesso.
 */
import type { FiscalTableEmptyKind } from '~/types/fiscal-modules'

const props = withDefaults(defineProps<{
  kind?: FiscalTableEmptyKind
  /** Título canônico. */
  title?: string
  /** Alias de `title` (páginas legadas: empty-title). */
  emptyTitle?: string
  description?: string
  error?: string | null
}>(), {
  kind: 'empty'
})

const emit = defineEmits<{
  retry: []
}>()

const resolvedTitle = computed(() => props.title || props.emptyTitle || '')

const resolved = computed(() => {
  const customTitle = resolvedTitle.value || undefined
  switch (props.kind) {
    case 'loading':
      return {
        icon: 'i-lucide-loader-circle',
        title: customTitle || 'Carregando…',
        description: props.description || 'Aguarde.',
        spin: true,
        retry: false
      }
    case 'error':
      return {
        icon: 'i-lucide-circle-x',
        title: customTitle || 'Falha ao carregar',
        description: props.error || props.description || 'Tente de novo.',
        spin: false,
        retry: true
      }
    case 'unsupported':
      return {
        icon: 'i-lucide-ban',
        title: customTitle || 'Não suportado',
        description: props.description || 'Sem integração oficial.',
        spin: false,
        retry: false
      }
    case 'blocked':
      return {
        icon: 'i-lucide-shield-off',
        title: customTitle || 'Bloqueado',
        description: props.description || 'Consulta bloqueada.',
        spin: false,
        retry: true
      }
    case 'filtered':
      return {
        icon: 'i-lucide-filter-x',
        title: customTitle || 'Sem resultados',
        description: props.description || 'Ajuste os filtros.',
        spin: false,
        retry: false
      }
    default:
      return {
        icon: 'i-lucide-inbox',
        title: customTitle || 'Nenhum registro',
        description: props.description || 'Sem dados neste módulo.',
        spin: false,
        retry: false
      }
  }
})
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-3 py-10 text-center"
    :data-testid="`fiscal-empty-${kind}`"
    role="status"
  >
    <UIcon
      :name="resolved.icon"
      class="size-8 text-dimmed"
      :class="resolved.spin ? 'animate-spin' : ''"
      aria-hidden="true"
    />
    <p class="font-medium text-highlighted">
      {{ resolved.title }}
    </p>
    <p class="max-w-md text-sm text-muted">
      {{ resolved.description }}
    </p>
    <UButton
      v-if="resolved.retry"
      size="sm"
      color="neutral"
      variant="outline"
      icon="i-lucide-refresh-cw"
      label="Tentar de novo"
      data-testid="fiscal-empty-retry"
      @click="emit('retry')"
    />
  </div>
</template>
