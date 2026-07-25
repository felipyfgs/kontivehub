<script setup lang="ts">
/**
 * Barra «N selecionados» — botão + UKbd + dropdown/slot de ações.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = withDefaults(defineProps<{
  selectedCount: number
  label?: string
  ariaLabel?: string
  loading?: boolean
  items?: DropdownMenuItem[][] | DropdownMenuItem[]
  testId?: string
}>(), {
  label: 'Ações',
  ariaLabel: 'Ações em massa',
  loading: false,
  items: undefined,
  testId: 'shell-bulk-actions'
})

const visible = computed(() => props.selectedCount > 0)
</script>

<template>
  <div
    v-if="visible"
    :data-testid="testId"
  >
    <slot>
      <UDropdownMenu
        v-if="items?.length"
        :items="items"
        :content="{ align: 'start' }"
      >
        <UButton
          color="neutral"
          variant="subtle"
          icon="i-lucide-list-checks"
          :label="label"
          :aria-label="ariaLabel"
          :ui="COMPACT_BUTTON_LABEL_UI"
          :loading="loading"
          :data-testid="`${testId}-menu`"
        >
          <template #trailing>
            <UKbd>{{ selectedCount }}</UKbd>
          </template>
        </UButton>
      </UDropdownMenu>
      <UButton
        v-else
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-checks"
        :label="label"
        :aria-label="ariaLabel"
        :ui="COMPACT_BUTTON_LABEL_UI"
        :loading="loading"
        :data-testid="`${testId}-menu`"
      >
        <template #trailing>
          <UKbd>{{ selectedCount }}</UKbd>
        </template>
      </UButton>
    </slot>
  </div>
</template>
