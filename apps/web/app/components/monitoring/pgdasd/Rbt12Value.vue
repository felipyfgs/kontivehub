<script setup lang="ts">
import type { PgdasdRbt12Summary } from '~/types/fiscal-modules'
import { pgdasdRbt12DetailItems } from '~/utils/pgdasd'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = defineProps<{
  rbt12?: PgdasdRbt12Summary | null
}>()

const available = computed(() => {
  const parsed = props.rbt12?.status === 'PARSED'
  return parsed && (props.rbt12?.total_cents != null || Boolean(props.rbt12?.rbt12_value))
})

const value = computed(() => available.value
  ? props.rbt12?.total_cents != null
    ? formatAmountCents(props.rbt12.total_cents)
    : formatCurrency(props.rbt12?.rbt12_value)
  : '—'
)

const detailItems = computed(() => pgdasdRbt12DetailItems(props.rbt12))
</script>

<template>
  <UPopover
    :content="{ side: 'bottom', align: 'start', sideOffset: 8 }"
    :ui="{ content: 'p-0' }"
  >
    <button
      type="button"
      class="block w-full min-w-0 text-left"
      :aria-label="available ? `Detalhar RBT12 ${value}` : 'Detalhar RBT12 indisponível'"
      data-testid="pgdasd-rbt12-value"
    >
      <UBadge
        :label="value"
        :icon="available ? 'i-lucide-chart-no-axes-column-increasing' : 'i-lucide-circle-help'"
        :color="available ? 'success' : 'neutral'"
        :variant="available ? 'outline' : 'subtle'"
        size="md"
        :class="[TABLE_CELL_BADGE_CLASS, 'pointer-events-none']"
        :ui="TABLE_CELL_BADGE_UI"
      />
    </button>

    <template #content>
      <div
        class="w-56 max-w-[min(14rem,calc(100vw-2rem))] p-2.5"
        data-testid="pgdasd-rbt12-detail"
      >
        <p class="mb-1.5 px-0.5 text-xs font-semibold text-highlighted">
          RBT12
        </p>
        <ul class="divide-y divide-default rounded-md border border-default">
          <li
            v-for="item in detailItems"
            :key="item.label"
            class="flex items-baseline justify-between gap-3 px-2 py-1.5 text-xs"
          >
            <span class="shrink-0 text-muted">
              {{ item.label }}
            </span>
            <span class="min-w-0 text-right font-medium tabular-nums text-highlighted">
              {{ item.value }}
            </span>
          </li>
        </ul>
      </div>
    </template>
  </UPopover>
</template>
