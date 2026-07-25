<script setup lang="ts">
import type { CcmeiIssuedCertificate, CcmeiIssuedCertificateHistoryPayload } from '~/types/fiscal-modules'
import { useCcmeiCertificateIssuance } from '~/composables/useCcmeiCertificateIssuance'
import { formatDateTime } from '~/utils/format'

const props = withDefaults(defineProps<{
  clientId: number
  canConsult: boolean
  /** Quando false, o alerta bilhetável fica no wrapper da página. */
  showBillingAlert?: boolean
}>(), {
  showBillingAlert: true
})
const toast = useToast()
const { fetchHistory, requestIssue, downloadPath } = useCcmeiCertificateIssuance()
const loading = ref(true)
const issuing = ref(false)
const issueConfirmOpen = ref(false)
const error = ref<string | null>(null)
const history = ref<CcmeiIssuedCertificateHistoryPayload | null>(null)
let generation = 0

const certificates = computed(() => history.value?.certificates || [])

function provenanceLabel(value?: string | null): string {
  return value === 'SERPRO_TRIAL'
    ? 'Demonstração SERPRO (Trial)'
    : value === 'SERPRO_REAL'
      ? 'SERPRO real — pendente de canário'
      : 'Origem não informada'
}

async function load() {
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const payload = await fetchHistory(props.clientId)
    if (requestGeneration === generation) history.value = payload
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar os certificados emitidos.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

function openIssueConfirmation() {
  if (!props.canConsult || issuing.value) return
  issueConfirmOpen.value = true
}

async function confirmIssue() {
  if (!props.canConsult || issuing.value) return
  issuing.value = true
  try {
    const result = await requestIssue(props.clientId)
    if (!result.success) throw new Error(result.error_message || 'Não foi possível emitir o certificado.')
    toast.add({ title: 'Emissão de certificado solicitada', color: 'success', icon: 'i-lucide-file-check-2' })
    await load()
    issueConfirmOpen.value = false
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível emitir o certificado CCMEI.'), color: 'error' })
  } finally {
    issuing.value = false
  }
}

function download(certificate: CcmeiIssuedCertificate) {
  window.location.assign(downloadPath(props.clientId, certificate.id))
}

watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <UPageCard
    title="Certificado CCMEI"
    description="Emissão manual e histórico local de certificados, sem expor dados fiscais ou o arquivo no painel."
    icon="i-lucide-file-badge-2"
    variant="subtle"
    data-testid="client-ccmei-certificate-issuance-panel"
  >
    <div class="space-y-4">
      <UAlert
        v-if="showBillingAlert"
        color="warning"
        icon="i-lucide-circle-alert"
        title="Emissão potencialmente bilhetável"
      />
      <p class="text-sm text-muted">
        A emissão só começa após sua ação explícita. O resultado fica disponível no histórico quando o serviço responder.
      </p>

      <UAlert
        v-if="!canConsult"
        color="neutral"
        icon="i-lucide-lock"
        title="Emissão indisponível para seu perfil"
      />
      <p v-if="!canConsult" class="text-sm text-muted">
        Você pode consultar e baixar certificados já registrados para este cliente.
      </p>

      <UAlert
        v-if="error"
        color="error"
        icon="i-lucide-circle-x"
        title="Histórico indisponível"
      >
        <template #actions>
          <UButton
            size="xs"
            color="neutral"
            variant="outline"
            label="Tentar novamente"
            @click="load"
          />
        </template>
      </UAlert>
      <p v-if="error" class="text-sm text-error">
        {{ error }}
      </p>

      <div v-else-if="loading" class="space-y-3" aria-label="Carregando certificados CCMEI">
        <USkeleton class="h-8 w-52" />
        <USkeleton class="h-20 w-full" />
      </div>
      <UEmpty
        v-else-if="!certificates.length"
        icon="i-lucide-file-x-2"
        title="Nenhum certificado emitido"
        description="Ainda não há certificado CCMEI registrado para este cliente."
        :ui="{ root: 'py-4' }"
      />
      <ul v-else class="space-y-2" aria-label="Certificados emitidos">
        <li
          v-for="certificate in certificates"
          :key="certificate.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default p-3"
        >
          <div>
            <p class="font-medium text-highlighted">
              Certificado emitido
            </p>
            <p class="text-xs text-muted">
              {{ formatDateTime(certificate.observed_at) }} · {{ provenanceLabel(certificate.source_provenance) }}
            </p>
          </div>
          <UButton
            color="neutral"
            variant="soft"
            icon="i-lucide-download"
            label="Baixar PDF"
            @click="download(certificate)"
          />
        </li>
      </ul>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar histórico"
          :loading="loading"
          @click="load"
        />
        <UButton
          v-if="canConsult"
          color="primary"
          icon="i-lucide-file-plus-2"
          label="Emitir certificado"
          :loading="issuing"
          @click="openIssueConfirmation"
        />
      </div>
    </template>
  </UPageCard>

  <ShellConfirmModal
    v-model:open="issueConfirmOpen"
    title="Confirmar emissão do CCMEI"
    description="A emissão será solicitada para este cliente e poderá consumir uma consulta do serviço."
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    confirm-label="Confirmar emissão"
    confirm-icon="i-lucide-check"
    :loading="issuing"
    @confirm="confirmIssue"
  >
    <template #body>
      <div class="space-y-3">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Emissão manual"
        />
        <p class="text-sm text-muted">
          O certificado comprova a condição atual do MEI. A solicitação só será enviada depois da confirmação abaixo; o PDF ficará disponível no histórico local quando houver resposta.
        </p>
      </div>
    </template>
  </ShellConfirmModal>
</template>
