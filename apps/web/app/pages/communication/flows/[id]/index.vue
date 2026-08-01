<script setup lang="ts">
/**
 * Detalhe de fluxo — metadados, versões, bindings, runs e atalho ao editor visual.
 * Arquétipo: settings/detalhe. Análogo: communication/contacts/[id].vue.
 * Cascas: ShellPagePanel, ShellPageNavbar, ShellSection*, ShellConfirmModal.
 * Canvas Vue Flow fica em /editor.
 */
import type { Inbox } from '~/types/communication/inboxes'
import type { Flow, FlowBinding, FlowRun, FlowVersion } from '~/types/communication/flows'
import { apiErrorCode, apiErrorMessage } from '~/utils/api-error'
import {
  communicationFlowStatusColor,
  communicationFlowStatusLabel,
  communicationFlowsMutationBlocked,
  formatFlowGraphJson,
  parseFlowGraphJson
} from '~/utils/communication-flows'
import {
  COMMUNICATION_FLOWS_PATH,
  communicationFlowEditorPath,
  parseCommunicationFlowId
} from '~/utils/communication-routes'
import {
  canManageCommunicationFlows,
  canViewCommunication
} from '~/utils/permissions'

const api = useApi()
const route = useRoute()
const toast = useToast()
const { me, sessionEpoch } = useDashboard()

const canView = computed(() => canViewCommunication(me.value))
const canManage = computed(() => canManageCommunicationFlows(me.value))

if (!canView.value) {
  await navigateTo('/')
}

const flowId = computed(() => parseCommunicationFlowId(route.params.id))
const flow = ref<Flow | null>(null)
const flowsEnabled = ref(true)
const loading = ref(false)
const loadError = ref<string | null>(null)

const editName = ref('')
const editStatus = ref<'paused' | 'active'>('paused')
const metaBusy = ref(false)
const metaError = ref<string | null>(null)

const draftJson = ref('')
const draftLockVersion = ref(1)
const draftDigest = ref('')
const draftBusy = ref(false)
const draftError = ref<string | null>(null)
const validateBusy = ref(false)
const validateMessage = ref<string | null>(null)
const validateOk = ref(false)

const publishOpen = ref(false)
const publishBusy = ref(false)
const enableOpen = ref(false)
const enableBusy = ref(false)
const enableTarget = ref<FlowBinding | null>(null)

const inboxes = ref<Inbox[]>([])
const bindingInboxId = ref<number | undefined>(undefined)
const bindingVersionId = ref<number | undefined>(undefined)
const bindingBusy = ref(false)
const bindingError = ref<string | null>(null)
const bindingActionKey = ref<string | null>(null)

/** Runs observáveis do fluxo (carregados via GET /flow-runs?flow_id=). */
const observedRuns = ref<FlowRun[]>([])
const runsLoading = ref(false)
const runsError = ref<string | null>(null)
const runsActiveOnly = ref(false)
const runActionKey = ref<string | null>(null)
const showAdvancedJson = ref(false)

const mutationBlocked = computed(() =>
  communicationFlowsMutationBlocked(flowsEnabled.value, canManage.value)
)

const versions = computed<FlowVersion[]>(() => flow.value?.versions ?? [])
const bindings = computed<FlowBinding[]>(() => flow.value?.bindings ?? [])

const inboxItems = computed(() =>
  inboxes.value.map(inbox => ({
    label: inbox.name,
    value: inbox.id
  }))
)

const versionItems = computed(() =>
  versions.value.map(version => ({
    label: `v${version.version}`,
    value: version.id
  }))
)

const inboxNameById = computed(() => {
  const map = new Map<number, string>()
  for (const inbox of inboxes.value) map.set(inbox.id, inbox.name)
  return map
})

function applyFlow(next: Flow) {
  flow.value = next
  editName.value = next.name
  editStatus.value = next.status
  if (next.draft) {
    draftJson.value = formatFlowGraphJson(next.draft.graph)
    draftLockVersion.value = next.draft.lock_version
    draftDigest.value = next.draft.graph_digest
  } else {
    draftJson.value = formatFlowGraphJson(null)
    draftLockVersion.value = 1
    draftDigest.value = ''
  }
}

async function loadInboxes() {
  try {
    const res = await api.communication.inboxes.list()
    inboxes.value = res.data
  } catch {
    inboxes.value = []
  }
}

async function loadRuns() {
  const id = flowId.value
  if (!id) {
    observedRuns.value = []
    return
  }
  runsLoading.value = true
  runsError.value = null
  try {
    const res = await api.communication.flows.listRuns({
      flow_id: id,
      active_only: runsActiveOnly.value || undefined,
      per_page: 50
    })
    observedRuns.value = res.data
  } catch (caught) {
    observedRuns.value = []
    runsError.value = apiErrorMessage(caught, 'Falha ao listar runs.')
  } finally {
    runsLoading.value = false
  }
}

async function load() {
  const id = flowId.value
  if (!id) {
    loadError.value = 'Fluxo inválido.'
    flow.value = null
    return
  }
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const [detail, list] = await Promise.all([
      api.communication.flows.get(id),
      api.communication.flows.list()
    ])
    if (epoch !== sessionEpoch.value) return
    flowsEnabled.value = Boolean(list.meta?.flows_enabled)
    applyFlow(detail.data)
    validateMessage.value = null
    validateOk.value = false
    await Promise.all([loadInboxes(), loadRuns()])
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    flow.value = null
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar o fluxo.')
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

async function saveMetadata() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    metaError.value = blocked
    return
  }
  const name = editName.value.trim()
  if (name.length < 2) {
    metaError.value = 'Informe um nome com pelo menos 2 caracteres.'
    return
  }
  metaBusy.value = true
  metaError.value = null
  try {
    const res = await api.communication.flows.update(flow.value.id, {
      name,
      status: editStatus.value,
      lock_version: flow.value.lock_version
    })
    applyFlow({
      ...flow.value,
      ...res.data,
      draft: flow.value.draft,
      versions: flow.value.versions,
      bindings: flow.value.bindings
    })
    toast.add({ title: 'Metadados salvos.', color: 'success' })
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    metaError.value = apiErrorMessage(caught, 'Falha ao salvar metadados.')
    toast.add({ title: metaError.value, color: 'error' })
  } finally {
    metaBusy.value = false
  }
}

async function saveDraft() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    draftError.value = blocked
    return
  }
  const parsed = parseFlowGraphJson(draftJson.value)
  if (!parsed.ok) {
    draftError.value = parsed.message
    return
  }
  draftBusy.value = true
  draftError.value = null
  try {
    const res = await api.communication.flows.updateDraft(flow.value.id, {
      graph: parsed.graph,
      lock_version: draftLockVersion.value
    })
    draftLockVersion.value = res.data.lock_version
    draftDigest.value = res.data.graph_digest
    draftJson.value = formatFlowGraphJson(res.data.graph)
    if (flow.value.draft) {
      flow.value = {
        ...flow.value,
        draft: res.data
      }
    }
    validateOk.value = false
    validateMessage.value = null
    toast.add({ title: 'Draft salvo.', color: 'success' })
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    draftError.value = apiErrorMessage(caught, 'Falha ao salvar draft.')
    toast.add({ title: draftError.value, color: 'error' })
  } finally {
    draftBusy.value = false
  }
}

async function validateDraft() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    validateMessage.value = blocked
    validateOk.value = false
    return
  }
  const parsed = parseFlowGraphJson(draftJson.value)
  if (!parsed.ok) {
    validateMessage.value = parsed.message
    validateOk.value = false
    return
  }
  validateBusy.value = true
  validateMessage.value = null
  validateOk.value = false
  try {
    const res = await api.communication.flows.validate(flow.value.id, { graph: parsed.graph })
    validateOk.value = true
    validateMessage.value = `Grafo válido. Digest: ${res.data.graph_digest}`
    toast.add({ title: 'Grafo válido.', color: 'success' })
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    validateOk.value = false
    validateMessage.value = apiErrorMessage(caught, 'Grafo inválido.')
    toast.add({ title: validateMessage.value, color: 'error' })
  } finally {
    validateBusy.value = false
  }
}

function openPublish() {
  if (mutationBlocked.value) {
    toast.add({ title: mutationBlocked.value, color: 'warning' })
    return
  }
  publishOpen.value = true
}

async function onPublishConfirm() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    if (blocked) toast.add({ title: blocked, color: 'warning' })
    return
  }
  publishBusy.value = true
  try {
    // Persiste o JSON atual antes de publicar para evitar publish de draft stale na UI.
    const parsed = parseFlowGraphJson(draftJson.value)
    if (!parsed.ok) {
      toast.add({ title: parsed.message, color: 'error' })
      return
    }
    const draftRes = await api.communication.flows.updateDraft(flow.value.id, {
      graph: parsed.graph,
      lock_version: draftLockVersion.value
    })
    draftLockVersion.value = draftRes.data.lock_version
    draftDigest.value = draftRes.data.graph_digest

    const res = await api.communication.flows.publish(flow.value.id, {
      lock_version: draftLockVersion.value
    })
    toast.add({
      title: `Versão v${res.data.version.version} publicada.`,
      description: 'Publicar não habilita bindings.',
      color: 'success'
    })
    publishOpen.value = false
    await load()
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao publicar fluxo.'),
      color: 'error'
    })
  } finally {
    publishBusy.value = false
  }
}

async function createBinding() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value || bindingInboxId.value == null) {
    bindingError.value = blocked || 'Selecione uma inbox.'
    return
  }
  bindingBusy.value = true
  bindingError.value = null
  try {
    await api.communication.flows.createBinding(flow.value.id, {
      inbox_id: bindingInboxId.value,
      published_version_id: bindingVersionId.value ?? null,
      enabled: false
    })
    toast.add({ title: 'Binding criado (desabilitado).', color: 'success' })
    bindingInboxId.value = undefined
    bindingVersionId.value = undefined
    await load()
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    bindingError.value = apiErrorMessage(caught, 'Falha ao criar binding.')
    toast.add({ title: bindingError.value, color: 'error' })
  } finally {
    bindingBusy.value = false
  }
}

function openEnable(binding: FlowBinding) {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    toast.add({ title: blocked || 'Sem permissão.', color: 'warning' })
    return
  }
  if (!binding.published_version_id && !versions.value.length) {
    toast.add({
      title: 'Publique uma versão antes de habilitar o binding.',
      color: 'warning'
    })
    return
  }
  enableTarget.value = binding
  enableOpen.value = true
}

async function onEnableConfirm() {
  const binding = enableTarget.value
  const blocked = mutationBlocked.value
  if (blocked || !flow.value || !binding) {
    if (blocked) toast.add({ title: blocked, color: 'warning' })
    return
  }
  const versionId = binding.published_version_id
    ?? versions.value[versions.value.length - 1]?.id
    ?? null
  if (versionId == null) {
    toast.add({ title: 'Versão publicada obrigatória.', color: 'warning' })
    return
  }
  enableBusy.value = true
  bindingActionKey.value = `${binding.id}:enable`
  try {
    await api.communication.flows.enableBinding(binding.id, {
      lock_version: binding.lock_version,
      published_version_id: versionId
    })
    toast.add({ title: 'Binding habilitado.', color: 'success' })
    enableOpen.value = false
    enableTarget.value = null
    await load()
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao habilitar binding.'),
      color: 'error'
    })
  } finally {
    enableBusy.value = false
    bindingActionKey.value = null
  }
}

async function toggleBinding(binding: FlowBinding, enabled: boolean) {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    toast.add({ title: blocked || 'Sem permissão.', color: 'warning' })
    return
  }
  if (enabled) {
    openEnable(binding)
    return
  }
  const key = `${binding.id}:disable`
  bindingActionKey.value = key
  try {
    await api.communication.flows.disableBinding(binding.id, {
      lock_version: binding.lock_version
    })
    toast.add({ title: 'Binding desabilitado.', color: 'success' })
    await load()
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao atualizar binding.'),
      color: 'error'
    })
  } finally {
    bindingActionKey.value = null
  }
}

async function controlRun(
  run: FlowRun,
  action: 'pause' | 'resume' | 'handoff' | 'stop' | 'restart'
) {
  const blocked = mutationBlocked.value
  if (blocked) {
    toast.add({ title: blocked, color: 'warning' })
    return
  }
  runActionKey.value = `${run.id}:${action}`
  try {
    const apiAction = {
      pause: api.communication.flows.pauseRun,
      resume: api.communication.flows.resumeRun,
      handoff: api.communication.flows.handoffRun,
      stop: api.communication.flows.stopRun,
      restart: api.communication.flows.restartRun
    }[action]
    const res = await apiAction(run.id)
    if (action === 'restart') {
      await loadRuns()
    } else {
      observedRuns.value = observedRuns.value.map(item =>
        item.id === run.id ? res.data : item
      )
    }
    toast.add({ title: `Run ${action} aplicado.`, color: 'success' })
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao controlar run.'),
      color: 'error'
    })
  } finally {
    runActionKey.value = null
  }
}

function formatRunStatus(status: string): string {
  return status.replaceAll('_', ' ')
}

function versionLabel(versionId: number | null): string {
  if (versionId == null) return '—'
  const found = versions.value.find(item => item.id === versionId)
  return found ? `v${found.version}` : `#${versionId}`
}

function formatPublishedAt(value?: string | null): string {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(new Date(value))
  } catch {
    return value
  }
}

watch(flowId, () => {
  void load()
})

watch(sessionEpoch, () => {
  flow.value = null
  void load()
})

watch(runsActiveOnly, () => {
  void loadRuns()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <ShellPagePanel
    id="communication-flow-detail"
    data-testid="communication-flow-detail-panel"
  >
    <template #header>
      <ShellPageNavbar :title="flow?.name || 'Fluxo'">
        <template #leading>
          <ShellNavbarBack
            :to="COMMUNICATION_FLOWS_PATH"
            label="Fluxos"
            aria-label="Voltar para fluxos"
            test-id="communication-flow-back"
          />
        </template>
        <template #right>
          <div
            v-if="flow"
            class="flex flex-wrap gap-2"
          >
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-workflow"
              label="Editor visual"
              :to="communicationFlowEditorPath(flow.id)"
              data-testid="communication-flow-open-editor"
            />
            <UButton
              v-if="canManage"
              icon="i-lucide-upload"
              label="Publicar"
              data-testid="communication-flow-publish"
              :disabled="Boolean(mutationBlocked)"
              @click="openPublish"
            />
          </div>
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <h1
        data-testid="page-title"
        class="sr-only"
      >
        {{ flow?.name || 'Detalhe do fluxo' }}
      </h1>

      <UAlert
        v-if="!flowsEnabled"
        class="mb-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-off"
        title="Engine de fluxos desabilitada"
        description="Mutações (draft, validate, publish e bindings) permanecem bloqueadas enquanto a flag estiver OFF."
        data-testid="communication-flow-disabled-alert"
      />

      <ShellLoadError
        v-if="loadError && !flow"
        :title="loadError"
        test-id="communication-flow-load-error"
        @retry="load"
      />

      <div
        v-else-if="loading && !flow"
        class="space-y-4"
        data-testid="communication-flow-loading"
      >
        <USkeleton class="h-28 w-full rounded-lg" />
        <USkeleton class="h-48 w-full rounded-lg" />
        <USkeleton class="h-40 w-full rounded-lg" />
      </div>

      <div
        v-else-if="flow"
        class="mx-auto flex w-full max-w-3xl flex-col gap-6"
      >
        <section>
          <ShellSectionHeader
            title="Metadados"
            description="Nome e situação do fluxo no escritório. Novo fluxo inicia pausado."
          >
            <UBadge
              size="md"
              variant="subtle"
              :color="communicationFlowStatusColor(flow)"
              :label="communicationFlowStatusLabel(flow)"
            />
          </ShellSectionHeader>
          <ShellSectionCard>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Nome"
                name="name"
                class="sm:col-span-2"
                required
              >
                <UInput
                  v-model="editName"
                  :disabled="Boolean(mutationBlocked)"
                  class="w-full"
                  data-testid="communication-flow-name"
                />
              </UFormField>
              <UFormField
                label="Situação"
                name="status"
              >
                <USelect
                  v-model="editStatus"
                  :items="[
                    { label: 'Pausado', value: 'paused' },
                    { label: 'Ativo', value: 'active' }
                  ]"
                  :disabled="Boolean(mutationBlocked)"
                  class="w-full"
                  data-testid="communication-flow-status"
                />
              </UFormField>
              <UFormField
                label="lock_version"
                name="lock_version"
              >
                <UInput
                  :model-value="String(flow.lock_version)"
                  disabled
                  class="w-full font-mono"
                />
              </UFormField>
            </div>
            <UAlert
              v-if="metaError"
              class="mt-4"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-x"
              :title="metaError"
            />
            <div
              v-if="canManage"
              class="mt-4 flex justify-end"
            >
              <UButton
                icon="i-lucide-save"
                label="Salvar metadados"
                :loading="metaBusy"
                :disabled="Boolean(mutationBlocked)"
                data-testid="communication-flow-save-meta"
                @click="saveMetadata"
              />
            </div>
            <UAlert
              v-else
              class="mt-4"
              color="neutral"
              variant="subtle"
              icon="i-lucide-lock"
              title="Somente leitura"
              description="É necessária a permissão communication.manage_flows para alterar este fluxo."
            />
          </ShellSectionCard>
        </section>

        <section>
          <ShellSectionHeader
            title="Draft do grafo"
            description="Monte o robô no editor visual. Validação e publicação usam o draft salvo no servidor."
          >
            <div class="flex flex-wrap gap-2">
              <UButton
                color="primary"
                icon="i-lucide-workflow"
                label="Abrir editor visual"
                :to="communicationFlowEditorPath(flow.id)"
                data-testid="communication-flow-open-editor-section"
              />
              <UButton
                v-if="canManage"
                color="neutral"
                variant="outline"
                icon="i-lucide-shield-check"
                label="Validar"
                :loading="validateBusy"
                :disabled="Boolean(mutationBlocked)"
                data-testid="communication-flow-validate"
                @click="validateDraft"
              />
              <UButton
                v-if="canManage"
                color="neutral"
                variant="outline"
                icon="i-lucide-save"
                label="Salvar draft JSON"
                :loading="draftBusy"
                :disabled="Boolean(mutationBlocked) || !showAdvancedJson"
                data-testid="communication-flow-save-draft"
                @click="saveDraft"
              />
            </div>
          </ShellSectionHeader>
          <ShellSectionCard>
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm text-muted">
                  Preferência: editar no canvas Vue Flow. O JSON avançado fica opcional para inspeção ou ajuste fino.
                </p>
                <p
                  v-if="draftDigest"
                  class="mt-2 font-mono text-xs text-muted"
                  data-testid="communication-flow-draft-digest"
                >
                  digest {{ draftDigest }} · lock {{ draftLockVersion }}
                </p>
              </div>
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                :icon="showAdvancedJson ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                :label="showAdvancedJson ? 'Ocultar JSON' : 'JSON avançado'"
                data-testid="communication-flow-toggle-json"
                @click="() => { showAdvancedJson = !showAdvancedJson }"
              />
            </div>
            <div
              v-if="showAdvancedJson"
              class="mt-4"
            >
              <UFormField
                label="Grafo (JSON avançado)"
                name="graph"
              >
                <UTextarea
                  v-model="draftJson"
                  :rows="12"
                  :disabled="Boolean(mutationBlocked)"
                  class="w-full font-mono text-xs"
                  data-testid="communication-flow-draft-json"
                  aria-label="Editor JSON do grafo do draft"
                />
              </UFormField>
            </div>
            <UAlert
              v-if="draftError"
              class="mt-4"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-x"
              :title="draftError"
            />
            <UAlert
              v-if="validateMessage"
              class="mt-4"
              :color="validateOk ? 'success' : 'error'"
              variant="subtle"
              :icon="validateOk ? 'i-lucide-check-circle' : 'i-lucide-circle-x'"
              :title="validateMessage"
              data-testid="communication-flow-validate-result"
            />
          </ShellSectionCard>
        </section>

        <section>
          <ShellSectionHeader
            title="Versões publicadas"
            description="Histórico imutável com digest. Publicar não habilita bindings."
          />
          <ShellSectionCard>
            <UEmpty
              v-if="!versions.length"
              icon="i-lucide-history"
              title="Nenhuma versão"
              description="Valide o draft e publique para criar a primeira versão."
              class="py-6"
            />
            <ul
              v-else
              class="divide-y divide-default rounded-lg border border-default"
              data-testid="communication-flow-versions"
            >
              <li
                v-for="version in versions"
                :key="version.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-highlighted">
                    v{{ version.version }}
                  </p>
                  <p class="font-mono text-xs text-muted">
                    {{ version.graph_digest }}
                  </p>
                </div>
                <span class="text-xs text-muted">
                  {{ formatPublishedAt(version.published_at) }}
                </span>
              </li>
            </ul>
          </ShellSectionCard>
        </section>

        <section>
          <ShellSectionHeader
            title="Runs"
            description="Execuções deste fluxo na conversa. Com binding habilitado e runtime ON, novos runs aparecem aqui."
          >
            <div class="flex flex-wrap items-center gap-2">
              <UCheckbox
                v-model="runsActiveOnly"
                label="Só ativos"
                data-testid="communication-flow-runs-active-only"
              />
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                icon="i-lucide-refresh-cw"
                aria-label="Atualizar runs"
                :loading="runsLoading"
                data-testid="communication-flow-runs-refresh"
                @click="loadRuns"
              />
            </div>
          </ShellSectionHeader>
          <ShellSectionCard>
            <UAlert
              v-if="runsError"
              class="mb-4"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-x"
              :title="runsError"
              data-testid="communication-flow-runs-error"
            />
            <div
              v-if="runsLoading && !observedRuns.length"
              class="space-y-2"
              data-testid="communication-flow-runs-loading"
            >
              <USkeleton class="h-12 w-full rounded-lg" />
              <USkeleton class="h-12 w-full rounded-lg" />
            </div>
            <UEmpty
              v-else-if="!observedRuns.length"
              icon="i-lucide-activity"
              title="Nenhum run ainda"
              description="Publique uma versão, habilite o binding na inbox e aguarde mensagens entrantes com a engine ligada."
              class="py-6"
              data-testid="communication-flow-runs-empty"
            />
            <ul
              v-else
              class="divide-y divide-default rounded-lg border border-default"
              data-testid="communication-flow-runs"
            >
              <li
                v-for="run in observedRuns"
                :key="run.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-highlighted">
                    Run #{{ run.id }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ formatRunStatus(String(run.status)) }}
                    · conversa {{ run.conversation_id ?? '—' }}
                    · {{ versionLabel(run.flow_version_id) }}
                    · nó {{ run.current_node_id || '—' }}
                  </p>
                </div>
                <div
                  v-if="canManage"
                  class="flex flex-wrap gap-1"
                >
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="soft"
                    label="Pausar"
                    :loading="runActionKey === `${run.id}:pause`"
                    :disabled="Boolean(mutationBlocked)"
                    @click="controlRun(run, 'pause')"
                  />
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="soft"
                    label="Retomar"
                    :loading="runActionKey === `${run.id}:resume`"
                    :disabled="Boolean(mutationBlocked)"
                    @click="controlRun(run, 'resume')"
                  />
                  <UButton
                    size="xs"
                    color="warning"
                    variant="soft"
                    label="Handoff"
                    :loading="runActionKey === `${run.id}:handoff`"
                    :disabled="Boolean(mutationBlocked)"
                    @click="controlRun(run, 'handoff')"
                  />
                  <UButton
                    size="xs"
                    color="error"
                    variant="soft"
                    label="Parar"
                    :loading="runActionKey === `${run.id}:stop`"
                    :disabled="Boolean(mutationBlocked)"
                    @click="controlRun(run, 'stop')"
                  />
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="soft"
                    label="Reiniciar"
                    :loading="runActionKey === `${run.id}:restart`"
                    :disabled="Boolean(mutationBlocked)"
                    @click="controlRun(run, 'restart')"
                  />
                </div>
              </li>
            </ul>
          </ShellSectionCard>
        </section>

        <section>
          <ShellSectionHeader
            title="Bindings por inbox"
            description="Novo binding inicia desabilitado. No máximo um habilitado por inbox."
          />
          <ShellSectionCard>
            <div
              v-if="canManage"
              class="mb-4 grid gap-3 sm:grid-cols-2"
              data-testid="communication-flow-binding-form"
            >
              <UFormField
                label="Inbox"
                name="inbox_id"
              >
                <USelect
                  v-model="bindingInboxId"
                  :items="inboxItems"
                  placeholder="Selecione"
                  :disabled="Boolean(mutationBlocked)"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Versão (opcional)"
                name="published_version_id"
              >
                <USelect
                  v-model="bindingVersionId"
                  :items="versionItems"
                  placeholder="Nenhuma"
                  :disabled="Boolean(mutationBlocked) || !versionItems.length"
                  class="w-full"
                />
              </UFormField>
              <div class="sm:col-span-2 flex justify-end">
                <UButton
                  icon="i-lucide-link"
                  label="Vincular inbox"
                  :loading="bindingBusy"
                  :disabled="Boolean(mutationBlocked) || bindingInboxId == null"
                  data-testid="communication-flow-binding-create"
                  @click="createBinding"
                />
              </div>
              <UAlert
                v-if="bindingError"
                class="sm:col-span-2"
                color="error"
                variant="subtle"
                icon="i-lucide-circle-x"
                :title="bindingError"
              />
            </div>

            <UEmpty
              v-if="!bindings.length"
              icon="i-lucide-unplug"
              title="Nenhum binding"
              description="Vincule este fluxo a uma inbox do escritório."
              class="py-6"
            />
            <ul
              v-else
              class="divide-y divide-default rounded-lg border border-default"
              data-testid="communication-flow-bindings"
            >
              <li
                v-for="binding in bindings"
                :key="binding.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-highlighted">
                    {{ inboxNameById.get(binding.inbox_id) || `Inbox #${binding.inbox_id}` }}
                  </p>
                  <p class="text-xs text-muted">
                    Versão {{ versionLabel(binding.published_version_id) }}
                    · {{ binding.enabled ? 'Habilitado' : 'Desabilitado' }}
                  </p>
                </div>
                <div
                  v-if="canManage"
                  class="flex gap-1"
                >
                  <UButton
                    v-if="!binding.enabled"
                    size="xs"
                    color="success"
                    variant="soft"
                    label="Habilitar"
                    :loading="bindingActionKey === `${binding.id}:enable`"
                    :disabled="Boolean(mutationBlocked)"
                    :data-testid="`communication-flow-binding-enable-${binding.id}`"
                    @click="openEnable(binding)"
                  />
                  <UButton
                    v-else
                    size="xs"
                    color="neutral"
                    variant="soft"
                    label="Desabilitar"
                    :loading="bindingActionKey === `${binding.id}:disable`"
                    :disabled="Boolean(mutationBlocked)"
                    :data-testid="`communication-flow-binding-disable-${binding.id}`"
                    @click="toggleBinding(binding, false)"
                  />
                </div>
              </li>
            </ul>
          </ShellSectionCard>
        </section>
      </div>

      <ShellConfirmModal
        v-model:open="publishOpen"
        title="Publicar versão?"
        description="O draft atual será materializado em uma versão imutável. Isso não habilita nenhum binding de inbox."
        confirm-label="Publicar"
        tone="neutral"
        :loading="publishBusy"
        test-id="communication-flow-publish-modal"
        @confirm="onPublishConfirm"
      />

      <ShellConfirmModal
        v-model:open="enableOpen"
        title="Habilitar binding?"
        description="A ativação exige versão publicada e no máximo um binding habilitado por inbox. Publicar não habilita automaticamente."
        confirm-label="Habilitar"
        tone="neutral"
        :loading="enableBusy"
        test-id="communication-flow-enable-modal"
        @confirm="onEnableConfirm"
      />
    </template>
  </ShellPagePanel>
</template>
