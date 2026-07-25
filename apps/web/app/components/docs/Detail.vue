<script setup lang="ts">
/**
 * Corpo do detalhe NFS-e — layout de documento fiscal (não lista técnica).
 *
 * Ordem da nota real:
 *  1. Cabeçalho (nº, competência, emissão, situação, papel)
 *  2. Valor do serviço
 *  3. Prestador | Tomador (cards lado a lado)
 *  4. Intermediário (se houver)
 *  5. Locais do serviço
 *  6. Identificação (chave copiável)
 *  7. Metadados do XML (secundário)
 *  8. Eventos (se houver)
 *
 * Chrome do modal: DocsDetailModal (title / footer).
 */
import type { TableColumn } from '@nuxt/ui'
import type { NfseEvent, NfseNote, NoteDetail } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { documentKindLabel } from '~/utils/document-kinds'
import { DASHBOARD_TABLE_UI } from '~/utils/table-ui'

const props = defineProps<{
  accessKey: string
  preview?: NfseNote | null
  embedded?: boolean
}>()

const emit = defineEmits<{
  loaded: [note: NfseNote]
}>()

const api = useApi()
const toast = useToast()
const { canTriggerSync } = useDashboard()
const detail = ref<NoteDetail | null>(null)
const loading = ref(false)
const refreshing = ref(false)
const notFound = ref(false)
const actionBusy = ref(false)
const showOptionalManifest = ref(false)
const confirmOpen = ref(false)
const confirmType = ref<'CIENCIA' | 'CONFIRMACAO' | 'DESCONHECIMENTO' | 'NAO_REALIZADA'>('CIENCIA')
const justification = ref('')
let loadGeneration = 0

const isNfe = computed(() => {
  const k = (note.value?.kind || '').toUpperCase()
  return k === 'NFE' || k === 'NFCE'
})

const needsFullXml = computed(() => {
  if (!isNfe.value) return false
  const n = note.value
  if (!n) return false
  if (n.has_full_xml === true || n.xml_completeness === 'FULL') return false
  if (n.is_summary === true || n.xml_completeness === 'SUMMARY_ONLY') return true
  return (n.manifestation_status || '').toUpperCase().includes('PENDING')
})

const confirmTitle = computed(() => {
  switch (confirmType.value) {
    case 'CIENCIA':
      return 'Obter XML completo (ciência)'
    case 'CONFIRMACAO':
      return 'Confirmar operação'
    case 'DESCONHECIMENTO':
      return 'Desconhecer operação'
    case 'NAO_REALIZADA':
      return 'Operação não realizada'
    default:
      return 'Manifestação'
  }
})

const confirmDescription = computed(() => {
  if (confirmType.value === 'CIENCIA') {
    return 'Registra ciência da operação na SEFAZ apenas para desbloquear o XML completo. Não confirma a compra nem a operação fiscal.'
  }
  if (confirmType.value === 'NAO_REALIZADA') {
    return 'Evento conclusivo com justificativa (15–255 caracteres). Impacto fiscal é do contribuinte.'
  }
  return 'Evento conclusivo de Manifestação do Destinatário. O painel envia à SEFAZ apenas se você confirmar; não é obrigatório para usar o catálogo.'
})

const note = computed(() => {
  const key = props.accessKey
  const fromDetail = detail.value?.note
  if (fromDetail?.access_key === key) return fromDetail
  if (props.preview?.access_key === key) return props.preview
  return null
})

const documentMeta = computed(() => {
  if (detail.value?.note?.access_key === props.accessKey) {
    return detail.value.document || detail.value.note.document || null
  }
  return note.value?.document || null
})

const events = computed(() => {
  if (detail.value?.note?.access_key === props.accessKey) {
    return detail.value.events || []
  }
  return []
})

const hasIntermediary = computed(() =>
  !!(note.value?.intermediary_cnpj || note.value?.intermediary_name)
)

const parseOk = computed(() => {
  const s = documentMeta.value?.parse_status
  return s === 'OK' || s === 'PARSED'
})

/** Linha oficial: cStat + descrição (API ou mapa local). */
function noteOfficialLine(n: NfseNote): string | null {
  const code = n.official_status_code
  const desc = noteOfficialSituation(n.status, code, n.official_status_label)
  if (code && desc) return `cStat ${code} · ${desc}`
  if (code) return `cStat ${code}`
  if (desc) return desc
  return null
}

/**
 * Nuance operacional no detalhe (substituta / substituída), sem duplicar o cStat.
 * Só aparece quando agrega informação além do chip Autorizada/Cancelada.
 */
function noteStatusNuance(n: NfseNote): string | null {
  const s = (n.status || '').toUpperCase()
  if (s === 'SUBSTITUTE') {
    // Evita repetir se o cStat 101 já diz substituição
    if (n.official_status_code === '101') return null
    return 'Nota de substituição (válida)'
  }
  if (s === 'SUPERSEDED' || s === 'REPLACED') {
    return 'Nota substituída — não utilizar na escrituração'
  }
  if (s === 'JUDICIAL' && n.official_status_code !== '102') {
    return 'Decisão judicial / administrativa'
  }
  if (s === 'CANCELLED') {
    return events.value.length
      ? 'Cancelamento registrado em evento'
      : null
  }
  return null
}

const eventColumns: TableColumn<NfseEvent>[] = [
  {
    accessorKey: 'event_type',
    header: 'Tipo',
    meta: { class: { th: 'w-36', td: 'w-36' } }
  },
  {
    accessorKey: 'event_at',
    header: 'Quando',
    meta: { class: { th: 'w-40', td: 'w-40' } }
  },
  {
    accessorKey: 'status',
    header: 'Situação'
  }
]

const tableUi = {
  ...DASHBOARD_TABLE_UI,
  th: `${DASHBOARD_TABLE_UI.th} px-2`,
  td: `${DASHBOARD_TABLE_UI.td} px-2`
}

async function copyText(label: string, value?: string | null) {
  const text = (value || '').trim()
  if (!text || text === '—') return
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ title: `${label} copiado`, color: 'success' })
  } catch {
    toast.add({ title: `Não foi possível copiar ${label.toLowerCase()}`, color: 'error' })
  }
}

async function copyCnpj(value?: string | null) {
  const clean = normalizeCnpj(value)
  if (!clean) return
  await copyText('CNPJ', clean)
}

async function load() {
  const key = props.accessKey
  const generation = ++loadGeneration
  notFound.value = false

  const hasMatchingDetail = detail.value?.note?.access_key === key
  const hasMatchingPreview = props.preview?.access_key === key

  if (!hasMatchingDetail) detail.value = null

  if (!hasMatchingDetail && !hasMatchingPreview) loading.value = true
  else refreshing.value = true

  try {
    const data = (await api.documents.get(key)).data
    if (generation !== loadGeneration) return
    detail.value = data
    if (data.note) emit('loaded', data.note)
  } catch (caught) {
    if (generation !== loadGeneration) return
    detail.value = null
    notFound.value = true
    toast.add({
      title: apiErrorMessage(caught, 'Nota não encontrada.'),
      color: 'error'
    })
  } finally {
    if (generation === loadGeneration) {
      loading.value = false
      refreshing.value = false
    }
  }
}

watch(() => props.accessKey, load, { immediate: true })

function openConfirm(type: typeof confirmType.value) {
  confirmType.value = type
  justification.value = ''
  confirmOpen.value = true
}

async function submitManifest() {
  const key = props.accessKey
  if (!key || actionBusy.value) return

  if (confirmType.value === 'NAO_REALIZADA') {
    const len = justification.value.trim().length
    if (len < 15 || len > 255) {
      toast.add({
        title: 'Justificativa inválida',
        description: 'Informe entre 15 e 255 caracteres.',
        color: 'error'
      })
      return
    }
  }

  actionBusy.value = true
  try {
    const body: {
      type: typeof confirmType.value
      justification?: string
      purpose?: 'UNLOCK_XML' | 'FISCAL'
    } = {
      type: confirmType.value,
      purpose: confirmType.value === 'CIENCIA' ? 'UNLOCK_XML' : 'FISCAL'
    }
    if (confirmType.value === 'NAO_REALIZADA') {
      body.justification = justification.value.trim()
    }

    const res = confirmType.value === 'CIENCIA'
      ? await api.documents.unlockXml(key)
      : await api.documents.manifest(key, body)

    toast.add({
      title: res.data.status === 'accepted' || res.data.status === 'already_full'
        ? 'Solicitação registrada'
        : 'Não concluído',
      description: res.data.message,
      color: res.data.status === 'accepted' || res.data.status === 'already_full' ? 'success' : 'warning'
    })
    confirmOpen.value = false
    await load()
  } catch (caught) {
    toast.add({
      title: 'Falha na manifestação',
      description: apiErrorMessage(caught, 'Não foi possível registrar o evento.'),
      color: 'error'
    })
  } finally {
    actionBusy.value = false
  }
}
</script>

<template>
  <div
    data-testid="note-detail"
    class="space-y-4 p-4 sm:space-y-5 sm:p-6"
    :class="refreshing && note ? 'opacity-80 transition-opacity' : ''"
  >
    <div
      v-if="loading && !note"
      class="space-y-4"
      role="status"
      aria-label="Carregando nota"
    >
      <USkeleton class="h-28 w-full" />
      <div class="grid gap-3 sm:grid-cols-2">
        <USkeleton class="h-28 w-full" />
        <USkeleton class="h-28 w-full" />
      </div>
      <USkeleton class="h-24 w-full" />
    </div>

    <UEmpty
      v-else-if="notFound && !note"
      icon="i-lucide-file-x"
      title="Nota não encontrada"
      description="A chave não existe ou pertence a outro escritório."
    />

    <template v-else-if="note">
      <!-- 1. Cabeçalho da nota (como topo de DANFSe simplificado) -->
      <UPageCard
        variant="subtle"
        :ui="{ container: 'gap-y-3' }"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <p class="text-xs font-medium uppercase tracking-wide text-muted">
              {{ note.kind_label || documentKindLabel(note.kind || 'NFSE') }}
            </p>
            <p class="text-lg font-semibold text-highlighted sm:text-xl">
              <template v-if="note.number">
                Nº {{ note.number }}
              </template>
              <template v-else>
                Sem número parseado
              </template>
            </p>
            <p class="text-sm text-muted">
              <span v-if="note.competence">Competência {{ note.competence }}</span>
              <span v-if="note.competence && note.issued_at"> · </span>
              <span v-if="note.issued_at">Emitida em {{ formatDateTime(note.issued_at) }}</span>
              <span v-if="!note.competence && !note.issued_at">Datas não disponíveis</span>
            </p>
          </div>
          <div class="flex flex-col items-end gap-2">
            <div class="flex flex-wrap justify-end gap-1.5">
              <ShellStatusBadge
                :status="note.status"
                :label="note.status_label"
              />
              <UBadge
                v-if="note.direction"
                :color="note.direction === 'OUT' ? 'primary' : note.direction === 'IN' ? 'info' : 'neutral'"
                variant="subtle"
                size="sm"
              >
                {{ note.direction_label || note.direction }}
              </UBadge>
              <UBadge
                v-if="note.purpose === 'TECHNICAL'"
                color="warning"
                variant="subtle"
                size="sm"
              >
                Finalidade técnica
              </UBadge>
              <UBadge
                v-if="note.acquisition_source"
                color="neutral"
                variant="outline"
                size="sm"
              >
                {{ note.acquisition_source_label || note.acquisition_source }}
              </UBadge>
              <UBadge
                v-if="note.artifact_quality"
                :color="note.is_autxml_redacted ? 'warning' : 'success'"
                variant="subtle"
                size="sm"
              >
                {{ note.artifact_quality_label || note.artifact_quality }}
              </UBadge>
              <UBadge
                v-if="note.coverage_status"
                color="neutral"
                variant="outline"
                size="sm"
              >
                {{ note.coverage_status_label || note.coverage_status }}
              </UBadge>
              <UBadge color="info" variant="subtle" size="sm">
                {{ statusLabel(note.fiscal_role) }}
              </UBadge>
            </div>
            <p
              v-if="noteOfficialLine(note)"
              class="max-w-xs text-right text-xs text-muted"
            >
              {{ noteOfficialLine(note) }}
            </p>
            <p
              v-if="noteStatusNuance(note)"
              class="max-w-xs text-right text-xs text-dimmed"
            >
              {{ noteStatusNuance(note) }}
            </p>
            <p class="text-xs text-muted">
              Papel do escritório nesta nota
            </p>
          </div>
        </div>

        <!-- Valor em destaque (centro visual da nota) -->
        <div class="rounded-lg bg-default/80 px-4 py-3 ring ring-inset ring-default">
          <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
              <p class="text-xs uppercase tracking-wide text-muted">
                Valor do serviço
              </p>
              <p class="text-2xl font-semibold tabular-nums text-highlighted sm:text-3xl">
                {{ formatCurrency(note.service_amount) }}
              </p>
            </div>
            <UBadge
              v-if="documentMeta?.parse_status"
              :color="parseOk ? 'success' : 'warning'"
              variant="subtle"
              icon="i-lucide-file-code-2"
            >
              XML {{ statusLabel(documentMeta.parse_status) }}
            </UBadge>
          </div>
        </div>
      </UPageCard>

      <UAlert
        v-if="note.is_autxml_redacted"
        color="warning"
        variant="subtle"
        icon="i-lucide-file-warning"
        title="Visão oficial redigida via autXML"
      />

      <!-- 2. Prestador × Tomador (estrutura clássica da NFS-e) -->
      <div class="grid gap-3 sm:grid-cols-2">
        <UPageCard
          title="Prestador / Emitente"
          icon="i-lucide-building-2"
          variant="subtle"
          :ui="{
            title: 'text-sm font-semibold',
            container: 'gap-y-2'
          }"
        >
          <p
            v-if="note.issuer_name"
            class="font-medium text-highlighted"
          >
            {{ note.issuer_name }}
          </p>
          <p
            v-else
            class="text-sm text-muted"
          >
            Razão social não parseada
          </p>
          <button
            v-if="note.issuer_cnpj"
            type="button"
            class="group inline-flex max-w-full items-center gap-1.5 font-mono text-sm text-toned hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            @click="copyCnpj(note.issuer_cnpj)"
          >
            <span>{{ formatCnpj(note.issuer_cnpj) }}</span>
            <UIcon name="i-lucide-copy" class="size-3.5 shrink-0 opacity-50 group-hover:opacity-100" />
          </button>
          <p v-else class="font-mono text-sm text-dimmed">
            —
          </p>
        </UPageCard>

        <UPageCard
          title="Tomador"
          icon="i-lucide-user-round"
          variant="subtle"
          :ui="{
            title: 'text-sm font-semibold',
            container: 'gap-y-2'
          }"
        >
          <p
            v-if="note.taker_name"
            class="font-medium text-highlighted"
          >
            {{ note.taker_name }}
          </p>
          <p
            v-else
            class="text-sm text-muted"
          >
            Razão social não parseada
          </p>
          <button
            v-if="note.taker_cnpj"
            type="button"
            class="group inline-flex max-w-full items-center gap-1.5 font-mono text-sm text-toned hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            @click="copyCnpj(note.taker_cnpj)"
          >
            <span>{{ formatCnpj(note.taker_cnpj) }}</span>
            <UIcon name="i-lucide-copy" class="size-3.5 shrink-0 opacity-50 group-hover:opacity-100" />
          </button>
          <p v-else class="font-mono text-sm text-dimmed">
            —
          </p>
        </UPageCard>
      </div>

      <!-- 3. Intermediário (só se existir) -->
      <UPageCard
        v-if="hasIntermediary"
        title="Intermediário"
        icon="i-lucide-git-branch"
        variant="subtle"
        :ui="{ title: 'text-sm font-semibold', container: 'gap-y-2' }"
      >
        <p
          v-if="note.intermediary_name"
          class="font-medium text-highlighted"
        >
          {{ note.intermediary_name }}
        </p>
        <button
          v-if="note.intermediary_cnpj"
          type="button"
          class="group inline-flex max-w-full items-center gap-1.5 font-mono text-sm text-toned hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          @click="copyCnpj(note.intermediary_cnpj)"
        >
          <span>{{ formatCnpj(note.intermediary_cnpj) }}</span>
          <UIcon name="i-lucide-copy" class="size-3.5 shrink-0 opacity-50 group-hover:opacity-100" />
        </button>
      </UPageCard>

      <!-- 4. Locais do serviço -->
      <UPageCard
        title="Local do serviço"
        icon="i-lucide-map-pin"
        variant="subtle"
        :ui="{ title: 'text-sm font-semibold', container: 'gap-y-3' }"
      >
        <dl class="grid gap-3 sm:grid-cols-2 text-sm">
          <div>
            <dt class="text-xs text-muted">
              Local de emissão
            </dt>
            <dd class="mt-0.5 text-highlighted">
              {{ note.issue_location || '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Local da prestação
            </dt>
            <dd class="mt-0.5 text-highlighted">
              {{ note.service_location || '—' }}
            </dd>
          </div>
        </dl>
      </UPageCard>

      <!-- 5. Identificação (chave) — bloco mono, copiável -->
      <UPageCard
        title="Identificação"
        icon="i-lucide-fingerprint"
        variant="subtle"
        :ui="{ title: 'text-sm font-semibold', container: 'gap-y-2' }"
      >
        <p class="text-xs text-muted">
          Chave de acesso
        </p>
        <button
          type="button"
          class="group flex w-full items-start gap-2 rounded-md bg-default px-3 py-2 text-left ring ring-inset ring-default hover:ring-primary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          @click="copyText('Chave', note.access_key)"
        >
          <span class="min-w-0 flex-1 break-all font-mono text-xs leading-relaxed text-highlighted">
            {{ note.access_key }}
          </span>
          <UIcon
            name="i-lucide-copy"
            class="mt-0.5 size-4 shrink-0 text-muted group-hover:text-primary"
          />
        </button>
      </UPageCard>

      <!-- 6. Eventos (quando existirem) -->
      <UPageCard
        v-if="events.length"
        :title="`Eventos (${events.length})`"
        icon="i-lucide-history"
        variant="subtle"
        :ui="{ title: 'text-sm font-semibold', container: 'gap-y-3' }"
      >
        <ShellDataTable
          primary-column-id="event_type"
          status-column-id="status"
          :summary-column-ids="['event_at']"
          :data="events"
          :columns="eventColumns"
          :page="1"
          :total="events.length"
          :items-per-page="events.length || 1"
          :show-footer="false"
          :ui="tableUi"
        >
          <template #event_type-cell="{ row }">
            <span class="text-xs font-medium">
              {{ statusLabel(row.original.event_type) }}
            </span>
          </template>
          <template #event_at-cell="{ row }">
            <span class="text-xs text-muted">
              {{ formatDateTime(row.original.event_at) }}
            </span>
          </template>
          <template #status-cell="{ row }">
            <ShellStatusBadge
              v-if="row.original.status"
              fill
              :status="row.original.status"
            />
            <span v-else class="text-xs text-muted">—</span>
          </template>
        </ShellDataTable>
      </UPageCard>

      <!-- 6b. XML completo / manifestação (NF-e) -->
      <UPageCard
        v-if="isNfe"
        title="XML e manifestação"
        icon="i-lucide-file-key-2"
        variant="subtle"
        :ui="{ title: 'text-sm font-semibold', container: 'gap-y-3' }"
      >
        <UAlert
          v-if="needsFullXml"
          color="warning"
          variant="subtle"
          icon="i-lucide-file-warning"
          title="XML completo indisponível; obtenha-o antes de continuar"
        />

        <div
          v-if="canTriggerSync"
          class="flex flex-wrap gap-2"
        >
          <UButton
            v-if="needsFullXml"
            color="primary"
            size="sm"
            icon="i-lucide-unlock"
            label="Obter XML completo"
            :loading="actionBusy"
            data-testid="nfe-unlock-xml"
            @click="() => { openConfirm('CIENCIA') }"
          />
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            :icon="showOptionalManifest ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            :label="showOptionalManifest ? 'Ocultar manifestação opcional' : 'Manifestação opcional'"
            data-testid="nfe-optional-manifest-toggle"
            @click="() => { showOptionalManifest = !showOptionalManifest }"
          />
        </div>

        <div
          v-if="canTriggerSync && showOptionalManifest"
          class="space-y-2 rounded-lg bg-default/60 p-3 ring ring-inset ring-default"
        >
          <p class="text-xs text-muted">
            Conclusivas são opcionais e de responsabilidade do contribuinte. O produto prioriza captura e entrega de XML.
          </p>
          <div class="flex flex-wrap gap-2">
            <UButton
              size="sm"
              color="neutral"
              variant="soft"
              label="Confirmar operação"
              :disabled="actionBusy"
              @click="openConfirm('CONFIRMACAO')"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="soft"
              label="Desconhecer"
              :disabled="actionBusy"
              @click="openConfirm('DESCONHECIMENTO')"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="soft"
              label="Não realizada"
              :disabled="actionBusy"
              @click="openConfirm('NAO_REALIZADA')"
            />
          </div>
        </div>

        <p
          v-if="note.manifestation_status"
          class="text-xs text-muted"
        >
          Status de manifestação: <span class="font-medium text-toned">{{ note.manifestation_status }}</span>
        </p>
      </UPageCard>

      <ShellFormModal
        v-model:open="confirmOpen"
        :title="confirmTitle"
        :description="confirmDescription"
        submit-label="Confirmar envio"
        :loading="actionBusy"
        :show-default-footer="false"
      >
        <template #body>
          <div class="space-y-3 p-1">
            <UFormField
              v-if="confirmType === 'NAO_REALIZADA'"
              label="Justificativa"
              required
              hint="15 a 255 caracteres"
            >
              <UTextarea
                v-model="justification"
                :rows="3"
                autoresize
                placeholder="Motivo da operação não realizada…"
              />
            </UFormField>
            <p
              v-else
              class="text-sm text-muted"
            >
              Confirme o envio à SEFAZ com o certificado A1 do cliente destinatário.
            </p>
          </div>
        </template>
        <template #footer>
          <ShellModalFooter
            submit-label="Confirmar envio"
            :loading="actionBusy"
            submit-test-id="nfe-manifest-confirm"
            @cancel="() => { confirmOpen = false }"
            @submit="submitManifest"
          />
        </template>
      </ShellFormModal>

      <!-- 7. Metadados do cofre (secundário — técnico) -->
      <UPageCard
        title="Arquivo original (cofre)"
        description="Metadados técnicos — o XML bruto não é renderizado aqui; use download auditado quando disponível."
        icon="i-lucide-archive"
        variant="outline"
        :ui="{
          title: 'text-sm font-semibold',
          description: 'text-xs',
          container: 'gap-y-3'
        }"
      >
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-muted">
              Tipo
            </dt>
            <dd class="mt-0.5 text-highlighted">
              {{ documentMeta?.document_type || '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Schema
            </dt>
            <dd class="mt-0.5 text-highlighted">
              {{ documentMeta?.schema_version || 'Desconhecido' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Tamanho
            </dt>
            <dd class="mt-0.5 text-highlighted">
              {{ formatBytes(documentMeta?.byte_size) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Parse
            </dt>
            <dd class="mt-0.5">
              <UBadge
                v-if="documentMeta?.parse_status"
                :color="parseOk ? 'success' : 'warning'"
                variant="subtle"
                size="sm"
              >
                {{ statusLabel(documentMeta.parse_status) }}
              </UBadge>
              <span v-else class="text-muted">—</span>
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-muted">
              SHA-256
            </dt>
            <dd class="mt-0.5">
              <button
                v-if="documentMeta?.sha256"
                type="button"
                class="group inline-flex max-w-full items-start gap-1 break-all text-left font-mono text-[11px] leading-snug text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                @click="copyText('SHA-256', documentMeta.sha256)"
              >
                <span class="break-all">{{ documentMeta.sha256 }}</span>
                <UIcon name="i-lucide-copy" class="mt-0.5 size-3 shrink-0 opacity-0 group-hover:opacity-70" />
              </button>
              <span v-else class="text-muted">—</span>
            </dd>
          </div>
        </dl>

        <UAlert
          v-if="documentMeta?.parse_status && !parseOk"
          color="warning"
          variant="subtle"
          icon="i-lucide-file-warning"
          :title="documentMeta.parse_alert || 'XML preservado: versão ou XSD não reconhecido'"
        />
      </UPageCard>
    </template>
  </div>
</template>
