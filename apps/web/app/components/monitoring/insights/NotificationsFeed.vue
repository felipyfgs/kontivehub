<script setup lang="ts">
import type { MonitoringInsightsNotification } from '~/types/monitoring-insights'
import { formatDateTime } from '~/utils/format'

const props = defineProps<{
  items: MonitoringInsightsNotification[] | null
  loading?: boolean
  error?: string | null
}>()

const q = ref('')
const filtered = computed(() => {
  const list = props.items ?? []
  const needle = q.value.trim().toLowerCase()
  if (!needle) return list
  return list.filter((item) => {
    const hay = `${item.title} ${item.body ?? ''} ${item.type}`.toLowerCase()
    return hay.includes(needle)
  })
})

function typeIcon(type: string) {
  if (type === 'pending') return 'i-lucide-circle-dashed'
  if (type === 'finding') return 'i-lucide-triangle-alert'
  if (type === 'alert') return 'i-lucide-mail'
  return 'i-lucide-bell'
}

function notificationLink(item: MonitoringInsightsNotification): string | undefined {
  if (item.deep_link?.startsWith('/')) return item.deep_link
  if (item.client_id != null) return `/monitoring/clients/${item.client_id}`
  return undefined
}
</script>

<template>
  <UPageCard
    variant="subtle"
    data-testid="insights-notifications-card"
    :ui="{ body: 'space-y-3' }"
  >
    <template #header>
      <div>
        <p class="text-xs uppercase text-muted">
          Atividade recente
        </p>
        <p class="mt-1 text-sm text-muted">
          Pendências, findings e alertas e-CAC.
        </p>
      </div>
    </template>

    <UInput
      v-model="q"
      icon="i-lucide-search"
      size="sm"
      placeholder="Filtrar…"
      :disabled="loading && !items"
    />

    <p
      v-if="error"
      class="text-sm text-error"
    >
      {{ error }}
    </p>
    <div
      v-else-if="loading && !items"
      class="py-6 text-center text-sm text-muted"
    >
      Carregando…
    </div>
    <MonitoringTableEmptyState
      v-else-if="!filtered.length"
      kind="empty"
      title="Sem notificações"
      description="Nada para exibir no momento."
    />
    <ul
      v-else
      class="max-h-72 divide-y divide-default overflow-y-auto"
    >
      <li
        v-for="item in filtered"
        :key="item.id"
        class="py-0.5"
      >
        <ULink
          :to="notificationLink(item)"
          class="flex gap-3 rounded-md px-1 py-2 text-default transition-colors"
          :class="notificationLink(item) ? 'hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary' : ''"
        >
          <UIcon
            :name="typeIcon(item.type)"
            class="mt-0.5 size-4 shrink-0 text-muted"
          />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ item.title }}
            </p>
            <p
              v-if="item.body"
              class="line-clamp-2 text-xs text-muted"
            >
              {{ item.body }}
            </p>
            <p
              v-if="item.occurred_at"
              class="mt-0.5 text-[10px] text-muted"
            >
              {{ formatDateTime(item.occurred_at) }}
            </p>
          </div>
          <UIcon
            v-if="notificationLink(item)"
            name="i-lucide-chevron-right"
            class="mt-0.5 size-4 shrink-0 text-dimmed"
          />
        </ULink>
      </li>
    </ul>
  </UPageCard>
</template>
