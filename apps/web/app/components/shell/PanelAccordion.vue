<script setup lang="ts">
/**
 * Acordeão de painéis secundários empilhados (doc Nuxt UI Accordion).
 * Prefira slots `#{{name}}-body` nos consumidores para herdar o wrapper `body`.
 */
import type { AccordionItem } from '@nuxt/ui'

const props = withDefaults(defineProps<{
  items: AccordionItem[]
  defaultValue?: string | string[]
  modelValue?: string | string[]
  type?: 'single' | 'multiple'
  testId?: string
}>(), {
  type: 'multiple',
  defaultValue: undefined,
  modelValue: undefined,
  testId: 'panel-accordion'
})

const emit = defineEmits<{
  'update:modelValue': [value: string | string[]]
}>()

const isControlled = computed(() => props.modelValue !== undefined)

function updateModel(value: string | string[] | undefined): void {
  if (value !== undefined) emit('update:modelValue', value)
}
</script>

<template>
  <UAccordion
    v-if="isControlled"
    :model-value="modelValue"
    :items="items"
    :type="type"
    :unmount-on-hide="false"
    :ui="{
      root: 'min-w-0 w-full shrink-0 flex flex-col gap-2',
      item: 'rounded-lg border border-default bg-elevated/50 overflow-hidden',
      trigger: 'px-4 py-3 sm:px-6 text-sm font-medium text-highlighted',
      content: 'px-4 sm:px-6',
      body: 'pb-4 text-sm',
      label: 'min-w-0 truncate'
    }"
    :data-testid="testId"
    @update:model-value="updateModel"
  >
    <template
      v-for="(_, name) in $slots"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData || {}"
      />
    </template>
  </UAccordion>
  <UAccordion
    v-else
    :items="items"
    :type="type"
    :default-value="defaultValue"
    :unmount-on-hide="false"
    :ui="{
      root: 'min-w-0 w-full shrink-0 flex flex-col gap-2',
      item: 'rounded-lg border border-default bg-elevated/50 overflow-hidden',
      trigger: 'px-4 py-3 sm:px-6 text-sm font-medium text-highlighted',
      content: 'px-4 sm:px-6',
      body: 'pb-4 text-sm',
      label: 'min-w-0 truncate'
    }"
    :data-testid="testId"
  >
    <template
      v-for="(_, name) in $slots"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData || {}"
      />
    </template>
  </UAccordion>
</template>
