import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { MeUser } from '~/types/api'
import type { CommunicationContact } from '~/types/communication'
import { createCommunicationContactsCatalog } from '~/composables/useCommunicationContactsCatalog'
import {
  buildCommunicationContactListQuery,
  communicationContactDisplayName,
  communicationContactEmptyKind,
  communicationContactLinkedClientNames,
  communicationContactPrimaryMasked,
  hasActiveCommunicationContactFilters,
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
  it('expõe paths canônicos da ficha e do catálogo', () => {
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
    expect(communicationContactPrimaryMasked(sampleContact)).toBe('119••••4321')
    expect(communicationContactLinkedClientNames(sampleContact)).toEqual(['Cliente Alpha'])
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

  it('catálogo extraído usa ShellDataTable, cards, toolbar, modal e sort whitelist', () => {
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
    expect(toolbar).toContain('ShellListFilterToolbar')
    expect(table).toContain('ShellDataTable')
    expect(modal).toContain('ShellFormModal')
    expect(table).toContain('#empty')
    expect(table).toContain('ShellListEmpty')
    expect(table).toContain(':manual-sorting="true"')
    expect(table).toContain('sortHeader(\'Nome\'')
    expect(table).toContain('sortHeader(\'ID\'')
    expect(table).toContain('primary-column-id="name"')
    expect(table).toContain('status-column-id="status"')
    expect(table).toContain('communication-contacts-stale')
    expect(composable).toContain('canManageCommunicationContacts')
    expect(composable).toContain('response.meta.current_page')
    expect(composable).toContain('response.meta.last_page')
    expect(table).not.toContain('sortHeader(\'WhatsApp\'')
    expect(table).not.toContain('sortHeader(\'Clientes\'')
    expect(table).not.toContain('sortHeader(\'Situação\'')

    expect(api).toContain('CommunicationContactListParams')
    const types = read('app/types/communication.ts')
    expect(types).toContain('is_provisional')
    expect(types).toContain('sort_direction')
    expect(types).toContain('client_name')
  })

  it('ficha gates mutações e usa confirmação de purge', () => {
    const page = read('app/pages/communication/contacts/[id].vue')
    expect(page).toContain('ShellPagePanel')
    expect(page).toContain('ShellSectionHeader')
    expect(page).toContain('ShellSectionCard')
    expect(page).toContain('ShellFormModal')
    expect(page).toContain('ShellConfirmModal')
    expect(page).toContain('canManageCommunicationContacts')
    expect(page).toContain('communication.manage_contacts')
    expect(page).toContain('client_name')
    expect(page).toContain('client_contact_name')
    expect(page).toContain('communication-contact-purge-confirm')
    expect(page).toContain('exportUrl')
    expect(page).toContain('addIdentity')
    expect(page).toContain('linkIdentity')
    expect(page).toContain('unlinkIdentity')
  })
})

describe('communication contacts — composable de catálogo', () => {
  function makeCatalog(options: {
    list?: ReturnType<typeof vi.fn>
    create?: ReturnType<typeof vi.fn>
    canManage?: boolean
  } = {}) {
    const replaceRoute = vi.fn()
    const pushRoute = vi.fn()
    const notify = vi.fn()
    const list = options.list ?? vi.fn().mockResolvedValue({
      data: [sampleContact],
      meta: { current_page: 2, last_page: 4, total: 61 }
    })
    const create = options.create ?? vi.fn()
    const catalog = createCommunicationContactsCatalog({
      list,
      create,
      replaceRoute,
      pushRoute,
      notify,
      sessionEpoch: ref(3),
      canManage: ref(options.canManage ?? true),
      initialQuery: {
        page: '2',
        q: ' ana ',
        is_active: 'all',
        is_provisional: 'true',
        linked: 'false',
        sort: 'created_at',
        sort_direction: 'desc'
      }
    })
    return { catalog, list, create, replaceRoute, pushRoute, notify }
  }

  it('hidrata query, consome meta completa e expõe loading/empty honestos', async () => {
    const { catalog, list } = makeCatalog()

    const pending = catalog.load()
    expect(catalog.initialLoading.value).toBe(true)
    await pending

    expect(list).toHaveBeenCalledWith({
      page: 2,
      per_page: 20,
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
      client_id: 3
    })).toBe(true)
    expect(create).toHaveBeenCalledWith({
      name: 'Ana',
      phone: '11999998888',
      client_id: 3
    })
    expect(allowed.pushRoute).toHaveBeenCalledWith('/communication/contacts/42')
    allowed.catalog.dispose()
  })
})
