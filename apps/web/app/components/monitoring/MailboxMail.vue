<script setup lang="ts">
/**
 * Detalhe canônico de mensagem (arquétipo InboxMail).
 * Corpo via fetch autenticado (preview) + download; anexos protegidos.
 * Triagem: NEW / IN_REVIEW / RESOLVED apenas; não altera ciência oficial.
 */
import {
  MAILBOX_TRIAGE_SELECT_ITEMS,
  mailboxTriageLabel,
  parseMailboxTriageStatus,
  type MailboxTriageStatus
} from '~/utils/mailbox-triage'
import { parseMailboxBodyPreviewBlob } from '~/utils/mailbox-body-preview'
import { toSanctumApiPath } from '~/utils/authenticated-download'
import type { MailboxCostPreview } from '~/types/mailbox-monitoring'

export interface MailboxMessageDetail {
  id: number
  client_id?: number | null
  subject_preview?: string | null
  sender_label?: string | null
  sender_code?: string | null
  triage_status?: string | null
  triage_note?: string | null
  severity_hint?: string | null
  received_at_official?: string | null
  created_at?: string | null
  due_at?: string | null
  official_read_indicator?: string | boolean | null
  official_read_observed_at?: string | null
  category_label?: string | null
  category_code?: string | null
  source?: string | null
  has_body?: boolean
  attachment_count?: number
  attachments?: Array<{
    id: number
    filename?: string | null
    name?: string | null
  }>
}

export interface MailboxDteState {
  status?: string | null
  source?: string | null
  observed_at?: string | null
}

const props = defineProps<{
  messageId: number
  /** Em painel adjacente, botão fechar navega de volta. */
  showClose?: boolean
}>()

const emit = defineEmits<{
  close: []
  triaged: []
}>()

const api = useApi()
const toast = useToast()
const sanctum = useSanctumClient()
const { download: authDownload, downloading: bodyDownloading } = useAuthenticatedDownload()
const { canTriageMailbox, sessionEpoch } = useDashboard()
const apiBase = String(useRuntimeConfig().public.apiBase || '').replace(/\/$/, '')

const loading = ref(false)
const loadError = ref<string | null>(null)
const message = ref<MailboxMessageDetail | null>(null)
const meta = ref<Record<string, unknown> | null>(null)
const dte = ref<MailboxDteState | null>(null)
const triageStatus = ref<MailboxTriageStatus>('NEW')
const triageNote = ref('')
const saving = ref(false)
const bodyPreview = ref<string | null>(null)
const bodyPreviewError = ref<string | null>(null)
const bodyPreviewLoading = ref(false)
const detailPreview = ref<MailboxCostPreview | null>(null)
const detailModalOpen = ref(false)
const detailPreviewLoading = ref(false)
const detailConfirming = ref(false)
const detailQueued = ref(false)
let loadSeq = 0

const triageItems = [...MAILBOX_TRIAGE_SELECT_ITEMS]

const attachments = computed(() => message.value?.attachments || [])

const triageBadgeLabel = computed(() => mailboxTriageLabel(message.value?.triage_status))

const officialReadLabel = computed(() => {
  if (meta.value?.official_read_unchanged === true) {
    return 'Inalterada pela triagem'
  }
  const v = message.value?.official_read_indicator
  if (v === true || v === 'READ' || v === 'true') return 'Lida (oficial)'
  if (v === false || v === 'UNREAD' || v === 'false') return 'Não lida (oficial)'
  if (v == null || v === '') return '—'
  return String(v)
})

async function loadDte(clientId: number, parentSeq: number, parentEpoch: number) {
  try {
    const res = await api.fiscal.mailbox.state({ client_id: clientId })
    if (parentSeq !== loadSeq || parentEpoch !== sessionEpoch.value) return
    const data = res.data as { dte?: MailboxDteState } | null
    dte.value = data?.dte || null
  } catch {
    if (parentSeq !== loadSeq || parentEpoch !== sessionEpoch.value) return
    dte.value = null
  }
}

async function loadBodyPreview(messageId: number, parentSeq: number, parentEpoch: number) {
  bodyPreview.value = null
  bodyPreviewError.value = null
  bodyPreviewLoading.value = true
  try {
    const path = toSanctumApiPath(api.fiscal.mailbox.bodyDownloadUrl(messageId), apiBase)
    const blob = await sanctum<Blob>(path, {
      method: 'GET',
      responseType: 'blob' as 'json',
      headers: { Accept: 'text/plain, text/*, */*' }
    })
    if (parentSeq !== loadSeq || parentEpoch !== sessionEpoch.value) return
    if (!(blob instanceof Blob)) {
      bodyPreviewError.value = 'Resposta inválida ao carregar o corpo.'
      return
    }
    const parsed = await parseMailboxBodyPreviewBlob(blob)
    if (parentSeq !== loadSeq || parentEpoch !== sessionEpoch.value) return
    if (parsed.ok) {
      bodyPreview.value = parsed.text
    } else {
      bodyPreviewError.value = parsed.error
    }
  } catch (caught) {
    if (parentSeq !== loadSeq || parentEpoch !== sessionEpoch.value) return
    bodyPreviewError.value = apiErrorMessage(caught, 'Falha ao carregar o corpo.')
  } finally {
    if (parentSeq === loadSeq && parentEpoch === sessionEpoch.value) {
      bodyPreviewLoading.value = false
    }
  }
}

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  if (!Number.isFinite(props.messageId) || props.messageId < 1) {
    loadError.value = 'ID inválido.'
    message.value = null
    return
  }
  loading.value = true
  loadError.value = null
  dte.value = null
  bodyPreview.value = null
  bodyPreviewError.value = null
  try {
    const res = await api.fiscal.mailbox.get(props.messageId)
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    message.value = res.data as unknown as MailboxMessageDetail
    meta.value = res.meta || null
    triageStatus.value = parseMailboxTriageStatus(res.data.triage_status) || 'NEW'
    triageNote.value = String(res.data.triage_note || '')
    const cid = Number(res.data.client_id)
    if (Number.isFinite(cid) && cid > 0) {
      void loadDte(cid, seq, epoch)
    }
    if (res.data.has_body === true) {
      void loadBodyPreview(props.messageId, seq, epoch)
    }
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    message.value = null
    loadError.value = apiErrorMessage(caught, 'Mensagem não encontrada ou sem permissão.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function saveTriage() {
  if (!canTriageMailbox.value) return
  const status = parseMailboxTriageStatus(triageStatus.value)
  if (!status) {
    toast.add({
      title: 'Triagem inválida. Use Nova, Em análise ou Resolvida.',
      color: 'warning'
    })
    return
  }
  saving.value = true
  try {
    const res = await api.fiscal.mailbox.triage(props.messageId, {
      triage_status: status,
      note: triageNote.value || undefined
    })
    message.value = res.data as unknown as MailboxMessageDetail
    meta.value = {
      ...(meta.value || {}),
      ...(res.meta || {}),
      official_read_unchanged: true
    }
    toast.add({
      title: 'Triagem atualizada',
      color: 'success'
    })
    emit('triaged')
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao triar.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

async function openBody() {
  await authDownload(
    api.fiscal.mailbox.bodyDownloadUrl(props.messageId),
    `mailbox-message-${props.messageId}.txt`
  )
}

async function openAttachment(attachmentId: number) {
  await authDownload(
    api.fiscal.mailbox.attachmentDownloadUrl(props.messageId, attachmentId),
    `mailbox-attachment-${attachmentId}.bin`
  )
}

async function openDetailPreview() {
  detailPreviewLoading.value = true
  try {
    detailPreview.value = (await api.fiscal.mailbox.detailPreview(props.messageId)).data.cost
    detailModalOpen.value = true
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível preparar o conteúdo.'), color: 'error' })
  } finally {
    detailPreviewLoading.value = false
  }
}

async function confirmDetail() {
  detailConfirming.value = true
  try {
    await api.fiscal.mailbox.enqueueDetail(props.messageId)
    detailQueued.value = true
    detailModalOpen.value = false
    toast.add({
      title: 'Busca do corpo iniciada',
      description: 'A mensagem será atualizada quando a consulta DETALHE terminar.',
      color: 'success'
    })
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao iniciar o DETALHE.'), color: 'error' })
  } finally {
    detailConfirming.value = false
  }
}

function onClose() {
  emit('close')
}

watch(() => props.messageId, () => {
  void load()
}, { immediate: true })

watch(sessionEpoch, () => {
  message.value = null
  meta.value = null
  dte.value = null
  loadError.value = null
  bodyPreview.value = null
  bodyPreviewError.value = null
  detailQueued.value = false
  void load()
})
</script>

<template>
  <UDashboardPanel id="mailbox-detail" data-testid="mailbox-detail">
    <template #header>
      <UDashboardNavbar :title="String(message?.subject_preview || `Mensagem #${messageId}`)" data-testid="mailbox-detail-navbar" :toggle="false">
        <template #leading>
          <UButton
            v-if="showClose"
            icon="i-lucide-x"
            color="neutral"
            variant="ghost"
            class="-ms-1.5"
            aria-label="Fechar detalhe"
            @click="onClose"
          />
          <UDashboardSidebarCollapse
            v-else
            class="lg:hidden"
          />
        </template>
        <template #right>
          <UButton
            v-if="message?.has_body"
            color="neutral"
            variant="ghost"
            icon="i-lucide-download"
            label="Baixar corpo"
            :loading="bodyDownloading"
            @click="openBody"
          />
          <UButton
            to="/monitoring/mailbox"
            color="neutral"
            variant="ghost"
            icon="i-lucide-arrow-left"
            label="Lista"
            class="lg:hidden"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-1 flex-col overflow-y-auto">
        <UAlert
          v-if="loadError"
          color="error"
          icon="i-lucide-circle-x"
          :title="loadError"
          class="m-4"
        >
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar de novo"
              @click="load"
            />
          </template>
        </UAlert>
        <div
          v-else-if="loading"
          class="p-8 text-center text-sm text-muted"
        >
          Carregando mensagem…
        </div>
        <template v-else-if="message">
          <div class="flex flex-col gap-1 border-b border-default p-4 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-semibold text-highlighted">
                  {{ message.sender_label || message.sender_code || 'Remetente' }}
                </p>
                <p class="text-sm text-muted">
                  Cliente
                  <NuxtLink
                    v-if="message.client_id"
                    class="text-primary"
                    :to="`/monitoring/clients/${message.client_id}`"
                  >
                    #{{ message.client_id }}
                  </NuxtLink>
                  <span v-else>—</span>
                  · Recebida
                  {{ formatDateTime(String(message.received_at_official || message.created_at || '') || null) }}
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <UBadge
                  color="neutral"
                  variant="subtle"
                  data-testid="mailbox-triage-badge"
                >
                  {{ triageBadgeLabel }}
                </UBadge>
                <UBadge
                  v-if="message.severity_hint"
                  color="warning"
                  variant="subtle"
                >
                  {{ message.severity_hint }}
                </UBadge>
              </div>
            </div>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-muted">
                  Prazo
                </dt>
                <dd>{{ formatDateTime(String(message.due_at || '') || null) }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Leitura oficial
                </dt>
                <dd>{{ officialReadLabel }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  DTE
                </dt>
                <dd>
                  <template v-if="dte?.status">
                    {{ dte.status }}
                    <span
                      v-if="dte.observed_at"
                      class="text-xs text-muted"
                    >
                      · {{ formatDateTime(dte.observed_at) }}
                    </span>
                  </template>
                  <template v-else>
                    —
                  </template>
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Categoria
                </dt>
                <dd>{{ message.category_label || message.category_code || '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Fonte
                </dt>
                <dd>{{ message.source || '—' }}</dd>
              </div>
            </dl>
            <p class="mt-3 whitespace-pre-wrap text-sm">
              {{ message.subject_preview || 'Sem prévia de assunto.' }}
            </p>
          </div>

          <div
            class="border-b border-default p-4 sm:px-6"
            data-testid="mailbox-body-preview"
          >
            <div class="mb-2 flex items-center justify-between gap-2">
              <h3 class="text-sm font-medium">
                Corpo da mensagem
              </h3>
              <UButton
                v-if="message.has_body"
                size="xs"
                color="neutral"
                variant="soft"
                icon="i-lucide-download"
                label="Baixar"
                :loading="bodyDownloading"
                @click="openBody"
              />
            </div>
            <div v-if="!message.has_body" class="flex flex-col items-start gap-2" data-testid="mailbox-body-pending">
              <p class="text-sm text-muted">
                {{ detailQueued ? 'Consulta DETALHE enfileirada. Atualize em alguns instantes.' : 'Corpo ainda não sincronizado.' }}
              </p>
              <UButton
                v-if="!detailQueued"
                size="sm"
                color="neutral"
                variant="soft"
                icon="i-lucide-file-search"
                label="Buscar corpo"
                :loading="detailPreviewLoading"
                data-testid="mailbox-detail-preview-button"
                @click="openDetailPreview"
              />
            </div>
            <p
              v-else-if="bodyPreviewLoading"
              class="text-sm text-muted"
            >
              Carregando corpo…
            </p>
            <UAlert
              v-else-if="bodyPreviewError"
              color="warning"
              variant="subtle"
              :title="bodyPreviewError"
            />
            <p
              v-else-if="bodyPreview"
              class="whitespace-pre-wrap text-sm text-highlighted"
            >
              {{ bodyPreview }}
            </p>
          </div>

          <div
            v-if="attachments.length"
            class="border-b border-default p-4 sm:px-6"
          >
            <h3 class="mb-2 text-sm font-medium">
              Anexos protegidos
            </h3>
            <ul class="flex flex-col gap-2">
              <li
                v-for="att in attachments"
                :key="String(att.id)"
              >
                <UButton
                  size="sm"
                  color="neutral"
                  variant="soft"
                  icon="i-lucide-paperclip"
                  :label="String(att.filename || att.name || `Anexo #${att.id}`)"
                  @click="openAttachment(Number(att.id))"
                />
              </li>
            </ul>
          </div>

          <div
            v-if="canTriageMailbox"
            class="p-4 sm:px-6"
            data-testid="mailbox-triage-form"
          >
            <h3 class="mb-1 text-sm font-medium">
              Triagem interna
            </h3>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
              <UFormField
                label="Status"
                class="flex-1"
              >
                <USelect
                  v-model="triageStatus"
                  :items="triageItems"
                  value-key="value"
                />
              </UFormField>
              <UFormField
                label="Nota"
                class="flex-[2]"
              >
                <UInput
                  v-model="triageNote"
                  placeholder="Opcional"
                />
              </UFormField>
              <UButton
                label="Salvar triagem"
                :loading="saving"
                data-testid="mailbox-triage-save"
                @click="saveTriage"
              />
            </div>
          </div>
        </template>
      </div>
    </template>

    <UModal
      v-model:open="detailModalOpen"
      title="Carregar conteúdo completo"
      description="Confirme para buscar o texto e os anexos desta mensagem."
      :ui="{ footer: 'justify-end' }"
    >
      <template #body>
        <UAlert
          v-if="detailPreview"
          :color="detailPreview.allowed ? 'primary' : 'warning'"
          variant="subtle"
          :icon="detailPreview.allowed ? 'i-lucide-mail-open' : 'i-lucide-circle-x'"
          :title="detailPreview.allowed ? 'Conteúdo pronto para ser solicitado' : 'Conteúdo indisponível no momento'"
          :description="detailPreview.allowed
            ? 'A busca será feita uma única vez e a mensagem será atualizada automaticamente.'
            : 'Nenhuma busca foi realizada. Tente novamente mais tarde ou fale com o suporte.'"
        />
      </template>
      <template #footer="{ close }">
        <UButton
          color="neutral"
          variant="outline"
          label="Cancelar"
          @click="close"
        />
        <UButton
          label="Buscar conteúdo"
          :loading="detailConfirming"
          :disabled="!detailPreview?.allowed"
          data-testid="mailbox-detail-confirm"
          @click="confirmDetail"
        />
      </template>
    </UModal>
  </UDashboardPanel>
</template>
