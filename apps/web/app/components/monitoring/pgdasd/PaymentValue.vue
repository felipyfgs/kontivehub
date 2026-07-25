<script setup lang="ts">
import type { PgdasdClientSummary } from '~/types/fiscal-modules'
import {
  pgdasdDasPaymentMeta,
  pgdasdDasPaymentState,
  pgdasdPaymentDetailItems
} from '~/utils/pgdasd'
import { TABLE_CELL_BADGE_CLASS, TABLE_CELL_BADGE_UI } from '~/utils/table-ui'

const props = defineProps<{
  summary?: PgdasdClientSummary | null
}>()

const state = computed(() => pgdasdDasPaymentState(props.summary?.payment_state))
const meta = computed(() => pgdasdDasPaymentMeta(state.value))
const detailItems = computed(() => pgdasdPaymentDetailItems(props.summary))
/** Em dia / Sem movimento: cartão curto (sem lista Situação|Detalhe). */
const isSimpleState = computed(() => state.value === 'PAID' || state.value === 'NO_DAS')
/** Estados de negócio — sem evidência mostra traço (sem rótulo “Não verificado”). */
const hasBusinessState = computed(() =>
  state.value === 'PAID' || state.value === 'UNPAID' || state.value === 'NO_DAS'
)
const badgeLabel = computed(() => hasBusinessState.value ? meta.value.label : '—')
const badgeIcon = computed(() => hasBusinessState.value ? meta.value.icon : undefined)
const badgeColor = computed(() => hasBusinessState.value ? meta.value.color : 'neutral')
const tooltipText = computed(() =>
  state.value === 'NO_DAS' ? meta.value.description : undefined
)
</script>

<template>
  <!-- Sempre um root (UPopover): UTable/h() não rende Fragment. -->
  <UPopover
    :content="{ side: 'bottom', align: 'start', sideOffset: 8 }"
    :ui="{ content: 'p-0' }"
  >
    <UTooltip
      :text="tooltipText"
      :disabled="!tooltipText"
      :content="{ side: 'top' }"
    >
      <button
        type="button"
        class="block w-full min-w-0 text-left"
        :aria-label="hasBusinessState
          ? `Detalhar pagamento PGDAS-D: ${meta.label}`
          : 'Pagamento sem evidência suficiente'"
        data-testid="pgdasd-payment"
      >
        <UBadge
          :label="badgeLabel"
          :icon="badgeIcon"
          :color="badgeColor"
          variant="subtle"
          size="md"
          :class="[TABLE_CELL_BADGE_CLASS, 'pointer-events-none']"
          :ui="TABLE_CELL_BADGE_UI"
        />
      </button>
    </UTooltip>

    <template #content>
      <div
        class="w-56 max-w-[min(14rem,calc(100vw-2rem))] p-2.5"
        data-testid="pgdasd-payment-detail"
      >
        <template v-if="hasBusinessState">
          <p class="mb-1.5 px-0.5 text-xs font-semibold text-highlighted">
            {{ meta.label === 'Pendências' ? 'Pendências' : 'Pagamento' }}
          </p>

          <div
            v-if="isSimpleState"
            class="flex items-start gap-2.5 rounded-md border border-default bg-elevated/50 px-2.5 py-2"
          >
            <UIcon
              :name="meta.icon"
              class="mt-0.5 size-4 shrink-0"
              :class="state === 'PAID' ? 'text-success' : 'text-muted'"
            />
            <div class="min-w-0 space-y-0.5">
              <p class="text-xs font-semibold text-highlighted">
                {{ meta.label }}
              </p>
              <p class="text-xs text-muted">
                {{ meta.description }}
              </p>
            </div>
          </div>

          <ul
            v-else
            class="divide-y divide-default rounded-md border border-default"
          >
            <li
              v-for="item in detailItems"
              :key="`${item.label}-${item.value}`"
              class="flex items-baseline justify-between gap-3 px-2 py-1.5 text-xs"
            >
              <span class="min-w-0 shrink-0 text-muted">
                {{ item.label }}
              </span>
              <span
                class="min-w-0 text-right font-medium tabular-nums"
                :class="item.isDebit ? 'text-error' : 'text-highlighted'"
              >
                {{ item.value || '—' }}
              </span>
            </li>
          </ul>
        </template>
        <p
          v-else
          class="px-0.5 text-xs text-muted"
        >
          Ainda sem evidência de pagamento. Rode uma consulta válida quando possível.
        </p>
      </div>
    </template>
  </UPopover>
</template>
