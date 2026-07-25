<script setup lang="ts">
import type {
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking
} from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { useDctfwebMonitoring } from '~/composables/useDctfwebMonitoring'
import { usePgdasdMonitoring } from '~/composables/usePgdasdMonitoring'
import { usePgmeiMonitoring } from '~/composables/usePgmeiMonitoring'
import { useSitfisMonitoring } from '~/composables/useSitfisMonitoring'
import { apiErrorMessage } from '~/utils/api-error'
import { formatDateTime } from '~/utils/format'
import { formatPgdasdPeriod, pgdasdTrackingMeta } from '~/utils/pgdasd'

const props = defineProps<{
  previewOpen: boolean
  trackingOpen: boolean
  prefsOpen: boolean
  clientId: number | null
  clientName?: string | null
  preference?: PgdasdCommunicationPreference | null
  /** Mantém o mesmo shell e isola as APIs por domínio fiscal. */
  context?: 'PGDASD' | 'PGMEI' | 'DCTFWEB' | 'SITFIS' | 'FGTS'
  year?: number | null
  periodKey?: string | null
}>()

const emit = defineEmits<{
  'update:previewOpen': [value: boolean]
  'update:trackingOpen': [value: boolean]
  'update:prefsOpen': [value: boolean]
}>()

const pgdasdMonitoring = usePgdasdMonitoring()
const pgmeiMonitoring = usePgmeiMonitoring()
const dctfwebMonitoring = useDctfwebMonitoring()
const sitfisMonitoring = useSitfisMonitoring()
const api = useApi()
const { download: downloadAuthenticated, downloading: downloadBusy } = useAuthenticatedDownload()

const previewLoading = ref(false)
const previewError = ref<string | null>(null)
const preview = ref<PgdasdCommunicationPreview | null>(null)
const trackingLoading = ref(false)
const trackingError = ref<string | null>(null)
const tracking = ref<PgdasdCommunicationTracking | null>(null)
let previewGeneration = 0
let trackingGeneration = 0

const isPgmei = computed(() => props.context === 'PGMEI')
const isDctfweb = computed(() => props.context === 'DCTFWEB')
const isSitfis = computed(() => props.context === 'SITFIS')
const isFgts = computed(() => props.context === 'FGTS')

function fetchPreview(clientId: number) {
  if (isPgmei.value) return pgmeiMonitoring.fetchPreview(clientId)
  if (isDctfweb.value) return dctfwebMonitoring.fetchPreview(clientId)
  if (isSitfis.value) return sitfisMonitoring.fetchPreview(clientId)
  if (isFgts.value) return api.fiscal.communication.preview('fgts', clientId)
  return pgdasdMonitoring.fetchPreview(clientId)
}

function fetchTracking(clientId: number) {
  if (isPgmei.value) return pgmeiMonitoring.fetchTracking(clientId)
  if (isDctfweb.value) return dctfwebMonitoring.fetchTracking(clientId)
  if (isSitfis.value) return sitfisMonitoring.fetchTracking(clientId)
  if (isFgts.value) return api.fiscal.communication.tracking('fgts', clientId)
  return pgdasdMonitoring.fetchTracking(clientId)
}

function requestSend(clientId: number) {
  if (isPgmei.value) return pgmeiMonitoring.requestSend(clientId)
  if (isDctfweb.value) return dctfwebMonitoring.requestSend(clientId)
  if (isSitfis.value) return sitfisMonitoring.requestSend(clientId)
  if (isFgts.value) return api.fiscal.communication.send('fgts', clientId, props.periodKey || undefined)
  return pgdasdMonitoring.requestSend(clientId)
}

function updatePreferences(
  clientId: number,
  body: {
    email_enabled: boolean
    whatsapp_enabled: boolean
    automatic_requested: boolean
    lock_version: number
  }
) {
  if (isPgmei.value) return pgmeiMonitoring.updatePreferences(clientId, body)
  if (isDctfweb.value) return dctfwebMonitoring.updatePreferences(clientId, body)
  if (isSitfis.value) return sitfisMonitoring.updatePreferences(clientId, body)
  if (isFgts.value) return api.fiscal.communication.updatePreference('fgts', clientId, body)
  return pgdasdMonitoring.updatePreferences(clientId, body)
}

const sendBusy = ref(false)
const prefsBusy = ref(false)
const draftEmail = ref(false)
const draftWhatsapp = ref(false)
const draftAutomatic = ref(false)

const displayedPreference = computed(() => preview.value?.preferences || props.preference || null)

watch(displayedPreference, (pref) => {
  if (!pref) return
  draftEmail.value = Boolean(pref.email_enabled)
  draftWhatsapp.value = Boolean(pref.whatsapp_enabled)
  draftAutomatic.value = Boolean(pref.automatic_requested)
}, { immediate: true })

async function confirmSend() {
  if (!props.clientId || sendBusy.value) return
  sendBusy.value = true
  try {
    const result = await requestSend(props.clientId)
    useToast().add({
      title: 'Envio enfileirado',
      description: result.provider_enabled
        ? `${result.queued} despacho(s) na fila.`
        : `${result.queued} despacho(s) registrados (provider fail-closed).`,
      color: 'success'
    })
    emit('update:previewOpen', false)
  } catch (caught) {
    useToast().add({
      title: apiErrorMessage(caught, 'Falha ao enfileirar envio.'),
      color: 'error'
    })
  } finally {
    sendBusy.value = false
  }
}

async function savePreferences() {
  if (!props.clientId || !displayedPreference.value || prefsBusy.value) return
  prefsBusy.value = true
  try {
    await updatePreferences(props.clientId, {
      email_enabled: draftEmail.value,
      whatsapp_enabled: draftWhatsapp.value,
      automatic_requested: draftAutomatic.value,
      lock_version: Number(displayedPreference.value.lock_version || 0)
    })
    useToast().add({ title: 'Preferências salvas', color: 'success' })
    await loadPreview()
  } catch (caught) {
    useToast().add({
      title: apiErrorMessage(caught, 'Falha ao salvar preferências.'),
      color: 'error'
    })
  } finally {
    prefsBusy.value = false
  }
}

function documentDownloadHref(document: { id: number, download_href?: string | null }): string | undefined {
  if (isPgmei.value) return document.download_href?.trim() || undefined
  if (isFgts.value) return document.download_href?.trim() || undefined
  if (isDctfweb.value && props.clientId) {
    return dctfwebMonitoring.evidenceDownloadUrl(props.clientId, document.id)
  }
  if (isSitfis.value) {
    return document.download_href?.trim() || sitfisMonitoring.evidenceDownloadUrl(document.id)
  }
  return pgdasdMonitoring.artifactDownloadUrl(document.id)
}

async function downloadDocument(document: {
  id: number
  filename?: string | null
  kind?: string | null
  download_href?: string | null
}): Promise<void> {
  const href = documentDownloadHref(document)
  if (!href) return
  const filename = (document.filename || '').trim()
    || `documento-${document.kind || document.id}.pdf`
  await downloadAuthenticated(href, filename)
}

function channelLabel(channel?: string | null): string {
  return channel === 'WHATSAPP' ? 'WhatsApp' : channel === 'EMAIL' ? 'E-mail' : channel || 'Canal'
}

async function loadPreview() {
  const clientId = props.clientId
  if (!clientId) return
  const generation = ++previewGeneration
  previewLoading.value = true
  previewError.value = null
  try {
    const response = await fetchPreview(clientId)
    if (generation !== previewGeneration) return
    preview.value = response
  } catch (caught) {
    if (generation !== previewGeneration) return
    previewError.value = apiErrorMessage(caught, 'Não foi possível carregar a prévia local.')
    preview.value = null
  } finally {
    if (generation === previewGeneration) previewLoading.value = false
  }
}

async function loadTracking() {
  const clientId = props.clientId
  if (!clientId) return
  const generation = ++trackingGeneration
  trackingLoading.value = true
  trackingError.value = null
  try {
    const response = await fetchTracking(clientId)
    if (generation === trackingGeneration) tracking.value = response
  } catch (caught) {
    if (generation !== trackingGeneration) return
    trackingError.value = apiErrorMessage(caught, 'Não foi possível carregar o rastreio local.')
    tracking.value = null
  } finally {
    if (generation === trackingGeneration) trackingLoading.value = false
  }
}

watch(
  () => [props.previewOpen, props.prefsOpen, props.clientId] as const,
  ([previewOpen, prefsOpen]) => {
    if (previewOpen || prefsOpen) {
      void loadPreview()
    } else {
      previewGeneration += 1
      preview.value = null
      previewError.value = null
    }
  },
  { immediate: true }
)

watch(
  () => [props.trackingOpen, props.clientId] as const,
  ([open]) => {
    if (open) {
      void loadTracking()
    } else {
      trackingGeneration += 1
      tracking.value = null
      trackingError.value = null
    }
  },
  { immediate: true }
)

function openPreferences() {
  emit('update:previewOpen', false)
  emit('update:prefsOpen', true)
}
</script>

<template>
  <ShellScrollableModal
    :open="previewOpen"
    title="Informações de comunicação"
    description="Destinatários, documento canônico, canais e elegibilidade do envio."
    content-class="w-[calc(100vw-1rem)] sm:max-w-3xl"
    :test-id="isPgmei ? 'pgmei-communication-preview' : 'pgdasd-communication-preview'"
    :show-default-footer="false"
    @update:open="emit('update:previewOpen', $event)"
    @cancel="emit('update:previewOpen', false)"
  >
    <template #body>
      <div class="space-y-4">
        <div>
          <p class="font-medium text-highlighted">
            {{ preview?.client?.legal_name || clientName || `Cliente #${clientId || '—'}` }}
          </p>
          <p class="text-xs text-muted">
            {{ isPgmei
              ? `Ano ${year || '—'}`
              : isSitfis
                ? 'Situação Fiscal'
                : isFgts
                  ? `Competência ${formatPgdasdPeriod(preview?.period_key || periodKey)}`
                  : `PA ${formatPgdasdPeriod(preview?.period_key)}` }}
          </p>
        </div>

        <UAlert
          v-if="!previewLoading && preview && !preview.provider_enabled"
          color="warning"
          variant="subtle"
          icon="i-lucide-info"
          title="Comunicação externa indisponível"
        >
          <template #description>
            Nenhum transporte está ativo nesta capacidade. A fila permanece fail-closed e não executa egress.
          </template>
        </UAlert>
        <UAlert
          v-else-if="preview?.provider_enabled && preview.execution_mode === 'WHATSAPP_NATIVE'"
          color="success"
          variant="subtle"
          icon="i-lucide-message-circle-check"
          title="WhatsApp nativo disponível"
          description="O envio usa a inbox geral do escritório, aparece na timeline compartilhada e preserva receipts auditáveis."
        />

        <UAlert v-if="previewError" color="error" :title="previewError">
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar novamente"
              @click="loadPreview"
            />
          </template>
        </UAlert>
        <ShellLoadingModalBody v-if="previewLoading" :rows="2" />

        <template v-else-if="preview">
          <section>
            <h3 class="mb-2 text-sm font-medium">
              Canais cadastrados e destinatários protegidos
            </h3>
            <div v-if="preview.channels?.length" class="grid gap-2 sm:grid-cols-2">
              <div
                v-for="channel in preview.channels"
                :key="channel.channel"
                class="rounded-md border border-default p-3"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="font-medium text-highlighted">{{ channelLabel(channel.channel) }}</span>
                  <UBadge
                    :color="channel.eligible ? 'success' : 'warning'"
                    :label="channel.eligible ? 'Contato disponível' : 'Sem contato disponível'"
                    variant="subtle"
                  />
                </div>
                <ul v-if="channel.recipients?.length" class="mt-2 space-y-1 text-xs text-muted">
                  <li v-for="recipient in channel.recipients" :key="`${channel.channel}-${recipient.contact_id}-${recipient.masked}`">
                    {{ recipient.name || 'Contato' }} · {{ recipient.masked || 'destinatário protegido' }}
                  </li>
                </ul>
                <p v-else class="mt-2 text-xs text-muted">
                  Nenhum contato disponível.
                </p>
              </div>
            </div>
            <p v-else class="text-sm text-muted">
              Nenhum canal configurado.
            </p>
          </section>

          <section>
            <h3 class="mb-2 text-sm font-medium">
              Documentos locais
            </h3>
            <ul v-if="preview.documents?.length" class="space-y-2">
              <li
                v-for="document in preview.documents"
                :key="document.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default p-2 text-sm"
              >
                <span>{{ document.filename || document.kind || `Documento #${document.id}` }}</span>
                <UButton
                  v-if="documentDownloadHref(document)"
                  size="xs"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  label="Baixar"
                  :loading="downloadBusy"
                  :disabled="downloadBusy"
                  @click="downloadDocument(document)"
                />
              </li>
            </ul>
            <p v-else class="text-sm text-muted">
              Nenhum documento local disponível para a prévia.
            </p>
          </section>

          <UAlert
            v-for="warning in preview.warnings || []"
            :key="warning"
            color="warning"
            variant="subtle"
            :title="warning"
          />
        </template>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Fechar"
          @click="emit('update:previewOpen', false)"
        />
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-settings-2"
          label="Preferências"
          @click="openPreferences"
        />
        <UButton
          color="primary"
          icon="i-lucide-send"
          label="Enviar"
          :loading="sendBusy"
          :disabled="!preview?.can_send || sendBusy"
          data-testid="communication-send-confirm"
          @click="confirmSend"
        />
      </div>
    </template>
  </ShellScrollableModal>

  <ShellScrollableModal
    :open="prefsOpen"
    title="Preferências de comunicação"
    description="Canais e intenção automática; o envio ocorre somente no cutoff da política."
    content-class="w-[calc(100vw-1rem)] sm:max-w-xl"
    :test-id="isPgmei ? 'pgmei-communication-preferences' : 'pgdasd-communication-preferences'"
    :show-default-footer="false"
    @update:open="emit('update:prefsOpen', $event)"
    @cancel="emit('update:prefsOpen', false)"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="!(displayedPreference?.provider_enabled ?? preview?.provider_enabled)"
          color="warning"
          variant="subtle"
          icon="i-lucide-info"
          title="Provider de envio desligado"
          description="Preferências e fila ficam ativas; o envio externo permanece fail-closed até a flag ser ligada."
        />
        <UAlert v-if="previewError" color="error" :title="previewError" />

        <div class="divide-y divide-default rounded-lg border border-default">
          <div class="flex items-center justify-between gap-3 p-3">
            <span class="text-sm text-highlighted">E-mail</span>
            <USwitch v-model="draftEmail" size="sm" aria-label="Habilitar e-mail" />
          </div>
          <div class="flex items-center justify-between gap-3 p-3">
            <span class="text-sm text-highlighted">WhatsApp</span>
            <USwitch v-model="draftWhatsapp" size="sm" aria-label="Habilitar WhatsApp" />
          </div>
          <div class="flex items-center justify-between gap-3 p-3">
            <span class="text-sm text-highlighted">Envio automático no cutoff da política</span>
            <USwitch v-model="draftAutomatic" size="sm" aria-label="Envio automático" />
          </div>
        </div>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        submit-label="Salvar"
        :loading="prefsBusy"
        @cancel="emit('update:prefsOpen', false)"
        @submit="savePreferences"
      />
    </template>
  </ShellScrollableModal>

  <ShellScrollableModal
    :open="trackingOpen"
    title="Histórico local de comunicação"
    description="Registros já existentes; abrir este modal não envia nem altera entregas."
    content-class="w-[calc(100vw-1rem)] sm:max-w-3xl"
    :test-id="isPgmei ? 'pgmei-communication-tracking' : 'pgdasd-communication-tracking'"
    :show-default-footer="false"
    @update:open="emit('update:trackingOpen', $event)"
    @cancel="emit('update:trackingOpen', false)"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert v-if="trackingError" color="error" :title="trackingError">
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar novamente"
              @click="loadTracking"
            />
          </template>
        </UAlert>
        <ShellLoadingModalBody v-if="trackingLoading" :rows="2" />

        <template v-else-if="tracking">
          <UBadge
            :color="pgdasdTrackingMeta(tracking.status).color"
            :icon="pgdasdTrackingMeta(tracking.status).icon"
            :label="pgdasdTrackingMeta(tracking.status).label"
            variant="subtle"
          />

          <div v-if="tracking.channels?.length" class="space-y-3">
            <UCard v-for="channel in tracking.channels" :key="channel.channel">
              <template #header>
                <div class="flex items-center justify-between gap-2">
                  <span class="font-medium text-highlighted">{{ channelLabel(channel.channel) }}</span>
                  <UBadge
                    :color="pgdasdTrackingMeta(channel.status).color"
                    :label="pgdasdTrackingMeta(channel.status).label"
                    variant="subtle"
                  />
                </div>
              </template>

              <div v-if="channel.dispatches?.length" class="space-y-3">
                <div
                  v-for="dispatch in channel.dispatches"
                  :key="dispatch.id || `${dispatch.period_key}-${dispatch.recipient_masked}`"
                  class="rounded-md border border-default p-3 text-sm"
                >
                  <p class="font-medium text-highlighted">
                    {{ dispatch.recipient_masked || 'Destinatário protegido' }} ·
                    {{ isPgmei
                      ? `Ano ${dispatch.period_key || '—'}`
                      : isSitfis
                        ? (dispatch.period_key || 'SITFIS')
                        : isFgts
                          ? `Competência ${formatPgdasdPeriod(dispatch.period_key)}`
                          : `PA ${formatPgdasdPeriod(dispatch.period_key)}` }}
                  </p>
                  <p class="mt-1 text-xs text-muted">
                    {{ pgdasdTrackingMeta(dispatch.status).label }} ·
                    {{ formatDateTime(dispatch.read_at || dispatch.delivered_at || dispatch.sent_at || dispatch.queued_at || dispatch.failed_at || dispatch.canceled_at) }}
                  </p>
                  <ul v-if="dispatch.events?.length" class="mt-2 border-l border-default pl-3 text-xs text-muted">
                    <li v-for="event in dispatch.events" :key="`${event.status}-${event.occurred_at}`">
                      {{ pgdasdTrackingMeta(event.status).label }} · {{ formatDateTime(event.occurred_at) }}
                    </li>
                  </ul>
                </div>
              </div>
              <p v-else class="text-sm text-muted">
                Nenhum registro histórico neste canal.
              </p>
            </UCard>
          </div>

          <div v-else class="py-10 text-center">
            <UIcon name="i-lucide-message-square-dashed" class="mx-auto mb-2 size-8 text-dimmed" />
            <p class="font-medium text-highlighted">
              Nenhum histórico registrado
            </p>
            <p class="text-sm text-muted">
              O workspace não fabrica eventos de entrega ou leitura.
            </p>
          </div>
        </template>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        :show-submit="false"
        @cancel="emit('update:trackingOpen', false)"
      />
    </template>
  </ShellScrollableModal>
</template>
