<script setup lang="ts">
/**
 * Célula de lista de monitores: saldo, último snapshot, próxima execução, bloqueio.
 * Layout denso — no máximo duas linhas principais.
 */
import {
  commercialBalanceLabel,
  commercialBlockLabel,
  lastSnapshotLabel,
  nextRunLabel
} from '~/utils/monitor-commercial'

const props = defineProps<{
  remaining?: number | null
  limit?: number | null
  used?: number | null
  blockReason?: string | null
  blockMessage?: string | null
  lastSnapshotAt?: string | null
  nextScheduledAt?: string | null
  isRecent?: boolean
}>()

const balance = computed(() => commercialBalanceLabel({
  remaining: props.remaining,
  limit: props.limit,
  used: props.used,
  block_reason: props.blockReason
}))

const block = computed(() =>
  props.blockMessage || commercialBlockLabel(props.blockReason)
)

const snapshotText = computed(() => lastSnapshotLabel(props.lastSnapshotAt))
const nextText = computed(() => nextRunLabel(props.nextScheduledAt))

const recentTooltip = computed(() =>
  props.isRecent ? 'Snapshot recente (dentro do TTL)' : undefined
)
</script>

<template>
  <div
    class="space-y-0.5 text-xs leading-tight"
    data-testid="monitor-commercial-meta"
  >
    <p class="min-w-0 truncate">
      <span class="text-muted">Saldo</span>
      <span class="ms-1 font-medium text-highlighted">{{ balance }}</span>
      <span class="mx-1 text-dimmed">·</span>
      <span class="text-muted">Snapshot</span>
      <UTooltip
        v-if="isRecent"
        :text="recentTooltip"
      >
        <span class="ms-1 text-highlighted underline decoration-dotted decoration-muted underline-offset-2">
          {{ snapshotText }}
        </span>
      </UTooltip>
      <span
        v-else
        class="ms-1 text-highlighted"
      >{{ snapshotText }}</span>
    </p>
    <p class="min-w-0 truncate">
      <span class="text-muted">Próxima</span>
      <span class="ms-1 text-highlighted">{{ nextText }}</span>
    </p>
    <p
      v-if="block"
      class="truncate text-warning"
      role="status"
    >
      {{ block }}
    </p>
  </div>
</template>
