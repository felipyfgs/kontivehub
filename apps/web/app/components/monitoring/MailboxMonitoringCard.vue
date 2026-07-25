<script setup lang="ts">
import type { MailboxMonitoringStatus } from '~/types/mailbox-monitoring'
import { resolveMailboxMonitoringPresentation } from '~/utils/mailbox-monitoring'

const props = defineProps<{
  status: MailboxMonitoringStatus | null
  messageCount: number
  loading?: boolean
  saving?: boolean
  previewing?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  refresh: []
  save: [value: { enabled: boolean, mode: 'ECONOMICO' }]
  updateNow: []
}>()

const enabled = ref(false)

watch(
  () => [props.status?.enabled, props.saving] as const,
  ([statusEnabled, saving]) => {
    if (!saving) enabled.value = statusEnabled ?? false
  },
  { immediate: true }
)

const presentation = computed(() => props.status
  ? resolveMailboxMonitoringPresentation(props.status, props.messageCount)
  : null)

function toggleAutomation(value: boolean) {
  enabled.value = value
  emit('save', { enabled: value, mode: 'ECONOMICO' })
}
</script>

<template>
  <section
    data-testid="mailbox-monitoring-card"
    aria-labelledby="mailbox-monitoring-title"
    class="border-b border-default pb-3"
  >
    <div v-if="loading && !status" class="flex items-center gap-3 py-1">
      <USkeleton class="size-9 rounded-lg" />
      <div class="min-w-0 flex-1 space-y-2">
        <USkeleton class="h-4 w-48" />
        <USkeleton class="h-3 w-72 max-w-full" />
      </div>
      <USkeleton class="hidden h-8 w-56 sm:block" />
    </div>

    <div v-else class="space-y-2.5">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 items-center gap-2.5">
          <UIcon
            :name="presentation?.icon || 'i-lucide-inbox'"
            class="size-5 shrink-0 text-primary"
          />
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 id="mailbox-monitoring-title" class="text-sm font-medium text-highlighted">
                Monitoramento e-CAC
              </h2>
              <UBadge
                v-if="presentation"
                size="sm"
                :color="presentation.color"
                variant="subtle"
                :label="presentation.label"
                data-testid="mailbox-monitoring-state"
              />
            </div>
            <p class="mt-1 text-xs text-muted">
              {{ presentation?.description || 'Status indisponível.' }}
            </p>
          </div>
        </div>

        <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end">
          <USwitch
            :model-value="enabled"
            label="Busca automática"
            size="sm"
            :loading="saving"
            :disabled="saving"
            @update:model-value="toggleAutomation"
          />
          <UButton
            size="sm"
            icon="i-lucide-refresh-cw"
            label="Atualizar agora"
            :loading="previewing"
            data-testid="mailbox-update-now"
            @click="emit('updateNow')"
          />
        </div>
      </div>

      <p v-if="error" class="flex items-center gap-1.5 text-xs text-error">
        <UIcon name="i-lucide-circle-x" class="size-4 shrink-0" />
        <span>{{ error }}</span>
        <UButton
          size="xs"
          color="error"
          variant="link"
          label="Tentar novamente"
          class="p-0"
          @click="emit('refresh')"
        />
      </p>

      <p
        v-else-if="status?.enabled && !status.runtime_enabled"
        class="flex items-center gap-1.5 text-xs text-warning"
      >
        <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
        <span>Busca automática aguardando liberação. Se persistir, fale com o suporte.</span>
      </p>
    </div>
  </section>
</template>
