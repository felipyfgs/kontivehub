<script setup lang="ts">
/**
 * Editor visual de fluxos — `/communication/flows/:id/editor`
 * Arquétipo: settings/detalhe + painéis. Cascas: ShellPagePanel/Navbar/ConfirmModal.
 * Desktop: paleta | canvas Vue Flow | inspector. Mobile: canvas read-only + lista.
 * Sem Pinia.
 */
import type {
  Flow,
  FlowDryRunResult,
  FlowGraphError,
  FlowNodeType,
  FlowPreviewResult
} from '~/types/communication/flows'
import FlowEditorCanvas from '~/components/communication/flows/FlowEditorCanvas.client.vue'
import FlowEditorInspector from '~/components/communication/flows/FlowEditorInspector.vue'
import FlowEditorListMode from '~/components/communication/flows/FlowEditorListMode.vue'
import FlowEditorPalette from '~/components/communication/flows/FlowEditorPalette.vue'
import { apiErrorCode, apiErrorMessage, apiGraphErrors } from '~/utils/api-error'
import { communicationFlowsMutationBlocked } from '~/utils/communication-flows'
import {
  COMMUNICATION_FLOWS_PATH,
  communicationFlowPath,
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

const editor = useFlowEditorDraft()
const saveBusy = ref(false)
const validateBusy = ref(false)
const publishBusy = ref(false)
const dryRunBusy = ref(false)
const previewBusy = ref(false)
const serverErrors = ref<FlowGraphError[]>([])
const validateOk = ref(false)
const validateMessage = ref<string | null>(null)

const publishOpen = ref(false)
const connectOpen = ref(false)
const connectTarget = ref<string | undefined>(undefined)
const dryRunOpen = ref(false)
const previewOpen = ref(false)
const dryRunResult = ref<FlowDryRunResult | null>(null)
const previewResult = ref<FlowPreviewResult | null>(null)
const dryRunContext = reactive({
  contact_name: '',
  conversation_status: 'OPEN',
  last_inbound_text: ''
})

const isMobile = useMediaQuery('(max-width: 1023px)')
const preferredMotion = usePreferredReducedMotion()
const reducedMotion = computed(() => preferredMotion.value === 'reduce')
const listModeForced = ref(false)
const showListMode = computed(() => isMobile.value || listModeForced.value)
const canvasReadOnly = computed(() => isMobile.value || Boolean(mutationBlocked.value))

const mutationBlocked = computed(() =>
  communicationFlowsMutationBlocked(flowsEnabled.value, canManage.value)
)

const allErrors = computed(() => [
  ...editor.clientErrors.value,
  ...serverErrors.value
])

const connectItems = computed(() =>
  editor.graph.value.nodes
    .filter(node => node.id !== editor.selectedNodeId.value)
    .map(node => ({ label: `${node.type} (${node.id})`, value: node.id }))
)

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
    flow.value = detail.data
    if (detail.data.draft) {
      editor.hydrate({
        graph: detail.data.draft.graph,
        lock_version: detail.data.draft.lock_version,
        graph_digest: detail.data.draft.graph_digest
      })
    } else {
      const draft = await api.communication.flows.getDraft(id)
      if (epoch !== sessionEpoch.value) return
      editor.hydrate({
        graph: draft.data.graph,
        lock_version: draft.data.lock_version,
        graph_digest: draft.data.graph_digest
      })
    }
    serverErrors.value = []
    validateOk.value = false
    validateMessage.value = null
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    flow.value = null
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar o editor.')
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

function onAddNode(type: FlowNodeType) {
  if (mutationBlocked.value) {
    toast.add({ title: mutationBlocked.value, color: 'warning' })
    return
  }
  const id = editor.addNode(type)
  if (!id) {
    toast.add({
      title: editor.clientErrors.value[0]?.message || 'Não foi possível inserir o nó.',
      color: 'error'
    })
  }
}

function onGraphUpdate(graph: typeof editor.graph.value) {
  if (mutationBlocked.value || canvasReadOnly.value) return
  editor.setGraph(graph)
}

async function saveDraft(): Promise<boolean> {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    if (blocked) toast.add({ title: blocked, color: 'warning' })
    return false
  }
  saveBusy.value = true
  try {
    const res = await api.communication.flows.updateDraft(flow.value.id, {
      graph: editor.graph.value,
      lock_version: editor.lockVersion.value
    })
    editor.hydrate({
      graph: res.data.graph,
      lock_version: res.data.lock_version,
      graph_digest: res.data.graph_digest
    })
    toast.add({ title: 'Draft salvo.', color: 'success' })
    return true
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    if (apiErrorCode(caught) === 'version_conflict') {
      editor.markConflict()
      toast.add({
        title: 'Conflito de versão (409). Recarregue o draft para continuar.',
        color: 'error'
      })
      return false
    }
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao salvar draft.'),
      color: 'error'
    })
    return false
  } finally {
    saveBusy.value = false
  }
}

async function validateDraft() {
  const blocked = mutationBlocked.value
  if (blocked || !flow.value) {
    validateMessage.value = blocked
    validateOk.value = false
    return
  }
  const localOk = editor.runClientValidate()
  if (!localOk) {
    serverErrors.value = []
    validateOk.value = false
    validateMessage.value = 'Validação local encontrou erros. Corrija antes de enviar ao servidor.'
    toast.add({ title: validateMessage.value, color: 'warning' })
    return
  }
  validateBusy.value = true
  serverErrors.value = []
  try {
    const res = await api.communication.flows.validate(flow.value.id, {
      graph: editor.graph.value
    })
    validateOk.value = true
    validateMessage.value = `Grafo válido. Digest: ${res.data.graph_digest}`
    toast.add({ title: 'Grafo válido.', color: 'success' })
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    validateOk.value = false
    serverErrors.value = apiGraphErrors(caught)
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
  if (!flow.value || mutationBlocked.value) return
  publishBusy.value = true
  try {
    const saved = await saveDraft()
    if (!saved) return
    const res = await api.communication.flows.publish(flow.value.id, {
      lock_version: editor.lockVersion.value
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
    if (apiErrorCode(caught) === 'version_conflict') {
      editor.markConflict()
    }
    serverErrors.value = apiGraphErrors(caught)
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao publicar.'),
      color: 'error'
    })
  } finally {
    publishBusy.value = false
  }
}

async function runDryRun() {
  if (!flow.value || mutationBlocked.value) {
    toast.add({ title: mutationBlocked.value || 'Sem permissão.', color: 'warning' })
    return
  }
  dryRunBusy.value = true
  dryRunResult.value = null
  try {
    const res = await api.communication.flows.dryRun(flow.value.id, {
      graph: editor.graph.value,
      context: {
        contact_name: dryRunContext.contact_name || null,
        conversation_status: dryRunContext.conversation_status || null,
        last_inbound_text: dryRunContext.last_inbound_text || null
      }
    })
    dryRunResult.value = res.data
    dryRunOpen.value = true
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    serverErrors.value = apiGraphErrors(caught)
    toast.add({
      title: apiErrorMessage(caught, 'Falha no dry-run.'),
      color: 'error'
    })
  } finally {
    dryRunBusy.value = false
  }
}

async function runPreview() {
  if (!flow.value || mutationBlocked.value) {
    toast.add({ title: mutationBlocked.value || 'Sem permissão.', color: 'warning' })
    return
  }
  previewBusy.value = true
  previewResult.value = null
  try {
    const res = await api.communication.flows.preview(flow.value.id, {
      graph: editor.graph.value
    })
    previewResult.value = res.data
    previewOpen.value = true
  } catch (caught) {
    if (apiErrorCode(caught) === 'communication_flows_disabled') flowsEnabled.value = false
    toast.add({
      title: apiErrorMessage(caught, 'Falha no preview mascarado.'),
      color: 'error'
    })
  } finally {
    previewBusy.value = false
  }
}

function openConnect() {
  connectTarget.value = undefined
  connectOpen.value = true
}

function confirmConnect() {
  if (!editor.selectedNodeId.value || !connectTarget.value) return
  const ok = editor.connect(editor.selectedNodeId.value, connectTarget.value)
  if (!ok) {
    toast.add({
      title: editor.clientErrors.value[0]?.message || 'Falha ao conectar.',
      color: 'error'
    })
    return
  }
  connectOpen.value = false
}

watch(flowId, () => {
  void load()
})

watch(sessionEpoch, () => {
  flow.value = null
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <ShellPagePanel
    id="communication-flow-editor"
    data-testid="communication-flow-editor-panel"
  >
    <template #header>
      <ShellPageNavbar :title="flow ? `Editor · ${flow.name}` : 'Editor de fluxo'">
        <template #leading>
          <ShellNavbarBack
            :to="flowId ? communicationFlowPath(flowId) : COMMUNICATION_FLOWS_PATH"
            label="Detalhe"
            aria-label="Voltar ao detalhe do fluxo"
            test-id="communication-flow-editor-back"
          />
        </template>
        <template #right>
          <div class="flex flex-wrap items-center gap-2">
            <UButton
              v-if="!isMobile"
              color="neutral"
              variant="ghost"
              :icon="listModeForced ? 'i-lucide-workflow' : 'i-lucide-list'"
              :label="listModeForced ? 'Canvas' : 'Lista'"
              aria-label="Alternar modo lista"
              data-testid="flow-editor-toggle-list"
              @click="() => { listModeForced = !listModeForced }"
            />
            <UButton
              v-if="canManage"
              color="neutral"
              variant="outline"
              icon="i-lucide-eye-off"
              label="Preview"
              :loading="previewBusy"
              :disabled="Boolean(mutationBlocked)"
              aria-label="Preview mascarado do grafo"
              data-testid="flow-editor-preview"
              @click="() => { void runPreview() }"
            />
            <UButton
              v-if="canManage"
              color="neutral"
              variant="outline"
              icon="i-lucide-flask-conical"
              label="Dry-run"
              :loading="dryRunBusy"
              :disabled="Boolean(mutationBlocked)"
              aria-label="Executar dry-run sem egress"
              data-testid="flow-editor-dry-run"
              @click="() => { void runDryRun() }"
            />
            <UButton
              v-if="canManage"
              color="neutral"
              variant="outline"
              icon="i-lucide-shield-check"
              label="Validar"
              :loading="validateBusy"
              :disabled="Boolean(mutationBlocked)"
              aria-label="Validar grafo"
              data-testid="flow-editor-validate"
              @click="() => { void validateDraft() }"
            />
            <UButton
              v-if="canManage"
              icon="i-lucide-save"
              label="Salvar"
              :loading="saveBusy"
              :disabled="Boolean(mutationBlocked)"
              aria-label="Salvar draft"
              data-testid="flow-editor-save"
              @click="() => { void saveDraft() }"
            />
            <UButton
              v-if="canManage"
              icon="i-lucide-upload"
              label="Publicar"
              :disabled="Boolean(mutationBlocked)"
              aria-label="Publicar versão"
              data-testid="flow-editor-publish"
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
        Editor visual do fluxo
      </h1>

      <UAlert
        v-if="!flowsEnabled"
        class="mb-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-off"
        title="Engine de fluxos desabilitada"
        description="Mutações, dry-run e preview permanecem bloqueados com a flag OFF."
      />

      <UAlert
        v-if="editor.versionConflict.value"
        class="mb-4"
        color="error"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        title="Conflito de lock_version (409)"
        description="Outra sessão alterou o draft. Recarregue antes de salvar novamente."
        data-testid="flow-editor-version-conflict"
      >
        <template #actions>
          <UButton
            size="xs"
            label="Recarregar draft"
            @click="load"
          />
        </template>
      </UAlert>

      <ShellLoadError
        v-if="loadError && !flow"
        :title="loadError"
        test-id="flow-editor-load-error"
        @retry="load"
      />

      <div
        v-else-if="loading && !flow"
        class="space-y-4"
        data-testid="flow-editor-loading"
      >
        <USkeleton class="h-16 w-full rounded-lg" />
        <USkeleton class="h-96 w-full rounded-lg" />
      </div>

      <div
        v-else-if="flow"
        class="flex min-h-[70vh] flex-col gap-4"
      >
        <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
          <span
            v-if="editor.graphDigest.value"
            class="font-mono"
            data-testid="flow-editor-digest"
          >
            digest {{ editor.graphDigest.value }}
          </span>
          <span class="font-mono">
            lock {{ editor.lockVersion.value }}
          </span>
          <UBadge
            v-if="editor.dirty.value"
            color="warning"
            variant="subtle"
            label="Alterações não salvas"
          />
          <UBadge
            v-if="reducedMotion"
            color="neutral"
            variant="subtle"
            label="Reduced motion"
          />
          <UBadge
            v-if="isMobile"
            color="neutral"
            variant="subtle"
            label="Mobile · canvas somente leitura"
            data-testid="flow-editor-mobile-badge"
          />
        </div>

        <UAlert
          v-if="validateMessage"
          :color="validateOk ? 'success' : 'error'"
          variant="subtle"
          :icon="validateOk ? 'i-lucide-check-circle' : 'i-lucide-circle-x'"
          :title="validateMessage"
          data-testid="flow-editor-validate-result"
        />

        <UAlert
          v-if="allErrors.length"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Erros de validação"
          data-testid="flow-editor-errors"
        >
          <ul class="mt-2 list-disc space-y-1 pl-4 text-sm">
            <li
              v-for="(error, index) in allErrors"
              :key="`${error.path}-${error.code}-${index}`"
            >
              <span class="font-mono text-xs">{{ error.code }}</span>
              — {{ error.message }}
              <span class="text-muted">({{ error.path }})</span>
            </li>
          </ul>
        </UAlert>

        <div
          class="grid min-h-[32rem] flex-1 gap-3"
          :class="isMobile
            ? 'grid-cols-1'
            : 'lg:grid-cols-[14rem_minmax(0,1fr)_18rem]'"
          data-testid="flow-editor-layout"
        >
          <ShellSectionCard
            v-if="canManage"
            class="overflow-hidden p-0"
          >
            <FlowEditorPalette
              :disabled="Boolean(mutationBlocked)"
              @add="onAddNode"
            />
          </ShellSectionCard>

          <div class="flex min-h-72 flex-col gap-3">
            <ClientOnly>
              <FlowEditorCanvas
                :graph="editor.graph.value"
                :selected-node-id="editor.selectedNodeId.value"
                :read-only="canvasReadOnly"
                :reduced-motion="reducedMotion"
                class="min-h-72 flex-1"
                @update:graph="onGraphUpdate"
                @select="editor.selectNode"
              />
              <template #fallback>
                <USkeleton class="min-h-72 w-full flex-1 rounded-lg" />
              </template>
            </ClientOnly>

            <ShellSectionCard
              v-if="showListMode"
              class="p-3"
            >
              <ShellSectionHeader
                title="Modo lista"
                description="Edição acessível sem drag completo no canvas."
              />
              <FlowEditorListMode
                class="mt-3"
                :graph="editor.graph.value"
                :selected-node-id="editor.selectedNodeId.value"
                :disabled="Boolean(mutationBlocked)"
                @select="editor.selectNode"
                @remove="editor.removeNode"
                @connect="(source, target) => editor.connect(source, target)"
              />
            </ShellSectionCard>
          </div>

          <ShellSectionCard
            v-if="!isMobile"
            class="overflow-hidden p-0"
          >
            <FlowEditorInspector
              :node="editor.selectedNode.value"
              :disabled="Boolean(mutationBlocked)"
              @update="editor.patchSelectedData"
              @remove="editor.removeSelected"
              @connect-request="openConnect"
            />
          </ShellSectionCard>
        </div>

        <ShellSectionCard
          v-if="isMobile"
          class="overflow-hidden p-0"
        >
          <FlowEditorInspector
            :node="editor.selectedNode.value"
            :disabled="Boolean(mutationBlocked)"
            @update="editor.patchSelectedData"
            @remove="editor.removeSelected"
            @connect-request="openConnect"
          />
        </ShellSectionCard>

        <ShellSectionCard class="p-3">
          <ShellSectionHeader
            title="Contexto de dry-run"
            description="Simulação server-side sem outbox, jobs de correlação ou gateway."
          />
          <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <UFormField
              label="Nome do contato"
              name="contact_name"
            >
              <UInput
                v-model="dryRunContext.contact_name"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Status da conversa"
              name="conversation_status"
            >
              <USelect
                v-model="dryRunContext.conversation_status"
                :items="[
                  { label: 'OPEN', value: 'OPEN' },
                  { label: 'PENDING', value: 'PENDING' },
                  { label: 'RESOLVED', value: 'RESOLVED' },
                  { label: 'SNOOZED', value: 'SNOOZED' }
                ]"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Último texto inbound"
              name="last_inbound_text"
            >
              <UInput
                v-model="dryRunContext.last_inbound_text"
                class="w-full"
              />
            </UFormField>
          </div>
        </ShellSectionCard>
      </div>

      <ShellConfirmModal
        v-model:open="publishOpen"
        title="Publicar versão?"
        description="O draft atual será materializado em uma versão imutável. Isso não habilita nenhum binding de inbox."
        confirm-label="Publicar"
        tone="neutral"
        :loading="publishBusy"
        test-id="flow-editor-publish-modal"
        @confirm="onPublishConfirm"
      />

      <ShellConfirmModal
        v-model:open="connectOpen"
        title="Conectar nós"
        description="Alternativa ao drag: escolha o destino da conexão a partir do nó selecionado."
        confirm-label="Conectar"
        tone="neutral"
        test-id="flow-editor-connect-modal"
        @confirm="confirmConnect"
      >
        <template #body>
          <UFormField
            label="Destino"
            name="connect_target"
          >
            <USelect
              v-model="connectTarget"
              :items="connectItems"
              placeholder="Selecione"
              class="w-full"
            />
          </UFormField>
        </template>
      </ShellConfirmModal>

      <UModal
        v-model:open="dryRunOpen"
        title="Resultado do dry-run"
        description="Simulação sem side effects de transporte."
        :ui="{ content: 'sm:max-w-2xl' }"
        data-testid="flow-editor-dry-run-modal"
      >
        <template #body>
          <div
            v-if="dryRunResult"
            class="space-y-3"
          >
            <p class="text-sm text-highlighted">
              Outcome: <strong>{{ dryRunResult.outcome }}</strong>
            </p>
            <p class="font-mono text-xs text-muted">
              digest {{ dryRunResult.graph_digest }}
            </p>
            <UAlert
              v-if="dryRunResult.side_effects"
              color="success"
              variant="subtle"
              icon="i-lucide-shield-check"
              title="Sem egress"
              :description="`outbox=${dryRunResult.side_effects.outbox_created} · run=${dryRunResult.side_effects.flow_run_persisted} · gateway=${dryRunResult.side_effects.gateway_called}`"
            />
            <ul class="divide-y divide-default rounded-lg border border-default">
              <li
                v-for="step in dryRunResult.steps"
                :key="`${step.seq}-${step.node_id}`"
                class="px-3 py-2 text-sm"
              >
                <span class="font-mono text-xs text-muted">#{{ step.seq }}</span>
                {{ step.node_type }} · {{ step.node_id }} · {{ step.status }}
              </li>
            </ul>
          </div>
        </template>
      </UModal>

      <UModal
        v-model:open="previewOpen"
        title="Preview mascarado"
        description="Textos sensíveis mascarados pelo servidor. Não copie para logs."
        :ui="{ content: 'sm:max-w-2xl' }"
        data-testid="flow-editor-preview-modal"
      >
        <template #body>
          <div
            v-if="previewResult"
            class="space-y-3"
          >
            <p class="font-mono text-xs text-muted">
              digest {{ previewResult.graph_digest }}
            </p>
            <p
              v-if="previewResult.masked_paths.length"
              class="text-xs text-muted"
            >
              Caminhos mascarados: {{ previewResult.masked_paths.join(', ') }}
            </p>
            <pre
              class="max-h-96 overflow-auto rounded-lg border border-default bg-elevated p-3 text-xs"
              data-testid="flow-editor-preview-json"
            >{{ JSON.stringify(previewResult.graph, null, 2) }}</pre>
          </div>
        </template>
      </UModal>
    </template>
  </ShellPagePanel>
</template>
