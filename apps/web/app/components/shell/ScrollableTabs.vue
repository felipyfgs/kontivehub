<script setup lang="ts">
/**
 * UTabs com scroll touch horizontal — padrão mobile das faixas KPI / fila / submódulos.
 * size sm no viewport estreito; md (ou prop) no desktop.
 */
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import {
  LINK_TABS_UI,
  SCROLLABLE_TABS_UI,
  TOUCH_SCROLL_X
} from '~/utils/list-filter-layout'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  modelValue?: string | number | null
  items: Array<Record<string, unknown>>
  content?: boolean
  /** Size no desktop (sm+). Mobile sempre sm. */
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
  color?: 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'
  variant?: 'pill' | 'link'
  ariaLabel?: string
  testId?: string | null
  /** Merge opcional em cima do ui canônico de scroll. */
  ui?: Record<string, string>
}>(), {
  modelValue: null,
  content: false,
  size: 'md',
  color: 'primary',
  variant: 'pill',
  ariaLabel: undefined,
  testId: null,
  ui: () => ({})
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const breakpoints = useBreakpoints(breakpointsTailwind)
const isNarrow = breakpoints.smaller('sm')
const resolvedSize = computed(() => (isNarrow.value ? 'sm' : props.size))

const mergedUi = computed(() => ({
  ...(props.variant === 'link' ? LINK_TABS_UI : SCROLLABLE_TABS_UI),
  ...props.ui
}))
</script>

<template>
  <div
    :class="[TOUCH_SCROLL_X, $attrs.class]"
    :data-testid="testId || undefined"
  >
    <UTabs
      :model-value="modelValue ?? undefined"
      :items="items"
      :content="content"
      activation-mode="automatic"
      :size="resolvedSize"
      :color="color"
      :variant="variant"
      :ui="mergedUi"
      :aria-label="ariaLabel"
      @update:model-value="emit('update:modelValue', $event as string | number)"
    />
  </div>
</template>
