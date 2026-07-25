<script setup lang="ts">
/**
 * Toolbar leve: busca + refresh (sem DataTableFilter).
 * Para listas admin/settings que não usam chips estruturados.
 */
import {
  COMPACT_BUTTON_LABEL_UI,
  LIST_FILTER_SEARCH_INPUT,
  LIST_FILTER_TOOLBAR_STACK
} from '~/utils/list-filter-layout'

const props = withDefaults(defineProps<{
  q?: string
  searchPlaceholder?: string
  searchAriaLabel?: string
  loading?: boolean
  showRefresh?: boolean
  testIdPrefix?: string
}>(), {
  q: '',
  searchPlaceholder: 'Buscar…',
  searchAriaLabel: 'Buscar',
  loading: false,
  showRefresh: true,
  testIdPrefix: 'list-filter-lite'
})

const emit = defineEmits<{
  'update:q': [value: string]
  'submit-q': []
  'refresh': []
}>()

const qDraft = ref(props.q)
watch(() => props.q, (value) => {
  if (value !== qDraft.value) qDraft.value = value
})

let qDebounce: ReturnType<typeof setTimeout> | null = null
function onQInput(value: string | number) {
  const next = String(value ?? '')
  qDraft.value = next
  if (qDebounce) clearTimeout(qDebounce)
  qDebounce = setTimeout(() => emit('update:q', next), 320)
}

function submitQ() {
  if (qDebounce) {
    clearTimeout(qDebounce)
    qDebounce = null
  }
  emit('update:q', qDraft.value)
  emit('submit-q')
}

onBeforeUnmount(() => {
  if (qDebounce) clearTimeout(qDebounce)
})
</script>

<template>
  <div
    class="w-full min-w-0"
    data-testid="page-toolbar-lite"
  >
    <div :class="LIST_FILTER_TOOLBAR_STACK">
      <UInput
        :model-value="qDraft"
        icon="i-lucide-search"
        :placeholder="searchPlaceholder"
        :class="LIST_FILTER_SEARCH_INPUT"
        size="md"
        :aria-label="searchAriaLabel"
        :data-testid="`${testIdPrefix}-q`"
        @update:model-value="onQInput"
        @keyup.enter="submitQ"
      />
      <div class="flex shrink-0 items-center gap-1.5">
        <slot name="actions" />
        <UButton
          v-if="showRefresh"
          color="neutral"
          variant="ghost"
          icon="i-lucide-refresh-cw"
          square
          :loading="loading"
          aria-label="Atualizar"
          :ui="COMPACT_BUTTON_LABEL_UI"
          :data-testid="`${testIdPrefix}-refresh`"
          @click="emit('refresh')"
        />
      </div>
    </div>
  </div>
</template>
