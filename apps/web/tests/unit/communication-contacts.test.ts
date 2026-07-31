import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { MeUser } from '~/types/api'
import type { CommunicationContact } from '~/types/communication'
import { createCommunicationContactDetail } from '~/composables/useCommunicationContactDetail'
import { createCommunicationContactsCatalog } from '~/composables/useCommunicationContactsCatalog'
import {
  COMMUNICATION_CONTACT_ACTION_LABELS,
  COMMUNICATION_CONTACT_DANGER_SOFT_CLASS,
  COMMUNICATION_CONTACT_SOLID_ACTION_CLASS,
  buildCommunicationContactListQuery,
  communicationContactActions,
  communicationContactDisplayName,
  communicationContactEmptyKind,
  communicationContactIdentityCount,
  communicationContactInitials,
  communicationContactLinkedClientNames,
  communicationContactPrimaryPhone,
  communicationContactRowActionsAriaLabel,
  communicationContactStatusContrastClass,
  hasActiveCommunicationContactFilters,
  isSensitiveCommunicationContactSearch,
  isCommunicationContactSortField
} from '~/utils/communication-contacts'
import {
  COMMUNICATION_CONTACTS_PATH,
  communicationContactPath,
  isCommunicationContactsNavActive,
  parseCommunicationContactId
} from '~/utils/communication-routes'
import { mainDestinations } from '~/utils/navigation'
import {
  canManageCommunication,
  canManageCommunicationContacts,
  canViewCommunication
} from '~/utils/permissions'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)
const read = (...parts: string[]) => readFileSync(root(...parts), 'utf8')

const sampleContact: CommunicationContact = {
  id: 7,
  name: 'Ana Silva',
  is_provisional: false,
  is_active: true,
  identities: [{
    id: 11,
    channel: 'WHATSAPP',
    address_masked: '119••••4321',
    phone: '+5511999998888',
    is_active: true,
    links: [{
      id: 21,
      client_id: 3,
      client_name: 'Cliente Alpha',
      client_contact_id: 9,
      client_contact_name: 'Maria Contato',
      is_primary: true,
      receives_automatic: true
    }]
  }]
}

describe('communication contacts — rotas e helpers', () => {
  it('expõe paths canônicos dos detalhes e do catálogo', () => {
    expect(COMMUNICATION_CONTACTS_PATH).toBe('/communication/contacts')
    expect(communicationContactPath(12)).toBe('/communication/contacts/12')
    expect(parseCommunicationContactId('9')).toBe(9)
    expect(parseCommunicationContactId(['4'])).toBe(4)
    expect(parseCommunicationContactId('x')).toBeNull()
    expect(isCommunicationContactsNavActive('/communication/contacts')).toBe(true)
    expect(isCommunicationContactsNavActive('/communication/contacts/3')).toBe(true)
    expect(isCommunicationContactsNavActive('/communication')).toBe(false)
  })

  it('monta query de listagem com filtros e sort whitelist', () => {
    expect(isCommunicationContactSortField('name')).toBe(true)
    expect(isCommunicationContactSortField('created_at')).toBe(true)
    expect(isCommunicationContactSortField('priority')).toBe(false)

    expect(buildCommunicationContactListQuery({
      q: ' ana ',
      isActive: 'false',
      isProvisional: 'true',
      linked: 'false',
      sort: 'created_at',
      sortDirection: 'desc',
      page: 2,
      perPage: 50
    })).toEqual({
      page: 2,
      per_page: 50,
      q: 'ana',
      is_active: false,
      include_inactive: true,
      is_provisional: true,
      linked: false,
      sort: 'created_at',
      sort_direction: 'desc'
    })

    expect(buildCommunicationContactListQuery({
      q: '',
      isActive: 'all',
      isProvisional: 'all',
      linked: 'all',
      sort: null,
      sortDirection: null,
      page: 1,
      perPage: 20
    })).toEqual({
      page: 1,
      per_page: 20,
      include_inactive: true
    })
  })

  it('deriva empty filtrado e labels de vínculo/nome', () => {
    expect(hasActiveCommunicationContactFilters({
      q: '',
      isActive: 'true',
      isProvisional: 'all',
      linked: 'all'
    })).toBe(false)
    expect(communicationContactEmptyKind({
      q: 'x',
      isActive: 'all',
      isProvisional: 'all',
      linked: 'all'
    })).toBe('filtered')
    expect(communicationContactDisplayName(sampleContact)).toBe('Ana Silva')
    expect(communicationContactDisplayName({
      id: 2,
      name: null,
      is_provisional: true,
      is_active: true
    })).toBe('Provisório #2')
    expect(communicationContactPrimaryPhone(sampleContact)).toBe('+5511999998888')
    expect(communicationContactLinkedClientNames(sampleContact)).toEqual(['Cliente Alpha'])
  })

  it('deriva iniciais, contagem e labels estáveis sem expor endereço bruto', () => {
    expect(communicationContactInitials(sampleContact)).toBe('AS')
    expect(communicationContactInitials({
      id: 8,
      name: 'Érica',
      is_provisional: false
    })).toBe('ÉR')
    expect(communicationContactInitials({
      id: 9,
      name: null,
      is_provisional: true
    })).toBe('?')
    expect(communicationContactIdentityCount(sampleContact)).toBe(1)
    expect(communicationContactRowActionsAriaLabel(sampleContact)).toBe('Ações de Ana Silva')
    expect(COMMUNICATION_CONTACT_ACTION_LABELS).toEqual({
      openDetail: 'Detalhes',
      goToConversations: 'Ir para conversas',
      export: 'Exportar',
      purge: 'Expurgar'
    })
    expect(COMMUNICATION_CONTACT_SOLID_ACTION_CLASS).toContain('text-zinc-950')
    expect(COMMUNICATION_CONTACT_DANGER_SOFT_CLASS).toContain('text-red-900')
    expect(communicationContactStatusContrastClass(sampleContact)).toContain('text-green-900')
    expect(communicationContactStatusContrastClass({
      ...sampleContact,
      is_provisional: true
    })).toContain('text-amber-900')
    expect(communicationContactStatusContrastClass({
      ...sampleContact,
      is_active: false
    })).toContain('text-zinc-900')
    expect(communicationContactStatusContrastClass({
      ...sampleContact,
      purged_at: '2026-07-28T10:00:00Z'
    })).toContain('text-red-900')
    expect(communicationContactPrimaryPhone({
      ...sampleContact,
      identities: [
        {
          id: 12,
          channel: 'WHATSAPP',
          address_masked: '',
          phone: null,
          is_active: true,
          links: []
        },
        {
          id: 13,
          channel: 'WHATSAPP',
          address_masked: '***7777',
          phone: '+5511988887777',
          is_active: true,
          links: []
        }
      ]
    })).toBe('+5511988887777')
  })

  it('classifica buscas potencialmente telefônicas para transporte fora da URL', () => {
    expect(isSensitiveCommunicationContactSearch('(11) 99999-8888')).toBe(true)
    expect(isSensitiveCommunicationContactSearch('+351 912 345 678')).toBe(true)
    expect(isSensitiveCommunicationContactSearch('12345678')).toBe(true)
    expect(isSensitiveCommunicationContactSearch('1234567')).toBe(false)
    expect(isSensitiveCommunicationContactSearch('Cliente 123')).toBe(false)
  })

  it('monta ações de contato conforme permissão e expurgo', () => {
    const onExport = vi.fn()
    const onPurge = vi.fn()
    const allowed = communicationContactActions(sampleContact, true, { onExport, onPurge })
    expect(allowed).toHaveLength(2)
    expect(allowed[0]?.map(item => item.label)).toEqual(['Detalhes', 'Ir para conversas'])
    expect(allowed[0]?.map(item => item.to)).toEqual([
      `/communication/contacts/${sampleContact.id}`,
      `/communication/contacts/${sampleContact.id}/conversations`
    ])
    expect(allowed[1]?.map(item => item.label)).toEqual(['Exportar', 'Expurgar'])

    expect(communicationContactActions(sampleContact, false, { onExport, onPurge })).toHaveLength(1)
    expect(communicationContactActions({
      ...sampleContact,
      purged_at: '2026-07-28T10:00:00Z'
    }, true, { onExport, onPurge })).toHaveLength(1)
  })
})

describe('communication contacts — permissões e navegação', () => {
  it('separa manage_contacts de manage_inboxes e view', () => {
    const viewOnly = {
      id: 1,
      role: 'OPERATOR',
      effective_permissions: ['communication.view']
    } as MeUser
    expect(canViewCommunication(viewOnly)).toBe(true)
    expect(canManageCommunicationContacts(viewOnly)).toBe(false)
    expect(canManageCommunication(viewOnly)).toBe(false)

    const inboxOnly = {
      id: 2,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_inboxes']
    } as MeUser
    expect(canManageCommunication(inboxOnly)).toBe(true)
    expect(canManageCommunicationContacts(inboxOnly)).toBe(false)

    const contactsManager = {
      id: 3,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_contacts']
    } as MeUser
    expect(canManageCommunicationContacts(contactsManager)).toBe(true)
    expect(canManageCommunication(contactsManager)).toBe(false)
  })

  it('expõe Contatos no grupo Atendimento com communication.view', () => {
    const user = {
      id: 10,
      role: 'VIEWER',
      context_status: 'ready',
      effective_permissions: ['communication.view']
    } as MeUser
    const destinations = mainDestinations(user, { path: '/communication/contacts' })
    const group = destinations.find(item => item.id === 'communication')
    expect(group).toMatchObject({
      id: 'communication',
      label: 'Atendimento',
      type: 'trigger',
      defaultOpen: true
    })
    expect(group?.children?.map(child => child.label)).toEqual([
      'Conversas',
      'Contatos',
      'Respostas rápidas',
      'Fluxos'
    ])
    expect(group?.children?.find(child => child.id === 'communication-contacts'))
      .toMatchObject({ to: '/communication/contacts', active: true })

    const withoutView = { id: 11, role: undefined } as MeUser
    expect(mainDestinations(withoutView).some(item => item.id === 'communication')).toBe(false)
  })
})

describe('communication contacts — superfícies e contrato Shell', () => {
  it('rota delega ao catálogo focado e preserva autorização', () => {
    const page = read('app/pages/communication/contacts/index.vue')
    expect(page).toContain('CommunicationContactsCatalog')
    expect(page).toContain('canViewCommunication')
    expect(page).toContain('navigateTo(\'/\')')
    expect(page).not.toContain('api.communication.contacts')
    expect(page).not.toContain('ShellDataTable')
  })

  it('catálogo extraído usa identidade rica, ações, empties e modal de vínculo completo', () => {
    const catalog = read('app/components/communication/contacts/Catalog.vue')
    const table = read('app/components/communication/contacts/CatalogTable.vue')
    const toolbar = read('app/components/communication/contacts/CatalogToolbar.vue')
    const modal = read('app/components/communication/contacts/CreateModal.vue')
    const composable = read('app/composables/useCommunicationContactsCatalog.ts')
    const api = read('app/composables/api/createCommunicationApi.ts')

    expect(catalog).toContain('ShellPagePanel')
    expect(catalog).toContain('ShellPageNavbar')
    expect(catalog).toContain('CommunicationContactsCatalogToolbar')
    expect(catalog).toContain('CommunicationContactsCatalogTable')
    expect(catalog).toContain('CommunicationContactsCreateModal')
    expect(catalog).toContain('CommunicationNewConversationModal')
    expect(catalog).toContain('openNewConversation')
    expect(catalog).toContain('canReply')
    expect(catalog).toContain('conversationRequestSequence')
    expect(catalog.indexOf('const inboxes = (await api.communication.inboxes.list()).data'))
      .toBeLessThan(catalog.indexOf('conversationInboxes.value = inboxes'))
    expect(toolbar).toContain('DataTableFilterRoot')
    expect(toolbar).toContain('Ordenar contatos')
    expect(toolbar).toContain('Mais ações de contatos')
    expect(table).toContain('communication-contacts-list')
    expect(modal).toContain('ShellFormModal')
    expect(table).toContain('ShellListEmpty')
    expect(table).toContain('communicationContactPrimaryPhone')
    expect(table).toContain('Número indisponível')
    expect(table).toContain('CommunicationContactsPhoneCopy')
    expect(read('app/components/communication/contacts/PhoneCopy.vue')).toContain('navigator.clipboard')
    expect(table).toContain('aria-label="Carregando contatos"')
    expect(table).toContain('communication-contacts-stale-error')
    expect(table).toContain('A última leitura válida permanece disponível.')
    expect(table).toContain('<UAvatar')
    expect(table).toContain('rounded-xl')
    expect(table).toContain('gap-4')
    expect(table).toContain('space-y-4')
    expect(table).toContain('communicationContactIdentityCount')
    expect(table).not.toContain('max-w-5xl')
    expect(table).toContain('min-h-0 w-full flex-1 space-y-4 overflow-y-auto')
    expect(table).toContain('md:grid-cols-[minmax(11rem,1fr)_minmax(10rem,1fr)]')
    expect(table).toContain('xl:grid-cols-[minmax(13rem,1.1fr)_minmax(10rem,0.75fr)_minmax(12rem,0.9fr)_auto]')
    expect(table).toContain('label="Detalhes"')
    expect(table).toContain(':aria-label="`Nova conversa com ${communicationContactDisplayName(contact)}`"')
    expect(table).toContain('aria-expanded')
    expect(table).toContain(':aria-controls="expandedId === contact.id')
    expect(table).toContain('discardOpen')
    expect(table).toContain('confirmDiscard')
    expect(table).toContain('const dirty = computed')
    expect(table).toContain('watch(() => props.items')
    expect(table).toContain('hasUnsavedDraft')
    expect(table).toContain('ShellConfirmModal')
    expect(table).not.toContain('CommunicationContactsContactActions')
    expect(toolbar).toContain('i-lucide-arrow-up-down')
    expect(toolbar).not.toContain('i-lucide-arrow-down-up')
    expect(toolbar).toContain('aria-live="polite"')
    expect(modal).toContain('client_contact_id')
    expect(modal).toContain('is_primary')
    expect(modal).toContain('receives_automatic')
    expect(composable).toContain('canManageCommunicationContacts')
    expect(composable).toContain('contactPageSize(initialQuery.per_page)')
    expect(composable).toContain('response.meta.current_page')
    expect(composable).toContain('response.meta.last_page')
    expect(table).not.toContain('ShellDataTable')

    expect(api).toContain('CommunicationContactListParams')
    expect(api).toContain('isSensitiveCommunicationContactSearch')
    expect(api).toContain('`${base}/contacts/search`')
    expect(api).toContain('{ method: \'POST\', body: params }')
    expect(api).toContain('sharedContent')
    expect(api).toContain('conversations: {')
    expect(api).toContain('create: (body: {')
    const types = read('app/types/communication/index.ts')
    expect(types).toContain('is_provisional')
    expect(types).toContain('sort_direction')
    expect(types).toContain('client_name')
    expect(types).toContain('CommunicationSharedContentItem')
    expect(types).toContain('conversation_initiation')
  })

  it('detalhes orquestram composable, seções, deep-link e gates de privacidade', () => {
    const page = read('app/pages/communication/contacts/[id].vue')
    const detail = read('app/composables/useCommunicationContactDetail.ts')
    const profile = read('app/components/communication/contacts/ProfileSection.vue')
    const identities = read('app/components/communication/contacts/IdentitiesSection.vue')
    const links = read('app/components/communication/contacts/LinksSection.vue')
    const privacy = read('app/components/communication/contacts/PrivacySection.vue')

    expect(page).toContain('ShellPagePanel')
    expect(page).toContain('useCommunicationContactDetail')
    expect(page).toContain('CommunicationContactsProfileSection')
    expect(page).toContain('CommunicationContactsContactContext')
    expect(page).toContain('USlideover')
    expect(page).toContain('communication-contact-context-trigger')
    expect(page).toContain('ShellFormModal')
    expect(page).toContain('communication-contact-header-status')
    expect(page).not.toContain('label="Conversas"')
    expect(page).toContain('headerMoreActions')
    expect(page).toContain('communication-contact-more-actions')
    expect(page).toContain('label="Nova conversa"')
    expect(page).toContain('CommunicationNewConversationModal')
    expect(page).toContain('definePageMeta')
    expect(page).not.toContain('if (!canView.value)')
    expect(page).not.toMatch(/<\/template>\s*<CommunicationNewConversationModal/)
    expect(page).toContain('communicationConversationMessagePath(input.conversationId, input.messageId)')
    expect(page).toContain('communicationContactStatusContrastClass')
    expect(page).toContain('COMMUNICATION_CONTACT_SOLID_ACTION_CLASS')
    expect(page).not.toContain('COMMUNICATION_INDEX_PATH')
    expect(page).toContain('canViewCommunication')
    expect(page).toContain('body-class="gap-0 overflow-hidden p-0 sm:p-0"')
    expect(page).toContain('flex h-full min-h-0 w-full flex-col overflow-hidden lg:flex-row')
    expect(page).toContain('lg:flex-[3_1_0%]')
    expect(page).toContain('lg:flex-[2_1_0%]')
    expect(page).not.toContain('lg:max-w-[40.625rem]')
    expect(page).not.toContain('lg:w-[clamp(13rem,28vw,28rem)]')
    expect(page).not.toContain('max-w-6xl')
    const context = read('app/components/communication/contacts/ContactContext.vue')
    expect(context).toContain('min-h-0 flex-1 overflow-y-auto')
    expect(context).toContain('CommunicationSharedContent')
    expect(context).toContain('compact')
    expect(context).toContain(':contact-id="contact.id"')
    expect(profile).toContain('sm:flex-row sm:items-end sm:justify-between')
    expect(page).not.toContain('api.communication.contacts')
    expect(detail).toContain('createCommunicationContactDetail')
    expect(detail).toContain('canManageCommunicationContacts')
    expect(detail).toContain('exportUrl')
    expect(detail).toContain('addIdentity')
    expect(detail).toContain('linkIdentity')
    expect(detail).toContain('unlinkIdentity')
    expect(profile).toContain('communication.manage_contacts')
    expect(identities).toContain('identity.phone')
    expect(links).toContain('client_contact_name')
    expect(page).toContain('communication-contact-purge-confirm')
    expect(privacy).toContain('communication-contact-privacy-purge')
    expect(privacy).toContain('communication.manage_contacts')
  })
})

describe('communication contacts — composable de catálogo', () => {
  function makeCatalog(options: {
    list?: ReturnType<typeof vi.fn>
    create?: ReturnType<typeof vi.fn>
    update?: ReturnType<typeof vi.fn>
    canManage?: boolean
  } = {}) {
    const pushRoute = vi.fn()
    const notify = vi.fn()
    const list = options.list ?? vi.fn().mockResolvedValue({
      data: [sampleContact],
      meta: { current_page: 2, last_page: 4, total: 61 }
    })
    const create = options.create ?? vi.fn()
    const update = options.update ?? vi.fn().mockResolvedValue({ data: sampleContact })
    const catalog = createCommunicationContactsCatalog({
      list,
      create,
      update,
      pushRoute,
      notify,
      sessionEpoch: ref(3),
      canManage: ref(options.canManage ?? true),
      initialQuery: {
        page: '2',
        per_page: '50',
        q: ' ana ',
        is_active: 'all',
        is_provisional: 'true',
        linked: 'false',
        sort: 'created_at',
        sort_direction: 'desc'
      }
    })
    return { catalog, list, create, update, pushRoute, notify }
  }

  it('hidrata query, consome meta completa e expõe loading/empty honestos', async () => {
    const { catalog, list } = makeCatalog()

    const pending = catalog.load()
    expect(catalog.initialLoading.value).toBe(true)
    await pending

    expect(list).toHaveBeenCalledWith({
      page: 2,
      per_page: 50,
      q: 'ana',
      include_inactive: true,
      is_provisional: true,
      linked: false,
      sort: 'created_at',
      sort_direction: 'desc'
    })
    expect(catalog.items.value).toEqual([sampleContact])
    expect(catalog.currentPage.value).toBe(2)
    expect(catalog.lastPage.value).toBe(4)
    expect(catalog.total.value).toBe(61)
    expect(catalog.empty.value).toBe(false)
    catalog.dispose()
  })

  it('mantém a última leitura válida como stale quando o refresh falha', async () => {
    let rejectRefresh: ((reason?: unknown) => void) | undefined
    const list = vi.fn()
      .mockResolvedValueOnce({
        data: [sampleContact],
        meta: { current_page: 1, last_page: 1, total: 1 }
      })
      .mockImplementationOnce(() => new Promise((_resolve, reject) => {
        rejectRefresh = reject
      }))
    const { catalog } = makeCatalog({ list })
    await catalog.load()

    const refresh = catalog.load()
    await nextTick()
    expect(catalog.stale.value).toBe(true)
    expect(catalog.items.value).toEqual([sampleContact])

    rejectRefresh?.(new Error('offline'))
    await refresh
    expect(catalog.items.value).toEqual([sampleContact])
    expect(catalog.loadError.value).toBeTruthy()
    expect(catalog.empty.value).toBe(false)
    catalog.dispose()
  })

  it('mantém telefone somente no body da busca', async () => {
    const { catalog, list } = makeCatalog()
    await catalog.load()

    catalog.onSearch('(11) 99999-8888')
    await vi.waitFor(() => {
      expect(list).toHaveBeenLastCalledWith(expect.objectContaining({
        q: '(11) 99999-8888'
      }))
    })
    catalog.dispose()
  })

  it('bloqueia criação sem manage_contacts e navega após criação autorizada', async () => {
    const denied = makeCatalog({ canManage: false })
    expect(await denied.catalog.createContact({ name: null, phone: '11999998888' })).toBe(false)
    expect(denied.create).not.toHaveBeenCalled()
    denied.catalog.dispose()

    const create = vi.fn().mockResolvedValue({
      data: { ...sampleContact, id: 42 }
    })
    const allowed = makeCatalog({ create })
    expect(await allowed.catalog.createContact({
      name: 'Ana',
      phone: '11999998888',
      client_id: 3,
      client_contact_id: 9,
      is_primary: true,
      receives_automatic: false
    })).toBe(true)
    expect(create).toHaveBeenCalledWith({
      name: 'Ana',
      phone: '11999998888',
      client_id: 3,
      client_contact_id: 9,
      is_primary: true,
      receives_automatic: false
    })
    expect(allowed.pushRoute).toHaveBeenCalledWith({
      path: '/communication/contacts/42'
    })
    allowed.catalog.dispose()
  })

  it('salva o resumo e recarrega a página autoritativa sem remover outro contato', async () => {
    const updated = { ...sampleContact, name: 'Ana Souza', is_active: false }
    const authoritative = { ...updated, name: 'Ana Souza Normalizada' }
    const other = { ...sampleContact, id: 8, name: 'Bruno Lima' }
    const update = vi.fn().mockResolvedValue({ data: updated })
    const list = vi.fn()
      .mockResolvedValueOnce({
        data: [sampleContact, other],
        meta: { current_page: 2, last_page: 4, total: 61 }
      })
      .mockResolvedValueOnce({
        data: [authoritative, other],
        meta: { current_page: 2, last_page: 4, total: 61 }
      })
    const { catalog } = makeCatalog({ update, list })
    await catalog.load()

    expect(await catalog.updateContact(sampleContact, {
      name: 'Ana Souza',
      is_active: false
    })).toBe(true)
    expect(update).toHaveBeenCalledWith(7, {
      name: 'Ana Souza',
      is_active: false
    })
    expect(list).toHaveBeenCalledTimes(2)
    expect(catalog.items.value).toEqual([authoritative, other])
    catalog.dispose()
  })
})

describe('communication contacts — composable de detalhe', () => {
  function makeDetail() {
    const get = vi.fn().mockResolvedValue({ data: sampleContact })
    const update = vi.fn().mockResolvedValue({
      data: { ...sampleContact, name: 'Ana Souza', is_active: false }
    })
    const addIdentity = vi.fn().mockResolvedValue({})
    const linkIdentity = vi.fn().mockResolvedValue({})
    const unlinkIdentity = vi.fn().mockResolvedValue({})
    const purge = vi.fn().mockResolvedValue({})
    const listClientContacts = vi.fn().mockResolvedValue({ data: [] })
    const download = vi.fn().mockResolvedValue(undefined)
    const toast = vi.fn()
    const canManage = ref(true)
    const contactId = ref<number | null>(7)
    const sessionEpoch = ref(1)
    const detail = createCommunicationContactDetail({
      api: {
        get,
        update,
        addIdentity,
        linkIdentity,
        unlinkIdentity,
        exportUrl: id => `/api/v1/communication/contacts/${id}/export`,
        purge,
        listClientContacts
      },
      canManage,
      contactId,
      sessionEpoch,
      toast,
      download
    })
    return {
      detail,
      get,
      update,
      addIdentity,
      linkIdentity,
      unlinkIdentity,
      purge,
      download,
      toast,
      canManage,
      contactId,
      sessionEpoch
    }
  }

  it('carrega perfil, salva via DI e respeita o gate de mutação', async () => {
    const { detail, get, update, canManage } = makeDetail()
    await detail.load()

    expect(get).toHaveBeenCalledWith(7)
    expect(detail.contact.value).toEqual(sampleContact)
    expect(detail.displayName.value).toBe('Ana Silva')
    expect(detail.canMutate.value).toBe(true)

    detail.editName.value = 'Ana Souza'
    detail.editActive.value = false
    await detail.saveProfile()
    expect(update).toHaveBeenCalledWith(7, {
      name: 'Ana Souza',
      is_active: false
    })
    expect(detail.contact.value?.name).toBe('Ana Souza')

    canManage.value = false
    await detail.saveProfile()
    expect(update).toHaveBeenCalledTimes(1)
    detail.dispose()
  })

  it('não exporta nem expurga contato purged e descarta resposta de sessão antiga', async () => {
    let resolveLoad: ((value: { data: CommunicationContact }) => void) | undefined
    const pendingGet = vi.fn().mockImplementation(() => new Promise((resolve) => {
      resolveLoad = resolve
    }))
    const fixture = makeDetail()
    fixture.detail.dispose()
    const detail = createCommunicationContactDetail({
      api: {
        get: pendingGet,
        update: fixture.update,
        addIdentity: fixture.addIdentity,
        linkIdentity: fixture.linkIdentity,
        unlinkIdentity: fixture.unlinkIdentity,
        exportUrl: id => `/api/v1/communication/contacts/${id}/export`,
        purge: fixture.purge,
        listClientContacts: vi.fn().mockResolvedValue({ data: [] })
      },
      canManage: fixture.canManage,
      contactId: fixture.contactId,
      sessionEpoch: fixture.sessionEpoch,
      toast: fixture.toast,
      download: fixture.download
    })

    const pending = detail.load()
    fixture.sessionEpoch.value += 1
    expect(resolveLoad).toBeDefined()
    resolveLoad!({ data: sampleContact })
    await pending
    expect(detail.contact.value).toBeNull()

    detail.contact.value = { ...sampleContact, purged_at: '2026-07-28T12:00:00Z' }
    await detail.exportContact()
    detail.openPurge()
    await detail.confirmPurge()
    expect(fixture.download).not.toHaveBeenCalled()
    expect(fixture.purge).not.toHaveBeenCalled()
    detail.dispose()
  })

  it('não aplica resposta de mutação iniciada em outro contexto de Tenant', async () => {
    let resolveUpdate: ((value: { data: CommunicationContact }) => void) | undefined
    const fixture = makeDetail()
    fixture.update.mockImplementationOnce(() => new Promise((resolve) => {
      resolveUpdate = resolve
    }))

    await fixture.detail.load()
    fixture.detail.editName.value = 'Resposta antiga'
    const pendingSave = fixture.detail.saveProfile()

    fixture.sessionEpoch.value += 1
    await nextTick()
    await vi.waitFor(() => {
      expect(fixture.get).toHaveBeenCalledTimes(2)
      expect(fixture.detail.contact.value?.name).toBe('Ana Silva')
    })

    expect(resolveUpdate).toBeDefined()
    resolveUpdate!({
      data: { ...sampleContact, name: 'Resposta antiga' }
    })
    await pendingSave

    expect(fixture.detail.contact.value?.name).toBe('Ana Silva')
    expect(fixture.toast).not.toHaveBeenCalledWith('Contato atualizado.', 'success')
    expect(fixture.detail.saving.value).toBe(false)
    fixture.detail.dispose()
  })
})
