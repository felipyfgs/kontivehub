import {
  computed,
  onMounted,
  ref,
  watch,
  type ComputedRef,
  type Ref
} from 'vue'
import type {
  CommunicationInbox
} from '~/types/communication'
import type {
  Flow,
  FlowBinding,
  FlowGraph,
  FlowPublishResult,
  FlowStatus,
  FlowValidateResult
} from '~/types/communication/flows'
import { apiErrorCode, apiErrorMessage } from '~/utils/api-error'
import {
  communicationFlowsMutationBlocked,
  formatFlowGraphJson,
  parseFlowGraphJson
} from '~/utils/communication-flows'
import { canManageCommunicationFlows } from '~/utils/permissions'
import { parseCommunicationFlowId } from '~/utils/communication-routes'

interface FlowDetailApi {
  list: () => Promise<{
    data: Flow[]
    meta: { flows_enabled: boolean }
  }>
  get: (id: number) => Promise<{ data: Flow }>
  update: (
    id: number,
    body: { name?: string, status?: FlowStatus, lock_version: number }
  ) => Promise<{ data: Flow }>
  updateDraft: (
    id: number,
    body: { graph: FlowGraph, lock_version: number }
  ) => Promise<{ data: NonNullable<Flow['draft']> }>
  validate: (
    id: number,
    body: { graph: FlowGraph }
  ) => Promise<{ data: FlowValidateResult }>
  publish: (
    id: number,
    body: { lock_version: number }
  ) => Promise<{ data: FlowPublishResult }>
  createBinding: (
    id: number,
    body: {
      inbox_id: number
      published_version_id: number | null
      enabled: boolean
    }
  ) => Promise<unknown>
  enableBinding: (
    bindingId: number,
    body: { lock_version: number, published_version_id: number }
  ) => Promise<unknown>
  disableBinding: (
    bindingId: number,
    body: { lock_version: number }
  ) => Promise<unknown>
}

interface FlowDetailDependencies {
  api: FlowDetailApi
  listInboxes: () => Promise<{ data: CommunicationInbox[] }>
  canManage: ComputedRef<boolean> | Ref<boolean>
  flowId: ComputedRef<number | null> | Ref<number | null>
  sessionEpoch: Ref<number>
  toast: (
    title: string,
    color: 'success' | 'error' | 'warning',
    description?: string
  ) => void
}

const VERSION_CONFLICT_MESSAGE
  = 'Este fluxo foi alterado por outra pessoa. Atualize os dados e tente novamente.'

export function createCommunicationFlowDetail(dependencies: FlowDetailDependencies) {
  const {
    api,
    listInboxes,
    canManage,
    flowId,
    sessionEpoch,
    toast
  } = dependencies

  const flow = ref<Flow | null>(null)
  const flowsEnabled = ref(false)
  const flagsConfirmed = ref(false)
  const loading = ref(false)
  const loadError = ref<string | null>(null)
  const hasLoaded = ref(false)
  const versionConflict = ref(false)
  let loadGeneration = 0

  const editName = ref('')
  const editStatus = ref<FlowStatus>('paused')
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
  const showAdvancedJson = ref(false)

  const publishOpen = ref(false)
  const publishBusy = ref(false)
  const enableOpen = ref(false)
  const enableBusy = ref(false)
  const enableTarget = ref<FlowBinding | null>(null)

  const inboxes = ref<CommunicationInbox[]>([])
  const inboxesError = ref<string | null>(null)
  const bindingInboxId = ref<number | undefined>(undefined)
  const bindingVersionId = ref<number | undefined>(undefined)
  const bindingBusy = ref(false)
  const bindingError = ref<string | null>(null)
  const bindingActionKey = ref<string | null>(null)

  const mutationBlocked = computed(() =>
    communicationFlowsMutationBlocked(
      flagsConfirmed.value && flowsEnabled.value,
      canManage.value
    )
  )
  const versions = computed(() => flow.value?.versions ?? [])
  const bindings = computed(() => flow.value?.bindings ?? [])
  const initialLoading = computed(() =>
    loading.value && !hasLoaded.value && flow.value === null
  )
  const stale = computed(() =>
    hasLoaded.value
    && flow.value !== null
    && (loading.value || Boolean(loadError.value))
  )
  const inboxItems = computed(() =>
    inboxes.value.map(inbox => ({ label: inbox.name, value: inbox.id }))
  )
  const versionItems = computed(() =>
    versions.value.map(version => ({
      label: `v${version.version}`,
      value: version.id
    }))
  )
  const inboxNameById = computed(() => {
    const names = new Map<number, string>()
    for (const inbox of inboxes.value) names.set(inbox.id, inbox.name)
    return names
  })

  function applyFlow(next: Flow) {
    flow.value = next
    editName.value = next.name
    editStatus.value = next.status
    draftJson.value = formatFlowGraphJson(next.draft?.graph)
    draftLockVersion.value = next.draft?.lock_version ?? 1
    draftDigest.value = next.draft?.graph_digest ?? ''
  }

  function closeFlags() {
    flowsEnabled.value = false
    flagsConfirmed.value = false
  }

  function mutationError(caught: unknown, fallback: string): string {
    const code = apiErrorCode(caught)
    if (code === 'communication_flows_disabled') {
      flowsEnabled.value = false
      flagsConfirmed.value = true
    }
    if (code === 'version_conflict') {
      versionConflict.value = true
      return VERSION_CONFLICT_MESSAGE
    }
    return apiErrorMessage(caught, fallback)
  }

  async function loadInboxes() {
    try {
      const response = await listInboxes()
      inboxes.value = response.data
      inboxesError.value = null
    } catch (caught) {
      inboxesError.value = apiErrorMessage(caught, 'Falha ao carregar inboxes.')
    }
  }

  async function load() {
    const id = flowId.value
    if (!id) {
      flow.value = null
      hasLoaded.value = false
      closeFlags()
      loadError.value = 'Fluxo inválido.'
      return
    }

    const epoch = sessionEpoch.value
    const generation = ++loadGeneration
    loading.value = true
    loadError.value = null
    closeFlags()

    try {
      const [detail, list] = await Promise.all([api.get(id), api.list()])
      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      applyFlow(detail.data)
      flowsEnabled.value = list.meta?.flows_enabled === true
      flagsConfirmed.value = true
      hasLoaded.value = true
      versionConflict.value = false
      validateMessage.value = null
      validateOk.value = false
      await loadInboxes()
    } catch (caught) {
      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      loadError.value = apiErrorMessage(caught, 'Falha ao carregar o fluxo.')
    } finally {
      if (epoch === sessionEpoch.value && generation === loadGeneration) {
        loading.value = false
      }
    }
  }

  async function saveMetadata() {
    const blocked = mutationBlocked.value
    const current = flow.value
    if (blocked || !current) {
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
      const response = await api.update(current.id, {
        name,
        status: editStatus.value,
        lock_version: current.lock_version
      })
      applyFlow({
        ...current,
        ...response.data,
        draft: current.draft,
        versions: current.versions,
        bindings: current.bindings
      })
      toast('Metadados salvos.', 'success')
    } catch (caught) {
      metaError.value = mutationError(caught, 'Falha ao salvar metadados.')
      toast(metaError.value, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      metaBusy.value = false
    }
  }

  function parsedDraft() {
    const parsed = parseFlowGraphJson(draftJson.value)
    if (!parsed.ok) draftError.value = parsed.message
    return parsed
  }

  async function saveDraft() {
    const blocked = mutationBlocked.value
    const current = flow.value
    if (blocked || !current) {
      draftError.value = blocked
      return
    }
    const parsed = parsedDraft()
    if (!parsed.ok) return

    draftBusy.value = true
    draftError.value = null
    try {
      const response = await api.updateDraft(current.id, {
        graph: parsed.graph,
        lock_version: draftLockVersion.value
      })
      draftLockVersion.value = response.data.lock_version
      draftDigest.value = response.data.graph_digest
      draftJson.value = formatFlowGraphJson(response.data.graph)
      flow.value = { ...current, draft: response.data }
      validateOk.value = false
      validateMessage.value = null
      toast('Draft salvo.', 'success')
    } catch (caught) {
      draftError.value = mutationError(caught, 'Falha ao salvar draft.')
      toast(draftError.value, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      draftBusy.value = false
    }
  }

  async function validateDraft() {
    const blocked = mutationBlocked.value
    const current = flow.value
    if (blocked || !current) {
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
      const response = await api.validate(current.id, { graph: parsed.graph })
      validateOk.value = true
      validateMessage.value = `Grafo válido. Digest: ${response.data.graph_digest}`
      toast('Grafo válido.', 'success')
    } catch (caught) {
      validateMessage.value = mutationError(caught, 'Grafo inválido.')
      toast(validateMessage.value, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      validateBusy.value = false
    }
  }

  function openPublish() {
    const blocked = mutationBlocked.value
    if (blocked) {
      toast(blocked, 'warning')
      return
    }
    publishOpen.value = true
  }

  async function confirmPublish() {
    const blocked = mutationBlocked.value
    const current = flow.value
    if (blocked || !current) {
      if (blocked) toast(blocked, 'warning')
      return
    }
    const parsed = parseFlowGraphJson(draftJson.value)
    if (!parsed.ok) {
      toast(parsed.message, 'error')
      return
    }
    publishBusy.value = true
    try {
      const draftResponse = await api.updateDraft(current.id, {
        graph: parsed.graph,
        lock_version: draftLockVersion.value
      })
      draftLockVersion.value = draftResponse.data.lock_version
      draftDigest.value = draftResponse.data.graph_digest
      const response = await api.publish(current.id, {
        lock_version: draftLockVersion.value
      })
      toast(
        `Versão v${response.data.version.version} publicada.`,
        'success',
        'Publicar não habilita bindings.'
      )
      publishOpen.value = false
      await load()
    } catch (caught) {
      const message = mutationError(caught, 'Falha ao publicar fluxo.')
      toast(message, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      publishBusy.value = false
    }
  }

  async function createBinding() {
    const blocked = mutationBlocked.value
    const current = flow.value
    if (blocked || !current || bindingInboxId.value == null) {
      bindingError.value = blocked || 'Selecione uma inbox.'
      return
    }
    bindingBusy.value = true
    bindingError.value = null
    try {
      await api.createBinding(current.id, {
        inbox_id: bindingInboxId.value,
        published_version_id: bindingVersionId.value ?? null,
        enabled: false
      })
      toast('Binding criado (desabilitado).', 'success')
      bindingInboxId.value = undefined
      bindingVersionId.value = undefined
      await load()
    } catch (caught) {
      bindingError.value = mutationError(caught, 'Falha ao criar binding.')
      toast(bindingError.value, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      bindingBusy.value = false
    }
  }

  function openEnable(binding: FlowBinding) {
    const blocked = mutationBlocked.value
    if (blocked || !flow.value) {
      toast(blocked || 'Sem permissão.', 'warning')
      return
    }
    if (!binding.published_version_id && !versions.value.length) {
      toast('Publique uma versão antes de habilitar o binding.', 'warning')
      return
    }
    enableTarget.value = binding
    enableOpen.value = true
  }

  async function confirmEnable() {
    const binding = enableTarget.value
    const blocked = mutationBlocked.value
    if (blocked || !flow.value || !binding) {
      if (blocked) toast(blocked, 'warning')
      return
    }
    const versionId = binding.published_version_id
      ?? versions.value[versions.value.length - 1]?.id
      ?? null
    if (versionId == null) {
      toast('Versão publicada obrigatória.', 'warning')
      return
    }
    enableBusy.value = true
    bindingActionKey.value = `${binding.id}:enable`
    try {
      await api.enableBinding(binding.id, {
        lock_version: binding.lock_version,
        published_version_id: versionId
      })
      toast('Binding habilitado.', 'success')
      enableOpen.value = false
      enableTarget.value = null
      await load()
    } catch (caught) {
      const message = mutationError(caught, 'Falha ao habilitar binding.')
      toast(message, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      enableBusy.value = false
      bindingActionKey.value = null
    }
  }

  async function disableBinding(binding: FlowBinding) {
    const blocked = mutationBlocked.value
    if (blocked || !flow.value) {
      toast(blocked || 'Sem permissão.', 'warning')
      return
    }
    bindingActionKey.value = `${binding.id}:disable`
    try {
      await api.disableBinding(binding.id, {
        lock_version: binding.lock_version
      })
      toast('Binding desabilitado.', 'success')
      await load()
    } catch (caught) {
      const message = mutationError(caught, 'Falha ao atualizar binding.')
      toast(message, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      bindingActionKey.value = null
    }
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

  watch(flowId, () => void load())
  watch(sessionEpoch, () => {
    loadGeneration += 1
    flow.value = null
    inboxes.value = []
    hasLoaded.value = false
    loadError.value = null
    closeFlags()
    void load()
  })

  return {
    canManage,
    flowId,
    flow,
    flowsEnabled,
    flagsConfirmed,
    loading,
    loadError,
    hasLoaded,
    initialLoading,
    stale,
    versionConflict,
    mutationBlocked,
    versions,
    bindings,
    editName,
    editStatus,
    metaBusy,
    metaError,
    draftJson,
    draftLockVersion,
    draftDigest,
    draftBusy,
    draftError,
    validateBusy,
    validateMessage,
    validateOk,
    showAdvancedJson,
    publishOpen,
    publishBusy,
    enableOpen,
    enableBusy,
    enableTarget,
    inboxes,
    inboxesError,
    inboxItems,
    inboxNameById,
    versionItems,
    bindingInboxId,
    bindingVersionId,
    bindingBusy,
    bindingError,
    bindingActionKey,
    load,
    saveMetadata,
    saveDraft,
    validateDraft,
    openPublish,
    confirmPublish,
    createBinding,
    openEnable,
    confirmEnable,
    disableBinding,
    versionLabel,
    formatPublishedAt
  }
}

export type CommunicationFlowDetail = ReturnType<
  typeof createCommunicationFlowDetail
>

export function useCommunicationFlowDetail() {
  const api = useApi()
  const route = useRoute()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const canManage = computed(() => canManageCommunicationFlows(me.value))
  const flowId = computed(() => parseCommunicationFlowId(route.params.id))

  const detail = createCommunicationFlowDetail({
    api: api.communication.flows,
    listInboxes: api.communication.inboxes.list,
    canManage,
    flowId,
    sessionEpoch,
    toast: (title, color, description) => toast.add({ title, color, description })
  })

  onMounted(() => void detail.load())
  return detail
}
