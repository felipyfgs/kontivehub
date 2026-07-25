<script setup lang="ts">
import type { TableColumn, TableRow } from '@nuxt/ui'
import type { CteChannelCursor, CteHealth, SyncRun } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'

const api = useApi()
const { sessionEpoch } = useDashboard()
const items = ref<SyncRun[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const selected = ref<SyncRun | null>(null)
const detailOpen = ref(false)
const toast = useToast()
const cteHealth = ref<CteHealth | null>(null)
const cteLoading = ref(false)
const cteError = ref<string | null>(null)

const columns: TableColumn<SyncRun>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'status', header: 'Resultado' },
  { accessorKey: 'trigger', header: 'Origem' },
  {
    accessorKey: 'pages_processed',
    header: 'Páginas',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  {
    accessorKey: 'documents_persisted',
    header: 'Documentos',
    meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
  },
  {
    accessorKey: 'started_at',
    header: 'Início',
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  { id: 'actions', header: '' }
]

type ChannelUiState = {
  key: string
  label: string
  color: 'success' | 'warning' | 'error' | 'info' | 'neutral'
  hint: string
}

/** Estados honestos a partir do cursor (sem inventar “ok” em quiet/circuito). */
function channelUiState(row: CteChannelCursor): ChannelUiState {
  const status = String(row.status || '').toUpperCase()
  const cstat = String(row.last_cstat || '')
  const quietFuture = row.next_sync_at
    ? new Date(row.next_sync_at).getTime() > Date.now()
    : false

  if (status === 'BLOCKED' || row.circuit_open || cstat === '656') {
    return {
      key: 'blocked',
      label: 'Bloqueado / circuito',
      color: 'error',
      hint: quietFuture && row.next_sync_at
        ? `Próxima tentativa após ${formatDateTime(row.next_sync_at)}. Sem retry antecipado.`
        : 'Circuito aberto ou cursor bloqueado — sem retry antecipado nem salto de NSU.'
    }
  }
  if (quietFuture && cstat === '137') {
    return {
      key: 'quiet',
      label: 'Quiet (fila vazia)',
      color: 'info',
      hint: row.next_sync_at
        ? `cStat 137: sem documentos novos até ${formatDateTime(row.next_sync_at)}.`
        : 'cStat 137: quiet mínimo — não é falha nem backfill concluído.'
    }
  }
  if (quietFuture) {
    return {
      key: 'waiting',
      label: 'Aguardando quiet',
      color: 'warning',
      hint: row.next_sync_at
        ? `Próxima janela em ${formatDateTime(row.next_sync_at)}.`
        : 'Aguardando intervalo mínimo entre consultas.'
    }
  }
  if (status === 'RUNNING') {
    return {
      key: 'running',
      label: 'Em sincronização',
      color: 'info',
      hint: 'Consulta em andamento neste canal.'
    }
  }
  if (status === 'ERROR') {
    return {
      key: 'error',
      label: 'Erro recuperável',
      color: 'warning',
      hint: row.retry_allowed === false
        ? 'Retry ainda não permitido neste cursor.'
        : 'Última execução com erro — canal ainda elegível a nova tentativa.'
    }
  }
  if (status === 'IDLE' || status === 'WAITING' || status === 'ACTIVE') {
    return {
      key: 'idle',
      label: status === 'ACTIVE' ? 'Ativo / ocioso' : 'Ocioso',
      color: 'success',
      hint: `Último NSU ${row.last_nsu ?? '—'} · max visto ${row.max_nsu_seen ?? '—'}.`
    }
  }
  return {
    key: 'unknown',
    label: status || 'Desconhecido',
    color: 'neutral',
    hint: 'Estado reportado pelo backend sem classificação adicional.'
  }
}

function summarizeChannel(rows: CteChannelCursor[] | undefined) {
  const list = rows || []
  const states = list.map(channelUiState)
  const blocked = states.filter(s => s.key === 'blocked').length
  const quiet = states.filter(s => s.key === 'quiet' || s.key === 'waiting').length
  const idle = states.filter(s => s.key === 'idle').length
  const running = states.filter(s => s.key === 'running').length
  const errors = states.filter(s => s.key === 'error').length
  const primary = states.find(s => s.key === 'blocked')
    || states.find(s => s.key === 'error')
    || states.find(s => s.key === 'quiet' || s.key === 'waiting')
    || states.find(s => s.key === 'running')
    || states[0]
    || null
  return { list, blocked, quiet, idle, running, errors, primary }
}

const clientChannelSummary = computed(() =>
  summarizeChannel(cteHealth.value?.channels?.CTE_DISTDFE)
)
const officeChannelSummary = computed(() =>
  summarizeChannel(cteHealth.value?.channels?.CTE_AUTXML_DISTDFE)
)

async function load(reset = false) {
  const epoch = sessionEpoch.value
  if (reset) {
    cursor.value = null
  }
  loading.value = true
  try {
    const response = await api.sync.history({
      limit: 50,
      ...(cursor.value ? { cursor: cursor.value } : {})
    })
    if (epoch !== sessionEpoch.value) return
    items.value = reset ? response.data : [...items.value, ...response.data]
    cursor.value = response.meta.next_cursor
    loadError.value = null
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Erro ao carregar sincronizações.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

async function loadCteHealth() {
  cteLoading.value = true
  try {
    cteHealth.value = (await api.cte.health()).data
    cteError.value = null
  } catch (caught) {
    cteError.value = apiErrorMessage(caught, 'Falha ao carregar a saúde CT-e.')
  } finally {
    cteLoading.value = false
  }
}

async function refreshAll() {
  await Promise.all([load(true), loadCteHealth()])
}

function openDetail(run: SyncRun) {
  selected.value = run
  detailOpen.value = true
}

function selectRow(_event: Event, row: TableRow<SyncRun>) {
  openDetail(row.original)
}

/** Bloqueio é sinalizado no status/mensagem — nunca oferecer salto de NSU. */
function isBlocked(run: SyncRun) {
  const message = (run.error_message || '').toLowerCase()
  return run.status === 'FAILED' && (message.includes('bloque') || message.includes('block'))
}

watch(sessionEpoch, () => {
  items.value = []
  cursor.value = null
  selected.value = null
  detailOpen.value = false
  cteHealth.value = null
  loadError.value = null
  void refreshAll()
})

onMounted(refreshAll)
</script>

<template>
  <ShellPagePanel id="syncs">
    <template #header>
      <ShellPageNavbar title="Sincronizações">
        <template #right>
          <ShellNavbarRefresh
            :loading="loading || cteLoading"
            aria-label="Atualizar histórico de sincronizações"
            @click="refreshAll"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <OfficeAutXmlSyncCard class="mb-4" />

      <div
        class="grid gap-4 lg:grid-cols-2"
        data-testid="cte-channel-health"
      >
        <UPageCard
          title="CT-e (clientes)"
          variant="subtle"
          icon="i-lucide-building-2"
        >
          <div
            v-if="cteLoading && !cteHealth"
            class="space-y-2"
            role="status"
            aria-live="polite"
            aria-busy="true"
          >
            <USkeleton class="h-4 w-1/2" />
            <USkeleton class="h-4 w-2/3" />
            <span class="sr-only">Carregando saúde CT-e dos clientes…</span>
          </div>
          <template v-else>
            <div class="flex flex-wrap items-center gap-2">
              <UBadge color="neutral" variant="subtle">
                {{ clientChannelSummary.list.length || cteHealth?.summary.client_streams || 0 }} stream(s)
              </UBadge>
              <UBadge
                v-if="clientChannelSummary.primary"
                :color="clientChannelSummary.primary.color"
                variant="subtle"
                data-testid="cte-client-channel-state"
              >
                {{ clientChannelSummary.primary.label }}
              </UBadge>
              <UBadge
                v-if="clientChannelSummary.blocked"
                color="error"
                variant="subtle"
              >
                {{ clientChannelSummary.blocked }} bloqueado(s)
              </UBadge>
              <UBadge
                v-if="clientChannelSummary.quiet"
                color="info"
                variant="subtle"
              >
                {{ clientChannelSummary.quiet }} em quiet
              </UBadge>
              <UBadge
                v-if="clientChannelSummary.idle && !clientChannelSummary.blocked"
                color="success"
                variant="subtle"
              >
                {{ clientChannelSummary.idle }} ocioso(s)
              </UBadge>
            </div>
            <p
              v-if="clientChannelSummary.primary"
              class="mt-2 text-sm text-muted"
            >
              {{ clientChannelSummary.primary.hint }}
            </p>
            <p
              v-else-if="!cteError"
              class="mt-2 text-sm text-muted"
            >
              Nenhum cursor CTE_DISTDFE ainda para este escritório.
            </p>
            <ul
              v-if="clientChannelSummary.list.length"
              class="mt-3 max-h-40 space-y-2 overflow-y-auto text-xs text-muted"
            >
              <li
                v-for="row in clientChannelSummary.list.slice(0, 8)"
                :key="row.id"
                class="flex flex-wrap items-center gap-2"
              >
                <UBadge
                  :color="channelUiState(row).color"
                  variant="subtle"
                  size="sm"
                >
                  {{ channelUiState(row).label }}
                </UBadge>
                <span class="truncate">
                  {{ row.client_name || `Est. ${row.establishment_id || '—'}` }}
                  · NSU {{ row.last_nsu }}
                </span>
              </li>
            </ul>
          </template>
        </UPageCard>

        <UPageCard
          title="CT-e autXML do escritório"
          description="CT-e com CNPJ do escritório em autXML."
          variant="subtle"
          icon="i-lucide-truck"
        >
          <div
            v-if="cteLoading && !cteHealth"
            class="space-y-2"
            role="status"
            aria-live="polite"
            aria-busy="true"
          >
            <USkeleton class="h-4 w-1/2" />
            <USkeleton class="h-4 w-2/3" />
            <span class="sr-only">Carregando saúde CT-e autXML…</span>
          </div>
          <template v-else>
            <div class="flex flex-wrap items-center gap-2">
              <UBadge color="neutral" variant="subtle">
                {{ officeChannelSummary.list.length || cteHealth?.summary.office_streams || 0 }} stream(s)
              </UBadge>
              <UBadge
                v-if="officeChannelSummary.primary"
                :color="officeChannelSummary.primary.color"
                variant="subtle"
                data-testid="cte-office-channel-state"
              >
                {{ officeChannelSummary.primary.label }}
              </UBadge>
              <UBadge
                v-if="officeChannelSummary.blocked"
                color="error"
                variant="subtle"
              >
                {{ officeChannelSummary.blocked }} bloqueado(s)
              </UBadge>
              <UBadge
                v-if="officeChannelSummary.quiet"
                color="info"
                variant="subtle"
              >
                {{ officeChannelSummary.quiet }} em quiet
              </UBadge>
            </div>
            <p
              v-if="officeChannelSummary.primary"
              class="mt-2 text-sm text-muted"
            >
              {{ officeChannelSummary.primary.hint }}
            </p>
            <p
              v-else-if="!cteError"
              class="mt-2 text-sm text-muted"
            >
              Stream central não inicializado. Configure autXML no catálogo CT-e.
            </p>
            <div class="mt-3">
              <UButton
                to="/docs/catalog?kind=CTE"
                color="neutral"
                variant="outline"
                size="sm"
                label="Abrir documentos CT-e"
              />
            </div>
          </template>
        </UPageCard>
      </div>

      <UAlert
        v-if="cteError"
        color="warning"
        variant="subtle"
        icon="i-lucide-wifi-off"
        :title="cteError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: loadCteHealth }]"
      />

      <UAlert
        v-if="loadError"
        :color="items.length ? 'warning' : 'error'"
        icon="i-lucide-wifi-off"
        :title="loadError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: () => load(true) }]"
      />

      <ShellDataTable
        test-id="data-table"
        ui-preset="monitoring-compact"
        primary-column-id="id"
        status-column-id="status"
        :summary-column-ids="['trigger', 'pages_processed', 'documents_persisted', 'started_at']"
        :columns="columns"
        :data="items"
        :loading="loading"
        :page="1"
        :total="items.length"
        :items-per-page="items.length || 1"
        :show-footer="false"
        @select="selectRow"
      >
        <template #status-cell="{ row }">
          <div class="flex w-full min-w-0 flex-wrap items-center gap-2">
            <ShellStatusBadge
              fill
              :status="row.original.status"
              class="min-w-0 flex-1"
            />
            <UBadge
              v-if="isBlocked(row.original)"
              color="error"
              variant="subtle"
              icon="i-lucide-ban"
              class="shrink-0"
            >
              Cursor bloqueado
            </UBadge>
          </div>
        </template>
        <template #trigger-cell="{ row }">
          {{ statusLabel(row.original.trigger) }}
        </template>
        <template #started_at-cell="{ row }">
          {{ formatDateTime(row.original.started_at || row.original.created_at) }}
        </template>
        <template #actions-cell="{ row }">
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-eye"
            square
            aria-label="Ver detalhes da execução"
            @click.stop="openDetail(row.original)"
          />
        </template>
        <template #empty>
          <UEmpty
            v-if="!loadError"
            icon="i-lucide-history"
            title="Nenhuma execução registrada"
          />
        </template>
      </ShellDataTable>

      <div v-if="cursor" class="flex justify-center border-t border-default pt-4">
        <UButton
          :loading="loading"
          color="neutral"
          variant="subtle"
          label="Carregar mais"
          @click="load(false)"
        />
      </div>

      <USlideover
        v-model:open="detailOpen"
        :title="selected ? `Execução #${selected.id}` : 'Execução'"
      >
        <template #body>
          <div v-if="selected" class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <ShellStatusBadge :status="selected.status" />
              <UBadge color="neutral" variant="subtle">
                {{ statusLabel(selected.trigger) }}
              </UBadge>
            </div>

            <UAlert
              v-if="isBlocked(selected)"
              color="error"
              icon="i-lucide-ban"
              title="Cursor bloqueado após falhas consecutivas de decodificação"
            />

            <dl class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <dt class="text-muted">
                  Origem
                </dt>
                <dd class="text-highlighted">
                  {{ statusLabel(selected.trigger) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Resultado
                </dt>
                <dd class="text-highlighted">
                  {{ statusLabel(selected.status) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Início
                </dt>
                <dd class="text-highlighted">
                  {{ formatDateTime(selected.started_at) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Fim
                </dt>
                <dd class="text-highlighted">
                  {{ formatDateTime(selected.finished_at) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  NSU inicial
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ selected.from_nsu }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  NSU final
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ selected.to_nsu }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Páginas
                </dt>
                <dd class="text-highlighted">
                  {{ selected.pages_processed }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Documentos
                </dt>
                <dd class="text-highlighted">
                  {{ selected.documents_persisted }}
                </dd>
              </div>
            </dl>
            <UAlert
              v-if="selected.error_message"
              color="error"
              icon="i-lucide-circle-x"
              :title="selected.error_message"
            />
            <p class="text-xs text-muted">
              Respostas remotas, XML, PFX, senha e material criptográfico não são exibidos no histórico.
            </p>
          </div>
        </template>
      </USlideover>
    </template>
  </ShellPagePanel>
</template>
