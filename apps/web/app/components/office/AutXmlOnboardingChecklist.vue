<script setup lang="ts">
/**
 * Checklist autXML por estabelecimento.
 * Arquétipo: settings/members.vue (card + lista) — adaptado a enrollment.
 * Cobertura: NF-e 55 apenas; NFC-e 65 → import XML/ZIP.
 */
import type { TableColumn } from '@nuxt/ui'
import type {
  OfficeAutXmlCoverage,
  OfficeAutXmlEnrollment,
  OfficeAutXmlStream
} from '~/types/api'
import { laravelPageBatch, usePagedTable } from '~/composables/usePagedTable'
import { DASHBOARD_TABLE_UI, LIST_TABLE_CLASS } from '~/utils/table-ui'

const api = useApi()
const toast = useToast()
const { canManageClients, sessionEpoch } = useDashboard()

const acting = ref<number | null>(null)
const officeCnpj = ref<string | null>(null)
const stream = ref<OfficeAutXmlStream | null>(null)
const coverage = ref<OfficeAutXmlCoverage | null>(null)

const table = useTemplateRef('table')
const feed = usePagedTable<OfficeAutXmlEnrollment>({
  getKey: row => row.establishment_id,
  pageSize: 20,
  load: async ({ page, pageSize, signal }) => {
    const epoch = sessionEpoch.value
    const response = await api.officeAutXml.overview({ page, per_page: pageSize }, { signal })
    if (epoch === sessionEpoch.value) {
      officeCnpj.value = response.data.office_cnpj
      stream.value = response.data.stream
      coverage.value = response.data.coverage
    }

    return laravelPageBatch({ data: response.data.enrollments, meta: response.meta })
  }
})

const feedTotal = feed.total
const feedPage = feed.page
const feedPageSize = feed.pageSize
const rows = feed.rows
const loading = feed.pendingInitial
const error = computed(() => feed.error.value
  ? apiErrorMessage(feed.error.value, 'Não foi possível carregar o onboarding autXML.')
  : null)

const columns: TableColumn<OfficeAutXmlEnrollment>[] = [
  { accessorKey: 'establishment_cnpj', header: 'Estabelecimento' },
  { accessorKey: 'status', header: 'Estado' },
  {
    accessorKey: 'first_seen_at',
    header: '1ª evidência',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  {
    accessorKey: 'last_seen_at',
    header: 'Última',
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  { id: 'actions', header: '' }
]

const streamReady = computed(() => !!stream.value?.stream_ready)
const streamHint = computed(() => {
  const reason = stream.value?.stream_reason
  if (!reason) return null
  if (reason === 'CURSOR_MISSING' || reason === 'NOT_ACTIVATED') {
    return 'Ative o stream autXML (primeira distNSU) antes de confirmar enrollments. O canal não é retroativo.'
  }
  if (reason === 'QUIET_PENDING') {
    return `Aguarde o quiet mínimo de ${stream.value?.quiet_hours ?? 1}h após a primeira consulta (até ${stream.value?.ready_at ? formatDateTime(stream.value.ready_at) : '—'}).`
  }
  return 'Stream ainda não apto a confirmações operacionais.'
})

async function load() {
  await feed.resetAndLoad()
}

async function retry() {
  await feed.retry()
}

function updateEnrollment(updated: OfficeAutXmlEnrollment) {
  rows.value = rows.value.map(row =>
    row.establishment_id === updated.establishment_id ? updated : row
  )
}

async function copyOfficeCnpj() {
  const c = officeCnpj.value
  if (!c) return
  try {
    await navigator.clipboard.writeText(c)
    toast.add({ title: 'CNPJ do escritório copiado para o ERP', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar o CNPJ', color: 'error' })
  }
}

function statusColor(status: string): 'success' | 'warning' | 'neutral' | 'error' {
  if (status === 'CONFIRMED') return 'success'
  if (status === 'PENDING') return 'warning'
  if (status === 'INACTIVE') return 'neutral'
  return 'neutral'
}

function statusLabel(status: string) {
  const map: Record<string, string> = {
    NONE: 'Não iniciado',
    PENDING: 'Pendente (ERP)',
    CONFIRMED: 'Confirmado',
    INACTIVE: 'Inativo'
  }
  return map[status] || status
}

async function enroll(row: OfficeAutXmlEnrollment) {
  if (!canManageClients.value || acting.value) return
  const epoch = sessionEpoch.value
  const estId = Number(row.establishment_id)
  acting.value = estId
  try {
    const response = await api.officeAutXml.enroll(estId)
    if (epoch !== sessionEpoch.value) return
    updateEnrollment(response.data)
    toast.add({ title: 'Enrollment PENDING criado — inclua o CNPJ no ERP do emitente', color: 'success' })
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    toast.add({ title: apiErrorMessage(caught, 'Falha ao enrollar.'), color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) acting.value = null
  }
}

async function confirm(row: OfficeAutXmlEnrollment) {
  if (!canManageClients.value || acting.value || !streamReady.value) return
  const epoch = sessionEpoch.value
  const id = Number(row.id)
  if (!id) return
  acting.value = id
  try {
    const response = await api.officeAutXml.confirm(id)
    if (epoch !== sessionEpoch.value) return
    updateEnrollment(response.data)
    toast.add({ title: 'Enrollment confirmado operacionalmente', color: 'success' })
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    toast.add({ title: apiErrorMessage(caught, 'Confirmação bloqueada.'), color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) acting.value = null
  }
}

async function inactivate(row: OfficeAutXmlEnrollment) {
  if (!canManageClients.value || acting.value) return
  const epoch = sessionEpoch.value
  const id = Number(row.id)
  if (!id) return
  acting.value = id
  try {
    const response = await api.officeAutXml.inactivate(id)
    if (epoch !== sessionEpoch.value) return
    updateEnrollment(response.data)
    toast.add({ title: 'Enrollment inativado', color: 'neutral' })
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    toast.add({ title: apiErrorMessage(caught, 'Falha ao inativar.'), color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) acting.value = null
  }
}

watch(sessionEpoch, () => {
  acting.value = null
  officeCnpj.value = null
  stream.value = null
  coverage.value = null
  feed.reset()
  void feed.resetAndLoad()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-4" data-testid="autxml-onboarding">
    <UAlert
      color="warning"
      icon="i-lucide-info"
      title="autXML cobre NF-e 55 e não é retroativo"
    />

    <UPageCard variant="subtle">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <div>
          <p class="text-muted">
            CNPJ do escritório (copiar para o ERP)
          </p>
          <code class="text-highlighted">{{ officeCnpj || '— cadastre a identidade fiscal —' }}</code>
        </div>
        <UButton
          size="sm"
          color="neutral"
          variant="subtle"
          icon="i-lucide-copy"
          label="Copiar CNPJ"
          :disabled="!officeCnpj"
          aria-label="Copiar CNPJ completo do escritório para colar no ERP"
          @click="copyOfficeCnpj"
        />
        <UBadge color="primary" variant="subtle">
          {{ coverage?.label || 'NF-e modelo 55' }}
        </UBadge>
        <UBadge
          :color="streamReady ? 'success' : 'warning'"
          variant="subtle"
        >
          Stream: {{ streamReady ? 'apto a confirmar' : 'aguardando ativação/quiet' }}
        </UBadge>
      </div>
    </UPageCard>

    <UAlert
      v-if="streamHint"
      color="warning"
      icon="i-lucide-clock"
      :title="streamHint"
    />

    <UAlert
      v-if="error"
      color="error"
      icon="i-lucide-wifi-off"
      :title="error"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: retry }]"
    />

    <div
      v-if="loading"
      class="space-y-2"
      role="status"
      aria-live="polite"
    >
      <USkeleton class="h-10 w-full" />
      <USkeleton class="h-10 w-full" />
    </div>

    <UTable
      v-else-if="rows.length"
      ref="table"
      data-testid="autxml-enrollment-table"
      :data="rows"
      :columns="columns"
      :class="LIST_TABLE_CLASS"
      :ui="DASHBOARD_TABLE_UI"
    >
      <template #establishment_cnpj-cell="{ row }">
        <div class="min-w-0">
          <p class="truncate font-medium text-highlighted">
            {{ row.original.client_name || row.original.establishment_name || '—' }}
          </p>
          <p class="truncate font-mono text-xs text-muted">
            {{ row.original.establishment_cnpj }}
          </p>
        </div>
      </template>
      <template #status-cell="{ row }">
        <div class="flex flex-wrap items-center gap-1">
          <UBadge :color="statusColor(String(row.original.status))" variant="subtle">
            {{ statusLabel(String(row.original.status)) }}
          </UBadge>
          <UBadge
            v-if="row.original.observed"
            color="success"
            variant="outline"
            size="sm"
          >
            XML observado
          </UBadge>
        </div>
      </template>
      <template #first_seen_at-cell="{ row }">
        <span class="text-sm text-muted">
          {{ row.original.first_seen_at ? formatDateTime(String(row.original.first_seen_at)) : '—' }}
        </span>
      </template>
      <template #last_seen_at-cell="{ row }">
        <span class="text-sm text-muted">
          {{ row.original.last_seen_at ? formatDateTime(String(row.original.last_seen_at)) : '—' }}
        </span>
      </template>
      <template #actions-cell="{ row }">
        <div class="flex flex-wrap justify-end gap-1">
          <UButton
            v-if="canManageClients && (row.original.status === 'NONE' || row.original.status === 'INACTIVE')"
            size="xs"
            color="primary"
            variant="subtle"
            label="Iniciar"
            :loading="acting === Number(row.original.establishment_id)"
            :aria-label="`Iniciar onboarding autXML para ${row.original.establishment_cnpj}`"
            @click="enroll(row.original)"
          />
          <UButton
            v-if="canManageClients && row.original.status === 'PENDING' && row.original.id"
            size="xs"
            color="success"
            variant="subtle"
            label="Confirmar"
            :disabled="!streamReady"
            :loading="acting === Number(row.original.id)"
            :aria-label="`Confirmar enrollment de ${row.original.establishment_cnpj}`"
            @click="confirm(row.original)"
          />
          <UButton
            v-if="canManageClients && (row.original.status === 'PENDING' || row.original.status === 'CONFIRMED') && row.original.id"
            size="xs"
            color="neutral"
            variant="ghost"
            label="Inativar"
            :loading="acting === Number(row.original.id)"
            :aria-label="`Inativar enrollment de ${row.original.establishment_cnpj}`"
            @click="inactivate(row.original)"
          />
        </div>
      </template>
    </UTable>

    <UEmpty
      v-if="!loading && !error && !rows.length"
      icon="i-lucide-building-2"
      title="Nenhum estabelecimento ativo"
      description="Cadastre clientes/estabelecimentos para montar o checklist autXML."
    />

    <ShellTableFooter
      :total="feedTotal"
      :page="feedPage"
      :items-per-page="feedPageSize"
      per-page-aria-label="Estabelecimentos por página"
      @update:page="(p) => feed.setPage(p)"
      @update:items-per-page="(n) => feed.setPageSize(n)"
    />
  </div>
</template>
