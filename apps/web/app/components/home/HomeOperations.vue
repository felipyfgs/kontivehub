<script setup lang="ts">
import type { InboxItem, OperationsSummary } from '~/types/api'
import { resolveInboxItemLink } from '~/utils/inbox-links'
import { inboxSeverityColor } from '~/utils/inbox-severity'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
  error?: string | null
  inboxItems?: InboxItem[]
  inboxLoading?: boolean
}>()

const emit = defineEmits<{
  retry: []
}>()

const attentionTotal = computed(() => {
  if (!props.summary) return 0
  if (typeof props.summary.inbox_total === 'number') {
    // Exports pendentes não viram item de inbox; mantém no total de atenção do home.
    return props.summary.inbox_total + (props.summary.exports_pending || 0)
  }
  return props.summary.sync_failures_24h
    + props.summary.sync_blocked
    + props.summary.credentials_expiring_30d
    + props.summary.exports_pending
})

const topItems = computed(() => (props.inboxItems || []).slice(0, 5))

const backup = computed(() => props.summary?.backup)

function severityColor(severity: string): 'error' | 'warning' | 'info' | 'neutral' {
  return inboxSeverityColor(severity)
}

function itemLink(item: InboxItem): string {
  return resolveInboxItemLink(item)
}
</script>

<template>
  <div
    data-testid="home-operations"
    class="min-w-0 shrink-0"
  >
    <UPageCard
      class="min-w-0 overflow-hidden"
      variant="subtle"
      title="Atenção"
      :ui="{
        container: 'min-w-0 gap-y-2 p-3 sm:p-4',
        title: 'text-xs font-normal uppercase text-muted truncate'
      }"
    >
      <template #header>
        <div class="flex min-w-0 items-center justify-between gap-2">
          <div class="min-w-0">
            <p class="text-xs uppercase text-muted">
              Atenção
            </p>
            <p class="text-2xl font-semibold tabular-nums text-highlighted sm:text-3xl">
              {{ loading && !summary ? '—' : attentionTotal }}
            </p>
          </div>
          <UButton
            to="/health"
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-lucide-arrow-right"
            square
            aria-label="Ver saúde"
          />
        </div>
      </template>

      <UAlert
        v-if="error && summary"
        color="warning"
        variant="subtle"
        icon="i-lucide-wifi-off"
        :title="error"
        class="mb-4"
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => emit('retry')
        }]"
      />

      <UAlert
        v-if="backup?.never"
        color="error"
        variant="subtle"
        icon="i-lucide-database-zap"
        title="Nenhum backup operacional concluído"
        class="mb-3"
        :actions="[{ label: 'Saúde', to: '/health', color: 'neutral', variant: 'ghost' }]"
      />
      <UAlert
        v-else-if="backup?.stale"
        color="warning"
        variant="subtle"
        icon="i-lucide-database-backup"
        :title="backup.last_success_at
          ? `Último backup: ${formatDateTime(backup.last_success_at)} (>24h).`
          : 'Mais de 24h sem backup OK.'"
        class="mb-3"
        :actions="[{ label: 'Saúde', to: '/health', color: 'neutral', variant: 'ghost' }]"
      />

      <div v-if="topItems.length" class="space-y-2 mb-4">
        <NuxtLink
          v-for="item in topItems"
          :key="item.id"
          :to="itemLink(item)"
          class="flex min-w-0 items-start gap-3 rounded-md border border-default px-3 py-2.5 hover:bg-elevated/50"
        >
          <UBadge
            :color="severityColor(item.severity)"
            variant="subtle"
            class="mt-0.5 shrink-0 capitalize"
          >
            {{ item.severity }}
          </UBadge>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ item.title }}
            </p>
            <p class="truncate text-xs text-muted">
              {{ item.body }}
            </p>
          </div>
          <UIcon name="i-lucide-chevron-right" class="mt-1 size-4 shrink-0 text-muted" />
        </NuxtLink>
      </div>

      <div v-else-if="summary" class="grid gap-3 sm:grid-cols-2">
        <UAlert
          v-if="summary.sync_failures_24h"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="`Falhas de sincronização (24h): ${summary.sync_failures_24h}`"
          :actions="[{ label: 'Syncs', to: '/syncs', color: 'neutral', variant: 'ghost' }]"
        />
        <UAlert
          v-if="summary.sync_blocked"
          color="error"
          variant="subtle"
          icon="i-lucide-ban"
          :title="`Cursores bloqueados: ${summary.sync_blocked}`"
          :actions="[{ label: 'Syncs', to: '/syncs', color: 'neutral', variant: 'ghost' }]"
        />
        <UAlert
          v-if="summary.credentials_expiring_30d"
          color="warning"
          variant="subtle"
          icon="i-lucide-badge-alert"
          :title="`Certificados A1 a vencer (30d): ${summary.credentials_expiring_30d}`"
          :actions="[{ label: 'Clientes', to: '/clients', color: 'neutral', variant: 'ghost' }]"
        />
        <UAlert
          v-if="summary.exports_pending"
          color="primary"
          variant="subtle"
          icon="i-lucide-loader-circle"
          :title="`Exportações na fila: ${summary.exports_pending}`"
          :actions="[{ label: 'Export', to: '/exports', color: 'neutral', variant: 'ghost' }]"
        />
        <UAlert
          v-if="attentionTotal === 0 && !backup?.stale && !backup?.never"
          color="success"
          variant="subtle"
          icon="i-lucide-circle-check"
          title="Sem alertas críticos"
          class="sm:col-span-2"
        />
      </div>

      <UEmpty
        v-else-if="!loading && error"
        icon="i-lucide-cloud-off"
        :title="error"
      >
        <UButton
          color="neutral"
          variant="subtle"
          label="Tentar novamente"
          @click="emit('retry')"
        />
      </UEmpty>

      <div
        v-else-if="loading || inboxLoading"
        class="grid gap-3 sm:grid-cols-2"
        role="status"
        aria-label="Carregando resumo operacional"
      >
        <USkeleton v-for="index in 4" :key="index" class="h-24 w-full" />
      </div>
    </UPageCard>
  </div>
</template>
