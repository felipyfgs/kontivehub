<script setup lang="ts">
import type {
  CcmeiHistoryPayload,
  CcmeiIssuedCertificateHistoryPayload,
  CcmeiRegistrationStatusHistoryPayload,
  FiscalMonitoringQueryState
} from '~/types/fiscal-modules'
import type { DasnHistoryPayload, MeiCoverage } from '~/types/mei-public-services'

const props = withDefaults(defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
  cnpjMasked?: string | null
  canRefresh: boolean
  initialService?: 'ccmei' | 'dasn'
}>(), {
  initialService: 'ccmei'
})

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()
const ccmei = useCcmeiMonitoring()
const ccmeiRegistration = useCcmeiRegistrationStatusMonitoring()

const activeService = ref<'ccmei' | 'dasn'>(props.initialService)
const serviceTabs = [
  { label: 'CCMEI', value: 'ccmei', icon: 'i-lucide-badge-check' },
  { label: 'DASN-SIMEI', value: 'dasn', icon: 'i-lucide-files' }
]

const ccmeiHistory = ref<CcmeiHistoryPayload | null>(null)
const registrationHistory = ref<CcmeiRegistrationStatusHistoryPayload | null>(null)
const certificateHistory = ref<CcmeiIssuedCertificateHistoryPayload | null>(null)
const ccmeiState = ref<FiscalMonitoringQueryState>('IDLE')
const registrationState = ref<FiscalMonitoringQueryState>('IDLE')
const ccmeiError = ref<string | null>(null)
const ccmeiLoading = ref(false)
let ccmeiGeneration = 0

const dasnYear = ref(new Date().getFullYear())
const includeFullReceipt = ref(false)
const dasnHistory = ref<DasnHistoryPayload | null>(null)
const dasnError = ref<string | null>(null)
const dasnLoading = ref(false)
const dasnPolling = ref(false)
const dasnBaselineAttemptId = ref<number | null>(null)
let dasnGeneration = 0
let dasnPollCount = 0
let dasnPollTimer: ReturnType<typeof setTimeout> | null = null

const dasnAttempt = computed(() => dasnHistory.value?.attempt || null)
const dasnState = computed<FiscalMonitoringQueryState>(() => {
  if (dasnLoading.value && !dasnHistory.value) return 'PROCESSING'
  if (dasnPolling.value || dasnAttempt.value?.status === 'QUEUED') return 'QUEUED'
  if (dasnAttempt.value?.status === 'RUNNING') return 'PROCESSING'
  if (dasnError.value) return 'FAILED'
  if (dasnAttempt.value?.status === 'WAITING_USER_ACTION') return 'BLOCKED'
  if (dasnAttempt.value?.status === 'FAILED') return 'FAILED'
  if (dasnHistory.value?.declarations.length) return 'READY'
  return dasnHistory.value ? 'NO_DATA' : 'IDLE'
})

function clearTimers() {
  if (dasnPollTimer) clearTimeout(dasnPollTimer)
  dasnPollTimer = null
}

function resetState() {
  clearTimers()
  activeService.value = props.initialService
  ccmeiGeneration += 1
  dasnGeneration += 1
  ccmeiHistory.value = null
  registrationHistory.value = null
  certificateHistory.value = null
  ccmeiState.value = 'IDLE'
  registrationState.value = 'IDLE'
  ccmeiError.value = null
  ccmeiLoading.value = false
  dasnYear.value = new Date().getFullYear()
  includeFullReceipt.value = false
  dasnHistory.value = null
  dasnError.value = null
  dasnLoading.value = false
  dasnPolling.value = false
  dasnBaselineAttemptId.value = null
  dasnPollCount = 0
}

async function loadCcmei() {
  const clientId = props.clientId
  if (!clientId || ccmeiLoading.value) return
  const generation = ++ccmeiGeneration
  ccmeiLoading.value = true
  ccmeiError.value = null
  if (!ccmeiHistory.value) ccmeiState.value = 'PROCESSING'
  if (!registrationHistory.value) registrationState.value = 'PROCESSING'

  const [summary, registration, certificates] = await Promise.allSettled([
    ccmei.fetchHistory(clientId),
    ccmeiRegistration.fetchHistory(clientId),
    api.fiscal.ccmei.issuedCertificates.history(clientId).then(response => response.data)
  ])
  if (generation !== ccmeiGeneration) return

  if (summary.status === 'fulfilled') {
    ccmeiHistory.value = summary.value
    ccmeiState.value = summary.value.current || summary.value.history?.length ? 'READY' : 'NO_DATA'
  } else {
    ccmeiState.value = 'FAILED'
  }
  if (registration.status === 'fulfilled') {
    registrationHistory.value = registration.value
    registrationState.value = registration.value.current || registration.value.history?.length ? 'READY' : 'NO_DATA'
  } else {
    registrationState.value = 'FAILED'
  }
  if (certificates.status === 'fulfilled') certificateHistory.value = certificates.value

  const failures = [summary, registration, certificates].filter(result => result.status === 'rejected')
  if (failures.length) {
    ccmeiError.value = failures.length === 3
      ? 'Não foi possível carregar o histórico CCMEI.'
      : 'Parte do histórico CCMEI não pôde ser atualizada; os snapshots disponíveis foram preservados.'
  }
  ccmeiLoading.value = false
}

async function consultCcmei(kind: 'summary' | 'registration') {
  const clientId = props.clientId
  if (!clientId || !props.canRefresh || ccmeiLoading.value) return
  ccmeiLoading.value = true
  ccmeiError.value = null
  if (kind === 'summary') ccmeiState.value = 'QUEUED'
  else registrationState.value = 'QUEUED'
  try {
    if (kind === 'summary') await ccmei.requestConsult(clientId)
    else await ccmeiRegistration.requestConsult(clientId)
    toast.add({
      title: kind === 'summary' ? 'Consulta CCMEI solicitada.' : 'Consulta cadastral CCMEI solicitada.',
      description: 'O snapshot local será atualizado após o processamento.',
      color: 'success'
    })
  } catch (caught) {
    if (kind === 'summary') ccmeiState.value = ccmeiHistory.value ? 'READY' : 'FAILED'
    else registrationState.value = registrationHistory.value ? 'READY' : 'FAILED'
    ccmeiError.value = apiErrorMessage(caught, 'Não foi possível solicitar a consulta CCMEI.')
  } finally {
    ccmeiLoading.value = false
  }
}

async function loadDasnHistory(silent = false) {
  const clientId = props.clientId
  if (!clientId || (dasnLoading.value && !silent)) return
  const generation = ++dasnGeneration
  if (!silent) dasnLoading.value = true
  dasnError.value = null
  try {
    const response = await api.fiscal.meiPublicServices.dasn.history(clientId, dasnYear.value)
    if (generation === dasnGeneration) dasnHistory.value = response.data
  } catch (caught) {
    if (generation !== dasnGeneration) return
    dasnError.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico DASN-SIMEI.')
  } finally {
    if (generation === dasnGeneration && !silent) dasnLoading.value = false
  }
}

function scheduleDasnPoll() {
  if (!props.open || !dasnPolling.value) return
  if (dasnPollCount >= 48) {
    dasnPolling.value = false
    dasnError.value = 'A consulta continua em segundo plano. Atualize o histórico em alguns instantes.'
    return
  }
  dasnPollCount += 1
  dasnPollTimer = setTimeout(() => void pollDasnHistory(), 2500)
}

async function pollDasnHistory() {
  await loadDasnHistory(true)
  const current = dasnHistory.value?.attempt || null
  const isNewAttempt = current && current.id !== dasnBaselineAttemptId.value
  if (isNewAttempt && !shouldPollMeiAttempt(current)) {
    dasnPolling.value = false
    return
  }
  scheduleDasnPoll()
}

async function consultDasn() {
  const clientId = props.clientId
  if (!clientId || !props.canRefresh || dasnLoading.value || dasnPolling.value) return
  dasnLoading.value = true
  dasnError.value = null
  dasnBaselineAttemptId.value = dasnHistory.value?.attempt?.id || null
  try {
    await api.fiscal.meiPublicServices.dasn.consult({
      client_ids: [clientId],
      calendar_year: dasnYear.value,
      include_full_receipt: includeFullReceipt.value,
      confirmed: true
    })
    dasnPolling.value = true
    dasnPollCount = 0
    toast.add({ title: 'Consulta DASN-SIMEI enfileirada.', color: 'success' })
    scheduleDasnPoll()
  } catch (caught) {
    dasnError.value = apiErrorMessage(caught, 'Não foi possível solicitar a consulta DASN-SIMEI.')
  } finally {
    dasnLoading.value = false
  }
}

function coverageMeta(coverage: MeiCoverage | string | null | undefined) {
  return meiCoverageMeta(coverage)
}

function certificateDownloadPath(certificateId: number): string | undefined {
  return props.clientId
    ? api.fiscal.ccmei.issuedCertificates.downloadPath(props.clientId, certificateId)
    : undefined
}

watch(
  () => [props.open, props.clientId, sessionEpoch.value] as const,
  ([open, clientId], previous) => {
    const clientChanged = previous && clientId !== previous[1]
    if (!open || clientChanged) resetState()
    if (open && clientId) {
      if (activeService.value === 'dasn') void loadDasnHistory()
      else void loadCcmei()
    }
  },
  { immediate: true }
)

watch(activeService, (service) => {
  if (!props.open || !props.clientId) return
  if (service === 'ccmei') void loadCcmei()
  else void loadDasnHistory()
})

watch(dasnYear, () => {
  if (props.open && activeService.value === 'dasn') void loadDasnHistory()
})

onBeforeUnmount(clearTimers)
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Consultas complementares MEI"
    :description="`${clientName || `Cliente #${clientId || '—'}`} · CNPJ ${cnpjMasked || '—'}`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-4xl"
    test-id="mei-public-services-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="min-w-0 space-y-4">
        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-eye"
          title="Workspace estritamente consultivo"
          description="Somente consultas oficiais de leitura e documentos já coletados são exibidos. Emissão de DAS ou de novo certificado CCMEI não está disponível aqui."
        />

        <ShellScrollableTabs
          v-model="activeService"
          :items="serviceTabs"
          size="sm"
          variant="pill"
          aria-label="Selecionar consulta complementar MEI"
          test-id="mei-public-services-tabs"
        />

        <section v-if="activeService === 'ccmei'" class="space-y-4" data-testid="ccmei-service">
          <UAlert
            v-if="ccmeiError"
            color="warning"
            icon="i-lucide-triangle-alert"
            :title="ccmeiError"
          />
          <ShellLoadingModalBody v-if="ccmeiLoading && !ccmeiHistory && !registrationHistory" :rows="3" />

          <div v-else class="grid gap-4 md:grid-cols-2">
            <UCard>
              <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <h3 class="font-medium text-highlighted">
                      Dados CCMEI
                    </h3>
                    <p class="text-xs text-muted">
                      Snapshot sanitizado de DADOSCCMEI.
                    </p>
                  </div>
                  <MonitoringQueryStateBadge :state="ccmeiState" />
                </div>
              </template>
              <dl v-if="ccmeiHistory?.current" class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Situação
                  </dt><dd class="font-medium">
                    {{ ccmeiHistory.current.situation || '—' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Status
                  </dt><dd>{{ ccmeiHistory.current.status || '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Observado em
                  </dt><dd>{{ formatDateTime(ccmeiHistory.current.last_valid_query_at) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Fonte
                  </dt><dd>{{ ccmeiHistory.current.source_provenance || ccmeiHistory.provenance?.source || '—' }}</dd>
                </div>
              </dl>
              <p v-else class="text-sm text-muted">
                Nenhum snapshot CCMEI armazenado.
              </p>
              <template v-if="canRefresh" #footer>
                <UButton
                  size="sm"
                  color="primary"
                  variant="soft"
                  icon="i-lucide-refresh-cw"
                  label="Consultar dados"
                  :loading="ccmeiLoading"
                  @click="consultCcmei('summary')"
                />
              </template>
            </UCard>

            <UCard>
              <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <h3 class="font-medium text-highlighted">
                      Situação cadastral
                    </h3>
                    <p class="text-xs text-muted">
                      Enquadramento e ocorrências cadastrais.
                    </p>
                  </div>
                  <MonitoringQueryStateBadge :state="registrationState" />
                </div>
              </template>
              <dl v-if="registrationHistory?.current" class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Enquadrado MEI
                  </dt><dd class="font-medium">
                    {{ registrationHistory.current.enquadrado_mei ? 'Sim' : 'Não' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Situação
                  </dt><dd>{{ registrationHistory.current.situation || '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Ocorrências
                  </dt><dd>{{ registrationHistory.current.count }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Observado em
                  </dt><dd>{{ formatDateTime(registrationHistory.current.observed_at) }}</dd>
                </div>
              </dl>
              <p v-else class="text-sm text-muted">
                Nenhuma situação cadastral armazenada.
              </p>
              <template v-if="canRefresh" #footer>
                <UButton
                  size="sm"
                  color="primary"
                  variant="soft"
                  icon="i-lucide-refresh-cw"
                  label="Consultar situação"
                  :loading="ccmeiLoading"
                  @click="consultCcmei('registration')"
                />
              </template>
            </UCard>
          </div>

          <section class="space-y-2">
            <div>
              <h3 class="text-sm font-medium text-highlighted">
                Certificados CCMEI já coletados
              </h3>
              <p class="text-xs text-muted">
                A lista não gera novo certificado.
              </p>
            </div>
            <ul v-if="certificateHistory?.certificates?.length" class="divide-y divide-default rounded-lg border border-default">
              <li v-for="certificate in certificateHistory.certificates" :key="certificate.id" class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                <span>Certificado #{{ certificate.id }} · {{ formatDateTime(certificate.observed_at) }} · {{ certificate.source_provenance || 'fonte local' }}</span>
                <UButton
                  size="xs"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  label="Baixar certificado existente"
                  :to="certificateDownloadPath(certificate.id)"
                  external
                  target="_blank"
                  rel="noopener noreferrer"
                />
              </li>
            </ul>
            <p v-else class="text-sm text-muted">
              Nenhum certificado CCMEI coletado.
            </p>
          </section>
        </section>

        <section v-else class="space-y-4" data-testid="mei-dasn-service">
          <div class="flex flex-wrap items-end gap-3">
            <UFormField label="Ano-calendário" name="dasn-year">
              <UInputNumber
                v-model="dasnYear"
                :min="2009"
                :max="2100"
                :format-options="{ useGrouping: false }"
                class="w-36"
              />
            </UFormField>
            <UCheckbox
              v-model="includeFullReceipt"
              label="Buscar recibo integral quando disponível"
              class="min-h-8 pb-1"
              :disabled="!canRefresh"
            />
            <div class="ml-auto flex items-center gap-2">
              <MonitoringQueryStateBadge :state="dasnState" />
              <UButton
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="outline"
                aria-label="Atualizar histórico DASN-SIMEI"
                :loading="dasnLoading"
                @click="loadDasnHistory()"
              />
              <UButton
                v-if="canRefresh"
                icon="i-lucide-search"
                color="primary"
                label="Consultar"
                :loading="dasnLoading || dasnPolling"
                @click="consultDasn"
              />
            </div>
          </div>

          <UAlert
            v-if="dasnError"
            color="error"
            icon="i-lucide-circle-x"
            :title="dasnError"
          />
          <UProgress v-if="dasnPolling" animation="carousel" aria-label="Consulta DASN-SIMEI em processamento" />
          <ShellLoadingModalBody v-if="dasnLoading && !dasnHistory" :rows="2" />

          <div v-else-if="dasnHistory?.declarations.length" class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
              <thead class="text-xs text-muted">
                <tr>
                  <th class="pb-2 pr-3 font-medium">
                    Ano
                  </th><th class="pb-2 pr-3 font-medium">
                    Situação
                  </th><th class="pb-2 pr-3 font-medium">
                    Transmissão
                  </th><th class="pb-2 pr-3 font-medium">
                    Cobertura
                  </th><th class="pb-2 text-right font-medium">
                    Evidência
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="declaration in dasnHistory.declarations" :key="`${declaration.calendar_year}-${declaration.transmitted_at || declaration.status}`" class="border-t border-default">
                  <td class="py-3 pr-3 tabular-nums">
                    {{ declaration.calendar_year }}
                  </td>
                  <td class="py-3 pr-3 font-medium">
                    {{ declaration.status }}
                  </td>
                  <td class="py-3 pr-3">
                    {{ formatDateTime(declaration.transmitted_at) }}
                  </td>
                  <td class="py-3 pr-3">
                    <UBadge :color="coverageMeta(declaration.coverage).color" :label="coverageMeta(declaration.coverage).label" variant="subtle" />
                  </td>
                  <td class="py-3 text-right">
                    <UButton
                      v-if="hasIntegralDasnReceipt(declaration.coverage, declaration.receipt_available) && declaration.artifact?.href"
                      icon="i-lucide-download"
                      color="neutral"
                      variant="outline"
                      size="xs"
                      label="Recibo"
                      :to="declaration.artifact.href"
                      external
                      target="_blank"
                      rel="noopener noreferrer"
                    />
                    <span v-else class="text-xs text-muted">Resumo público</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <MonitoringTableEmptyState v-else-if="!dasnLoading" :title="`Nenhuma declaração armazenada para ${dasnYear}`" />
        </section>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter cancel-label="Fechar" :show-submit="false" @cancel="emit('update:open', false)" />
    </template>
  </ShellScrollableModal>
</template>
