<script setup lang="ts">
/**
 * Home — cockpit operacional do escritório ativo.
 * Fonte: GET /api/v1/operations/summary + /operations/inbox + work/kpis.
 * Não duplica a profundidade fiscal de /monitoring.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { InboxItem, OperationsSummary } from '~/types/api'
import { quickActions } from '~/utils/navigation'

const api = useApi()
const toast = useToast()
const {
  isNotificationsSlideoverOpen,
  me,
  openClientCreate,
  openExportCreate,
  sessionEpoch
} = useDashboard()

const summary = ref<OperationsSummary | null>(null)
const lastGoodSummary = ref<OperationsSummary | null>(null)
const inboxItems = ref<InboxItem[]>([])
const lastValidAt = ref<string | null>(null)
const loading = ref(false)
const inboxLoading = ref(false)
const refreshError = ref<string | null>(null)

const actionItems = computed<DropdownMenuItem[][]>(() => [[
  ...quickActions(me.value).map(action => ({
    label: action.label,
    icon: action.icon,
    to: action.to,
    onSelect: action.id === 'new-client'
      ? openClientCreate
      : action.id === 'new-export'
        ? openExportCreate
        : undefined
  }))
]])

const alertCount = computed(() => {
  if (!summary.value) return 0
  if (typeof summary.value.inbox_total === 'number') {
    return summary.value.inbox_total
  }
  return (summary.value.sync_blocked || 0) + (summary.value.sync_failures_24h || 0)
})

async function load() {
  const epoch = sessionEpoch.value
  const had = !!lastGoodSummary.value
  loading.value = !had
  inboxLoading.value = true
  try {
    const [summaryResult, inboxResult] = await Promise.allSettled([
      api.operations.summary(),
      api.operations.inbox({ limit: 5 })
    ])

    if (epoch !== sessionEpoch.value) return

    if (summaryResult.status === 'fulfilled') {
      summary.value = summaryResult.value.data
      lastGoodSummary.value = summaryResult.value.data
      lastValidAt.value = summaryResult.value.data.generated_at
      refreshError.value = null
    } else {
      const message = apiErrorMessage(summaryResult.reason, 'Não foi possível carregar o resumo operacional.')
      refreshError.value = message
      if (lastGoodSummary.value) {
        summary.value = lastGoodSummary.value
      } else {
        toast.add({ title: message, color: 'error' })
      }
    }

    if (inboxResult.status === 'fulfilled') {
      inboxItems.value = inboxResult.value.data
    } else if (summaryResult.status === 'fulfilled') {
      inboxItems.value = []
    }
  } finally {
    if (epoch === sessionEpoch.value) {
      loading.value = false
      inboxLoading.value = false
    }
  }
}

onMounted(load)

watch(sessionEpoch, () => {
  summary.value = null
  lastGoodSummary.value = null
  inboxItems.value = []
  lastValidAt.value = null
  refreshError.value = null
  void load()
})
</script>

<template>
  <ShellPagePanel id="home">
    <template #header>
      <ShellPageNavbar
        title="Início"
        test-id="page-navbar"
      >
        <template #right>
          <AssistantTriggerButton compact />
          <UTooltip
            text="Alertas"
            :shortcuts="['N']"
          >
            <UButton
              color="neutral"
              variant="ghost"
              square
              aria-label="Abrir alertas operacionais"
              @click="() => { isNotificationsSlideoverOpen = true }"
            >
              <UChip
                color="primary"
                :show="alertCount > 0"
                inset
              >
                <UIcon
                  name="i-lucide-bell"
                  class="size-5 shrink-0"
                />
              </UChip>
            </UButton>
          </UTooltip>
          <UDropdownMenu
            v-if="actionItems[0]?.length"
            :items="actionItems"
          >
            <UButton
              icon="i-lucide-plus"
              size="md"
              class="rounded-full"
              aria-label="Abrir ações rápidas"
            />
          </UDropdownMenu>
        </template>
      </ShellPageNavbar>
    </template>

    <template #toolbar>
      <UDashboardToolbar data-testid="page-toolbar">
        <template #left>
          <ShellNavbarRefresh
            :loading="loading"
            class="-ms-1"
            aria-label="Atualizar"
            @click="load"
          />
        </template>
        <template #right>
          <span
            v-if="lastValidAt"
            class="hidden text-xs text-muted sm:inline"
          >
            Atualizado {{ formatDateTime(lastValidAt) }}
          </span>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6">
        <HomeBlocksBanner
          :summary="summary"
          :loading="loading"
        />

        <section
          data-testid="home-operations-section"
          aria-labelledby="home-ops-heading"
        >
          <h2
            id="home-ops-heading"
            class="mb-2 text-xs font-normal uppercase text-muted"
          >
            Operações
          </h2>
          <HomeStats
            :summary="summary"
            :loading="loading"
          />
        </section>

        <HomeWorkKpisBlock />

        <HomeFiscalSlice
          :summary="summary"
          :loading="loading"
        />

        <HomeSerproTenant
          :summary="summary"
          :loading="loading"
        />

        <HomeCommunication
          :summary="summary"
          :loading="loading"
        />

        <HomeOperations
          :summary="summary"
          :loading="loading"
          :inbox-items="inboxItems"
          :inbox-loading="inboxLoading"
          :error="refreshError"
          @retry="load"
        />

        <HomeTotals
          :summary="summary"
          :loading="loading"
        />
      </div>
    </template>
  </ShellPagePanel>
</template>
