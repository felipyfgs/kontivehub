<script setup lang="ts">
import { formatTimeAgo } from '@vueuse/core'
import type { AppNotification, InboxItem } from '~/types/api'
import { resolveInboxItemLink } from '~/utils/inbox-links'

const { isNotificationsSlideoverOpen, me, sessionEpoch } = useDashboard()
const api = useApi()
const notifications = ref<AppNotification[]>([])
/** Snapshot preservado em falha de refresh (não limpar se já houver dados). */
const lastGoodNotifications = ref<AppNotification[]>([])
const loading = ref(false)
const errorMessage = ref<string | null>(null)
const loadState = ref<'idle' | 'loading' | 'success' | 'error'>('idle')

function severityColor(severity: string): AppNotification['color'] {
  return inboxSeverityColor(severity)
}

function itemTo(item: InboxItem): string {
  return resolveInboxItemLink(item)
}

function mapInbox(items: InboxItem[]): AppNotification[] {
  return items.map(item => ({
    id: item.id,
    title: item.title,
    body: item.body,
    date: item.occurred_at || new Date().toISOString(),
    unread: true,
    to: itemTo(item),
    color: severityColor(item.severity)
  }))
}

/** Fallback sintético removido: em falha de inbox, preservar última carga ou erro explícito. */

async function load() {
  const hadData = notifications.value.length > 0 || lastGoodNotifications.value.length > 0
  loading.value = true
  if (!hadData) {
    loadState.value = 'loading'
  }
  errorMessage.value = null
  const epoch = sessionEpoch.value

  try {
    const inbox = await api.operations.inbox({ limit: 20 })
    if (epoch !== sessionEpoch.value) return
    const mapped = mapInbox(inbox.data)
    notifications.value = mapped
    lastGoodNotifications.value = mapped
    errorMessage.value = null
    loadState.value = 'success'
  } catch {
    if (epoch !== sessionEpoch.value) return
    if (lastGoodNotifications.value.length) {
      // Falha de refresh: preserva última carga válida.
      notifications.value = lastGoodNotifications.value
      errorMessage.value = 'Falha ao atualizar. Exibindo alertas da última carga válida.'
      loadState.value = 'error'
    } else {
      notifications.value = []
      errorMessage.value = 'Não foi possível carregar os alertas operacionais.'
      loadState.value = 'error'
    }
  } finally {
    if (epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

function clearNotifications() {
  notifications.value = []
  lastGoodNotifications.value = []
  errorMessage.value = null
  loadState.value = 'idle'
}

watch(isNotificationsSlideoverOpen, (open) => {
  if (open) {
    void load()
  }
})

watch(() => me.value?.id, (next, prev) => {
  if (prev !== undefined && next !== prev) {
    clearNotifications()
    if (isNotificationsSlideoverOpen.value) {
      void load()
    }
  }
  if (!next) {
    clearNotifications()
  }
})

// Troca explícita de escritório: zera e recarrega se aberto.
watch(sessionEpoch, () => {
  clearNotifications()
  if (isNotificationsSlideoverOpen.value) {
    void load()
  }
})
</script>

<template>
  <USlideover v-model:open="isNotificationsSlideoverOpen" title="Alertas operacionais">
    <template #body>
      <div
        v-if="loading"
        class="space-y-3"
        role="status"
        aria-label="Carregando alertas"
      >
        <USkeleton v-for="index in 3" :key="index" class="h-16 w-full" />
      </div>

      <div v-else-if="loadState === 'error' && !notifications.length" class="space-y-4">
        <UAlert
          color="error"
          icon="i-lucide-circle-x"
          :title="errorMessage || 'Falha ao consultar alertas'"
        />
        <UButton
          icon="i-lucide-refresh-cw"
          label="Tentar novamente"
          color="neutral"
          variant="subtle"
          @click="load"
        />
      </div>

      <template v-else>
        <UAlert
          v-if="errorMessage && notifications.length"
          color="warning"
          icon="i-lucide-triangle-alert"
          class="mb-4"
          :title="errorMessage"
          :actions="[{
            label: 'Tentar novamente',
            color: 'neutral',
            variant: 'subtle',
            onClick: load
          }]"
        />

        <div class="mb-3 flex items-center justify-between gap-2">
          <p class="text-xs text-muted">
            Fonte: inbox operacional
          </p>
          <UButton
            to="/health"
            size="xs"
            color="neutral"
            variant="ghost"
            label="Ver saúde"
            trailing-icon="i-lucide-arrow-right"
            @click="() => { isNotificationsSlideoverOpen = false }"
          />
        </div>

        <UEmpty
          v-if="!notifications.length"
          icon="i-lucide-circle-check"
          title="Nenhum alerta recente"
          description="A operação não possui ocorrências críticas no momento."
        />

        <NuxtLink
          v-for="notification in notifications"
          :key="notification.id"
          :to="notification.to || '/health'"
          class="relative -mx-3 flex items-center gap-3 rounded-md px-3 py-2.5 first:-mt-3 last:-mb-3 hover:bg-elevated/50"
        >
          <UChip :color="notification.color || 'error'" :show="!!notification.unread" inset>
            <div
              class="flex size-10 items-center justify-center rounded-full"
              :class="{
                'bg-error/10': notification.color === 'error' || !notification.color,
                'bg-warning/10': notification.color === 'warning',
                'bg-info/10': notification.color === 'info'
              }"
            >
              <UIcon
                :name="notification.color === 'warning' ? 'i-lucide-badge-alert' : 'i-lucide-triangle-alert'"
                class="size-5"
                :class="{
                  'text-error': notification.color === 'error' || !notification.color,
                  'text-warning': notification.color === 'warning',
                  'text-info': notification.color === 'info'
                }"
              />
            </div>
          </UChip>

          <div class="min-w-0 flex-1 text-sm">
            <p class="flex items-center justify-between gap-2">
              <span class="truncate font-medium text-highlighted">{{ notification.title }}</span>
              <time
                :datetime="notification.date"
                class="shrink-0 text-xs text-muted"
                v-text="formatTimeAgo(new Date(notification.date))"
              />
            </p>
            <p class="truncate text-dimmed">
              {{ notification.body }}
            </p>
          </div>
        </NuxtLink>
      </template>
    </template>
  </USlideover>
</template>
