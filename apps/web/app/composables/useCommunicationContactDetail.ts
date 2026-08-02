import {
  computed,
  onMounted,
  onScopeDispose,
  ref,
  watch,
  type ComputedRef,
  type Ref
} from 'vue'
import type { ClientContact } from '~/types/api'
import type { Contact, Identity } from '~/types/communication/contacts'
import { apiErrorMessage } from '~/utils/api-error'
import {
  communicationContactDisplayName,
  flattenCommunicationIdentityLinks
} from '~/utils/communication-contacts'
import { parseCommunicationContactId } from '~/utils/communication-routes'
import { canManageCommunicationContacts } from '~/utils/permissions'
import { COMMUNICATION_SURFACES, consumeSurfaceNavigationIntent } from './useSurfaceNavigationState'

export type ContactDetailApi = {
  get: (id: number, inboxId?: number) => Promise<{ data: Contact }>
  update: (
    id: number,
    body: { name?: string | null, is_active?: boolean }
  ) => Promise<{ data: Contact }>
  addIdentity: (contactId: number, phone: string) => Promise<unknown>
  linkIdentity: (
    identityId: number,
    body: {
      client_id: number
      client_contact_id?: number
      is_primary?: boolean
      receives_automatic?: boolean
    }
  ) => Promise<unknown>
  unlinkIdentity: (identityId: number, linkId: number) => Promise<unknown>
  exportUrl: (contactId: number) => string
  purge: (contactId: number) => Promise<unknown>
  listClientContacts: (clientId: number) => Promise<{ data: ClientContact[] }>
}

export type ContactDetailDependencies = {
  api: ContactDetailApi
  canManage: ComputedRef<boolean> | Ref<boolean>
  contactId: ComputedRef<number | null> | Ref<number | null>
  inboxId?: ComputedRef<number | null> | Ref<number | null>
  sessionEpoch: Ref<number>
  toast: (
    title: string,
    color: 'success' | 'error',
    description?: string
  ) => void
  download: (url: string, filename: string) => Promise<unknown>
}

export function createCommunicationContactDetail(
  dependencies: ContactDetailDependencies
) {
  const contact = ref<Contact | null>(null)
  const loading = ref(false)
  const loadError = ref<string | null>(null)
  const saving = ref(false)

  const editName = ref('')
  const editActive = ref(true)

  const identityOpen = ref(false)
  const identityPhone = ref('')
  const identityError = ref<string | null>(null)
  const identityBusy = ref(false)

  const linkOpen = ref(false)
  const linkIdentity = ref<Identity | null>(null)
  const linkClientId = ref<number | null>(null)
  const linkClientContactId = ref<number | undefined>(undefined)
  const linkIsPrimary = ref(false)
  const linkReceivesAutomatic = ref(true)
  const linkClientContacts = ref<ClientContact[]>([])
  const linkBusy = ref(false)
  const linkError = ref<string | null>(null)
  const unlinkingKey = ref<string | null>(null)

  const purgeOpen = ref(false)
  const purging = ref(false)
  const exporting = ref(false)

  let loadSequence = 0
  let contextSequence = 0
  let clientContactsSequence = 0

  const displayName = computed(() =>
    contact.value ? communicationContactDisplayName(contact.value) : 'Contato'
  )

  const identityLinks = computed(() =>
    flattenCommunicationIdentityLinks(contact.value?.identities)
  )

  const isPurged = computed(() => Boolean(contact.value?.purged_at))
  const canMutate = computed(
    () => dependencies.canManage.value && Boolean(contact.value) && !isPurged.value
  )

  const clientContactItems = computed(() =>
    linkClientContacts.value
      .filter(item => item.is_active)
      .map(item => ({
        label: item.name,
        value: item.id
      }))
  )

  function captureContext(contactId: number) {
    return {
      sequence: contextSequence,
      epoch: dependencies.sessionEpoch.value,
      contactId
    }
  }

  function isCurrentContext(context: ReturnType<typeof captureContext>) {
    return context.sequence === contextSequence
      && context.epoch === dependencies.sessionEpoch.value
      && context.contactId === dependencies.contactId.value
  }

  function resetTransientState() {
    ++contextSequence
    ++clientContactsSequence
    saving.value = false
    editName.value = ''
    editActive.value = true
    identityOpen.value = false
    identityPhone.value = ''
    identityError.value = null
    identityBusy.value = false
    linkOpen.value = false
    linkIdentity.value = null
    linkClientId.value = null
    linkClientContactId.value = undefined
    linkIsPrimary.value = false
    linkReceivesAutomatic.value = true
    linkClientContacts.value = []
    linkBusy.value = false
    linkError.value = null
    unlinkingKey.value = null
    purgeOpen.value = false
    purging.value = false
    exporting.value = false
  }

  async function load() {
    const sequence = ++loadSequence
    const epoch = dependencies.sessionEpoch.value
    const id = dependencies.contactId.value
    const inboxId = dependencies.inboxId?.value ?? null
    loading.value = true
    loadError.value = null
    if (!id) {
      loadError.value = 'Contato inválido.'
      contact.value = null
      loading.value = false
      return
    }
    try {
      const res = inboxId ? await dependencies.api.get(id, inboxId) : await dependencies.api.get(id)
      if (
        sequence !== loadSequence
        || epoch !== dependencies.sessionEpoch.value
        || id !== dependencies.contactId.value
        || inboxId !== (dependencies.inboxId?.value ?? null)
      ) return
      contact.value = res.data
      editName.value = res.data.name || ''
      editActive.value = res.data.is_active
    } catch (caught) {
      if (
        sequence !== loadSequence
        || epoch !== dependencies.sessionEpoch.value
        || id !== dependencies.contactId.value
        || inboxId !== (dependencies.inboxId?.value ?? null)
      ) return
      contact.value = null
      loadError.value = apiErrorMessage(caught, 'Falha ao carregar o contato.')
    } finally {
      if (
        sequence === loadSequence
        && epoch === dependencies.sessionEpoch.value
        && id === dependencies.contactId.value
      ) {
        loading.value = false
      }
    }
  }

  async function saveProfile() {
    if (saving.value || !canMutate.value || !contact.value) return
    const context = captureContext(contact.value.id)
    saving.value = true
    try {
      await dependencies.api.update(contact.value.id, {
        name: editName.value.trim() || null,
        is_active: editActive.value
      })
      if (!isCurrentContext(context)) return
      await load()
      if (!isCurrentContext(context)) return
      dependencies.toast('Contato atualizado.', 'success')
    } catch (caught) {
      if (!isCurrentContext(context)) return
      dependencies.toast(apiErrorMessage(caught, 'Falha ao salvar o contato.'), 'error')
    } finally {
      if (isCurrentContext(context)) saving.value = false
    }
  }

  function openAddIdentity() {
    identityPhone.value = ''
    identityError.value = null
    identityOpen.value = true
  }

  async function submitIdentity() {
    if (identityBusy.value || !canMutate.value || !contact.value) return
    const phone = identityPhone.value.trim()
    if ((phone.match(/\d/g) || []).length < 8) {
      identityError.value = 'Informe um telefone WhatsApp válido.'
      return
    }
    const context = captureContext(contact.value.id)
    identityBusy.value = true
    identityError.value = null
    try {
      await dependencies.api.addIdentity(contact.value.id, phone)
      if (!isCurrentContext(context)) return
      dependencies.toast('Identidade adicionada.', 'success')
      identityOpen.value = false
      await load()
    } catch (caught) {
      if (!isCurrentContext(context)) return
      identityError.value = apiErrorMessage(caught, 'Falha ao adicionar identidade.')
    } finally {
      if (isCurrentContext(context)) identityBusy.value = false
    }
  }

  async function loadClientContacts(clientId: number) {
    const contactId = contact.value?.id
    if (!contactId) return
    const sequence = ++clientContactsSequence
    const context = captureContext(contactId)
    try {
      const res = await dependencies.api.listClientContacts(clientId)
      if (
        sequence !== clientContactsSequence
        || !isCurrentContext(context)
        || linkClientId.value !== clientId
      ) return
      linkClientContacts.value = res.data
    } catch {
      if (
        sequence !== clientContactsSequence
        || !isCurrentContext(context)
        || linkClientId.value !== clientId
      ) return
      linkClientContacts.value = []
      linkError.value = 'Falha ao carregar os contatos do cliente.'
    }
  }

  function openLink(identity: Identity) {
    ++clientContactsSequence
    linkIdentity.value = identity
    linkClientId.value = null
    linkClientContactId.value = undefined
    linkIsPrimary.value = false
    linkReceivesAutomatic.value = true
    linkClientContacts.value = []
    linkError.value = null
    linkOpen.value = true
  }

  async function submitLink() {
    const current = contact.value
    if (linkBusy.value || !canMutate.value || !current || !linkIdentity.value) return
    if (!linkClientId.value) {
      linkError.value = 'Selecione um cliente.'
      return
    }
    const context = captureContext(current.id)
    linkBusy.value = true
    linkError.value = null
    try {
      await dependencies.api.linkIdentity(linkIdentity.value.id, {
        client_id: linkClientId.value,
        client_contact_id: linkClientContactId.value,
        is_primary: linkIsPrimary.value,
        receives_automatic: linkReceivesAutomatic.value
      })
      if (!isCurrentContext(context)) return
      dependencies.toast('Vínculo criado.', 'success')
      linkOpen.value = false
      await load()
    } catch (caught) {
      if (!isCurrentContext(context)) return
      linkError.value = apiErrorMessage(caught, 'Falha ao vincular cliente.')
    } finally {
      if (isCurrentContext(context)) linkBusy.value = false
    }
  }

  async function unlink(identityId: number, linkId: number) {
    if (!canMutate.value || !contact.value || unlinkingKey.value) return
    const context = captureContext(contact.value.id)
    const key = `${identityId}:${linkId}`
    unlinkingKey.value = key
    try {
      await dependencies.api.unlinkIdentity(identityId, linkId)
      if (!isCurrentContext(context)) return
      dependencies.toast('Vínculo removido.', 'success')
      await load()
    } catch (caught) {
      if (!isCurrentContext(context)) return
      dependencies.toast(apiErrorMessage(caught, 'Falha ao remover vínculo.'), 'error')
    } finally {
      if (isCurrentContext(context)) unlinkingKey.value = null
    }
  }

  async function exportContact() {
    if (!dependencies.canManage.value || !contact.value || isPurged.value) return
    const context = captureContext(contact.value.id)
    exporting.value = true
    try {
      await dependencies.download(
        dependencies.api.exportUrl(contact.value.id),
        `contato-${contact.value.id}.json`
      )
      if (!isCurrentContext(context)) return
      dependencies.toast('Exportação iniciada.', 'success')
    } catch (caught) {
      if (!isCurrentContext(context)) return
      dependencies.toast(apiErrorMessage(caught, 'Falha ao exportar o contato.'), 'error')
    } finally {
      if (isCurrentContext(context)) exporting.value = false
    }
  }

  async function confirmPurge() {
    if (purging.value || !dependencies.canManage.value || !contact.value || isPurged.value) return
    const context = captureContext(contact.value.id)
    purging.value = true
    try {
      await dependencies.api.purge(contact.value.id)
      if (!isCurrentContext(context)) return
      dependencies.toast(
        'Dados pessoais expurgados.',
        'success',
        'Conteúdo recuperável foi removido; o tombstone auditável permanece.'
      )
      purgeOpen.value = false
      await load()
    } catch (caught) {
      if (!isCurrentContext(context)) return
      dependencies.toast(apiErrorMessage(caught, 'Falha ao expurgar os dados.'), 'error')
    } finally {
      if (isCurrentContext(context)) purging.value = false
    }
  }

  function openPurge() {
    if (!dependencies.canManage.value || !contact.value || isPurged.value) return
    purgeOpen.value = true
  }

  const stopContactWatch = watch(
    () => dependencies.contactId.value,
    () => {
      contact.value = null
      resetTransientState()
      void load()
    }
  )

  const stopSessionWatch = watch(dependencies.sessionEpoch, () => {
    contact.value = null
    resetTransientState()
    void load()
  })

  const stopInboxWatch = dependencies.inboxId
    ? watch(dependencies.inboxId, () => {
        contact.value = null
        resetTransientState()
        void load()
      })
    : () => {}

  const stopLinkClientWatch = watch(linkClientId, (id) => {
    linkClientContactId.value = undefined
    if (id) void loadClientContacts(id)
    else {
      ++clientContactsSequence
      linkClientContacts.value = []
    }
  })

  function dispose() {
    stopContactWatch()
    stopSessionWatch()
    stopInboxWatch()
    stopLinkClientWatch()
    ++loadSequence
    ++contextSequence
    ++clientContactsSequence
  }

  return {
    contact,
    loading,
    loadError,
    saving,
    editName,
    editActive,
    identityOpen,
    identityPhone,
    identityError,
    identityBusy,
    linkOpen,
    linkIdentity,
    linkClientId,
    linkClientContactId,
    linkIsPrimary,
    linkReceivesAutomatic,
    linkBusy,
    linkError,
    unlinkingKey,
    purgeOpen,
    purging,
    exporting,
    displayName,
    identityLinks,
    isPurged,
    canMutate,
    clientContactItems,
    load,
    saveProfile,
    openAddIdentity,
    submitIdentity,
    openLink,
    submitLink,
    unlink,
    exportContact,
    confirmPurge,
    openPurge,
    dispose
  }
}

export function useCommunicationContactDetail() {
  const api = useApi()
  const route = useRoute()
  const toast = useToast()
  const download = useAuthenticatedDownload()
  const { me, sessionEpoch } = useDashboard()

  const canManage = computed(() => canManageCommunicationContacts(me.value))
  const contactId = computed(() => parseCommunicationContactId(route.params.id))
  const detailIntent = consumeSurfaceNavigationIntent<{ detail_inbox_id?: unknown }>(
    COMMUNICATION_SURFACES.contacts
  )
  const parsedInboxId = Number(detailIntent?.detail_inbox_id)
  const inboxId = ref<number | null>(
    Number.isInteger(parsedInboxId) && parsedInboxId > 0 ? parsedInboxId : null
  )

  const detail = createCommunicationContactDetail({
    api: {
      get: (id, selectedInboxId) => api.communication.contacts.get(id, selectedInboxId),
      update: (id, body) => api.communication.contacts.update(id, body),
      addIdentity: (contactId, phone) => api.communication.contacts.addIdentity(contactId, phone),
      linkIdentity: (identityId, body) => api.communication.contacts.linkIdentity(identityId, body),
      unlinkIdentity: (identityId, linkId) =>
        api.communication.contacts.unlinkIdentity(identityId, linkId),
      exportUrl: contactId => api.communication.contacts.exportUrl(contactId),
      purge: contactId => api.communication.contacts.purge(contactId),
      listClientContacts: clientId => api.contacts.list(clientId)
    },
    canManage,
    contactId,
    inboxId,
    sessionEpoch,
    toast: (title, color, description) => toast.add({ title, color, description }),
    download: (url, filename) => download.download(url, filename)
  })

  useCommunicationProfilePictureRealtime(detail.load, (event) => {
    const identityId = Number(event.payload.identity_id)
    const matchesInbox = inboxId.value === null || Number(event.inbox_id) === inboxId.value
    return matchesInbox
      && Number.isInteger(identityId)
      && Boolean(detail.contact.value?.identities?.some(identity => identity.id === identityId))
  })

  onMounted(() => {
    void detail.load()
  })
  onScopeDispose(detail.dispose)

  return {
    ...detail,
    canManage,
    contactId,
    inboxId
  }
}
