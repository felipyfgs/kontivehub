import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { computed, effectScope, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { MeUser } from '~/types/api'
import type { CannedResponse } from '~/types/communication/quick-responses'
import { createCommunicationQuickResponsesCatalog } from '~/composables/useCommunicationQuickResponsesCatalog'
import {
  buildCannedResponseListQuery,
  cannedResponseEmptyKind,
  filterCannedResponsesByShortcut,
  findCannedSlashToken,
  isValidCannedShortcut,
  normalizeCannedShortcut,
  replaceCannedSlashToken,
  shouldHandleCannedAutocompleteKey
} from '~/utils/communication-quick-responses'
import {
  COMMUNICATION_QUICK_RESPONSES_PATH,
  isCommunicationQuickResponsesNavActive
} from '~/utils/communication-routes'
import { mainDestinations } from '~/utils/navigation'
import {
  canManageCommunication,
  canManageCommunicationContacts,
  canManageCommunicationQuickReplies,
  canViewCommunication
} from '~/utils/permissions'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)
const read = (...parts: string[]) => readFileSync(root(...parts), 'utf8')

const sample: CannedResponse[] = [
  {
    id: 1,
    title: 'Saudação',
    shortcut: 'saudacao',
    body: 'Olá {{contato.nome}}',
    is_active: true,
    lock_version: 1
  },
  {
    id: 2,
    title: 'Encerrar',
    shortcut: 'encerrar',
    body: 'Até logo',
    is_active: false,
    lock_version: 2
  },
  {
    id: 3,
    title: 'Status',
    shortcut: 'status',
    body: 'Em análise',
    is_active: true,
    lock_version: 1
  }
]

describe('communication quick-responses — helpers e autocomplete', () => {
  it('normaliza atalho e monta query de gestão', () => {
    expect(normalizeCannedShortcut(' Saudacao ')).toBe('saudacao')
    expect(isValidCannedShortcut('ok_atalho.1')).toBe(true)
    expect(isValidCannedShortcut('Com Espaco')).toBe(false)

    expect(buildCannedResponseListQuery({
      q: ' encer ',
      isActive: 'false',
      page: 2,
      perPage: 10
    })).toEqual({
      manage: 1,
      page: 2,
      per_page: 10,
      q: 'encer',
      is_active: false
    })

    expect(cannedResponseEmptyKind({ q: '', isActive: 'all' })).toBe('empty')
    expect(cannedResponseEmptyKind({ q: 'x', isActive: 'all' })).toBe('filtered')
  })

  it('detecta token /atalho, filtra ativos e substitui com segurança IME', () => {
    expect(findCannedSlashToken('oi /sau', 7)).toEqual({
      start: 3,
      end: 7,
      query: 'sau'
    })
    expect(findCannedSlashToken('oi /sau mais', 12)).toBeNull()
    expect(filterCannedResponsesByShortcut(sample, 'sau').map(item => item.shortcut))
      .toEqual(['saudacao'])
    expect(filterCannedResponsesByShortcut(sample, '').every(item => item.is_active)).toBe(true)
    expect(replaceCannedSlashToken('oi /sau', { start: 3, end: 7, query: 'sau' }, 'Olá Ana'))
      .toBe('oi Olá Ana')

    expect(shouldHandleCannedAutocompleteKey({ key: 'Enter', isComposing: true })).toBe(false)
    expect(shouldHandleCannedAutocompleteKey({ key: 'Enter', keyCode: 229 })).toBe(false)
    expect(shouldHandleCannedAutocompleteKey({ key: 'ArrowDown' })).toBe(true)
  })
})

describe('communication quick-responses — permissões, nav e API client', () => {
  it('separa manage_quick_replies de inboxes/contacts', () => {
    const viewOnly = {
      id: 1,
      role: 'OPERATOR',
      effective_permissions: ['communication.view']
    } as MeUser
    const inboxOnly = {
      id: 2,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_inboxes']
    } as MeUser
    const contactsOnly = {
      id: 3,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_contacts']
    } as MeUser
    const quick = {
      id: 4,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_quick_replies']
    } as MeUser

    expect(canViewCommunication(viewOnly)).toBe(true)
    expect(canManageCommunicationQuickReplies(viewOnly)).toBe(false)
    expect(canManageCommunication(inboxOnly)).toBe(true)
    expect(canManageCommunicationQuickReplies(inboxOnly)).toBe(false)
    expect(canManageCommunicationContacts(contactsOnly)).toBe(true)
    expect(canManageCommunicationQuickReplies(contactsOnly)).toBe(false)
    expect(canManageCommunicationQuickReplies(quick)).toBe(true)
  })

  it('expõe Respostas rápidas no grupo Atendimento com communication.view', () => {
    expect(COMMUNICATION_QUICK_RESPONSES_PATH).toBe('/communication/quick-responses')
    expect(isCommunicationQuickResponsesNavActive('/communication/quick-responses')).toBe(true)

    const user = {
      id: 9,
      role: 'OPERATOR',
      effective_permissions: ['communication.view']
    } as MeUser
    const group = mainDestinations(user, { path: '/communication/quick-responses' })
      .find(item => item.id === 'communication')
    expect(group?.type).toBe('trigger')
    expect(group?.children?.map(item => item.label)).toEqual([
      'Conversas',
      'Contatos',
      'Respostas rápidas',
      'Fluxos'
    ])
    expect(group?.children?.find(item => item.id === 'communication-quick-responses'))
      .toMatchObject({
        to: '/communication/quick-responses',
        active: true
      })
  })

  it('página e cliente cobrem gestão, CTA do catálogo e autocomplete do composer', () => {
    const page = read('app/pages/communication/quick-responses/index.vue')
    const catalogComposable = read('app/composables/useCommunicationQuickResponsesCatalog.ts')
    const catalogTable = read(
      'app/components/communication/quick-responses/CatalogTable.vue'
    )
    const editorModal = read(
      'app/components/communication/quick-responses/EditorModal.vue'
    )
    const duplicateModal = read(
      'app/components/communication/quick-responses/DuplicateModal.vue'
    )
    const deactivateModal = read(
      'app/components/communication/quick-responses/DeactivateModal.vue'
    )
    const api = read('app/composables/api/createCommunicationApi.ts')
    const catalog = read('app/components/communication/CatalogAdminPanel.vue')
    const composer = read('app/components/communication/Composer.vue')

    expect(page).toContain('useCommunicationQuickResponsesCatalog')
    expect(page).toContain('CommunicationQuickResponsesCatalogTable')
    expect(catalogTable).toContain('ShellDataTable')
    expect(catalogTable).toContain('communication-quick-responses-stale')
    expect(catalogComposable).toContain('canManageCommunicationQuickReplies')
    expect(catalogComposable).toContain('listCannedResponses')
    expect(catalogComposable).toContain('duplicateCannedResponse')
    expect(catalogComposable).toContain('deactivateCannedResponse')
    expect(catalogComposable).toContain('lock_version')
    expect(catalogComposable).toContain('version_conflict')
    expect(editorModal).toContain('ShellFormModal')
    expect(duplicateModal).toContain('ShellFormModal')
    expect(deactivateModal).toContain('ShellConfirmModal')

    expect(api).toContain('listCannedResponses')
    expect(api).toContain('manage: 1')
    expect(api).toContain('/duplicate')
    expect(api).toContain('/deactivate')
    expect(api).toContain('/render')
    expect(api).toContain('lock_version')

    expect(catalog).toContain('COMMUNICATION_QUICK_RESPONSES_PATH')
    expect(catalog).toContain('communication-catalog-quick-responses-cta')
    expect(catalog).not.toContain('createCanned')
    expect(catalog).not.toContain('deleteCanned')

    expect(composer).toContain('communication-composer-canned-listbox')
    expect(composer).toContain('communication-composer-canned-touch')
    expect(composer).toContain('renderCannedResponse')
    expect(composer).toContain('conversationId')
    expect(composer).toContain('compositionstart')
    expect(composer).toContain('role="listbox"')
  })
})

function createApiMock() {
  return {
    cannedResponses: vi.fn(async () => ({ data: sample.filter(item => item.is_active) })),
    listCannedResponses: vi.fn(async () => ({
      data: sample,
      meta: { current_page: 1, last_page: 1, total: sample.length }
    })),
    createCannedResponse: vi.fn(async () => ({ data: sample[0] })),
    updateCannedResponse: vi.fn(async () => ({ data: sample[0] })),
    duplicateCannedResponse: vi.fn(async () => ({ data: sample[0] })),
    deactivateCannedResponse: vi.fn(async () => ({ data: sample[0] }))
  }
}

function createCatalog(options: {
  manage?: boolean
  query?: Record<string, unknown>
  api?: ReturnType<typeof createApiMock>
} = {}) {
  const scope = effectScope()
  const api = options.api ?? createApiMock()
  const sessionEpoch = ref(0)
  const toasts: Array<{ title: string, color: string }> = []
  const catalog = scope.run(() => createCommunicationQuickResponsesCatalog({
    api,
    canManage: computed(() => options.manage ?? true),
    initialQuery: options.query ?? {},
    sessionEpoch,
    toast: (title, color) => {
      toasts.push({ title, color })
    }
  }))

  if (!catalog) throw new Error('Falha ao criar catálogo de teste.')
  return { api, catalog, scope, sessionEpoch, toasts }
}

describe('communication quick-responses — catálogo extraído', () => {
  it('separa listagem paginada do gestor e leitura somente de ativos', async () => {
    const manager = createCatalog({
      query: { page: '2', per_page: '10', q: ' encer ', is_active: 'false' }
    })
    await manager.catalog.load()

    expect(manager.api.listCannedResponses).toHaveBeenCalledWith({
      manage: 1,
      page: 2,
      per_page: 10,
      q: 'encer',
      is_active: false
    })
    expect(manager.api.cannedResponses).not.toHaveBeenCalled()
    expect(manager.catalog.total.value).toBe(3)

    const reader = createCatalog({ manage: false, query: { q: ' sau ', is_active: 'false' } })
    await reader.catalog.load()

    expect(reader.api.cannedResponses).toHaveBeenCalledWith({ q: 'sau' })
    expect(reader.api.listCannedResponses).not.toHaveBeenCalled()
    expect(reader.catalog.items.value.every(item => item.is_active)).toBe(true)
    reader.catalog.onStructuredFilters([{
      key: 'is_active',
      kind: 'option',
      label: 'Situação',
      value: 'false',
      displayValue: 'Inativas'
    }])
    expect(reader.catalog.isActive.value).toBe('all')

    manager.scope.stop()
    reader.scope.stop()
  })

  it('sincroniza busca, filtro e paginação 10/20/50 com a rota', async () => {
    const { catalog, scope } = createCatalog()

    catalog.page.value = 3
    catalog.setPerPage(50)
    await nextTick()
    expect(catalog.page.value).toBe(1)
    expect(catalog.perPage.value).toBe(50)

    catalog.onSearch('saudação')
    await nextTick()

    catalog.clearFilters()
    await nextTick()
    scope.stop()
  })

  it('envia lock_version no update e mantém o editor aberto no conflito 409', async () => {
    const api = createApiMock()
    api.updateCannedResponse.mockRejectedValueOnce({
      data: { code: 'version_conflict', message: 'Versão divergente.' }
    })
    const { catalog, scope, toasts } = createCatalog({ api })
    catalog.openEdit(sample[1]!)
    catalog.editorTitle.value = 'Encerramento'

    await catalog.submitEditor()

    expect(api.updateCannedResponse).toHaveBeenCalledWith(2, {
      title: 'Encerramento',
      shortcut: 'encerrar',
      body: 'Até logo',
      is_active: false,
      lock_version: 2
    })
    expect(catalog.editorOpen.value).toBe(true)
    expect(catalog.editorError.value).toContain('alterada por outra pessoa')
    expect(toasts.at(-1)?.color).toBe('warning')
    scope.stop()
  })

  it('preserva as mutações de criar, duplicar e desativar para gestor', async () => {
    const { api, catalog, scope } = createCatalog()
    catalog.openCreate()
    catalog.editorTitle.value = 'Cobrança'
    catalog.editorShortcut.value = ' COBRANCA '
    catalog.editorBody.value = 'Olá {{contato.nome}}'
    await catalog.submitEditor()
    expect(api.createCannedResponse).toHaveBeenCalledWith({
      title: 'Cobrança',
      shortcut: 'cobranca',
      body: 'Olá {{contato.nome}}',
      is_active: true
    })

    catalog.openDuplicate(sample[0]!)
    catalog.duplicateShortcut.value = ' SAUDACAO.2 '
    await catalog.submitDuplicate()
    expect(api.duplicateCannedResponse).toHaveBeenCalledWith(1, { shortcut: 'saudacao.2' })

    catalog.openDeactivate(sample[0]!)
    await catalog.confirmDeactivate()
    expect(api.deactivateCannedResponse).toHaveBeenCalledWith(1)
    scope.stop()
  })

  it('mantém dados válidos como stale após falha de refresh e oferece retry', async () => {
    const api = createApiMock()
    const { catalog, scope } = createCatalog({ api })
    await catalog.load()
    api.listCannedResponses.mockRejectedValueOnce(new Error('offline'))

    await catalog.load()

    expect(catalog.items.value).toEqual(sample)
    expect(catalog.total.value).toBe(3)
    expect(catalog.loadError.value).toBe('Falha ao listar respostas rápidas.')
    expect(catalog.stale.value).toBe(true)
    scope.stop()
  })

  it('descarta resposta atrasada e reinicia dados ao trocar de Tenant', async () => {
    let resolveFirst: ((value: {
      data: CannedResponse[]
      meta: { current_page: number, last_page: number, total: number }
    }) => void) | undefined
    const api = createApiMock()
    api.listCannedResponses
      .mockImplementationOnce(() => new Promise((resolve) => {
        resolveFirst = resolve
      }))
      .mockResolvedValueOnce({
        data: [sample[2]!],
        meta: { current_page: 1, last_page: 1, total: 1 }
      })
    const { catalog, scope, sessionEpoch } = createCatalog({ api })
    const first = catalog.load()
    const second = catalog.load()
    await second
    resolveFirst?.({
      data: [sample[0]!],
      meta: { current_page: 1, last_page: 1, total: 1 }
    })
    await first
    expect(catalog.items.value).toEqual([sample[2]])

    sessionEpoch.value += 1
    await nextTick()
    expect(catalog.q.value).toBe('')
    expect(catalog.page.value).toBe(1)
    scope.stop()
  })
})
