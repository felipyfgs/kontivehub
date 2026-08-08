import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import type { Flow } from '~/types/communication/flows'
import {
  communicationFlowEmptyKind,
  communicationFlowStatusColor,
  communicationFlowStatusLabel,
  communicationFlowsMutationBlocked,
  filterCommunicationFlows,
  formatFlowGraphJson,
  paginateCommunicationFlows,
  parseFlowGraphJson
} from '~/utils/communication-flows'
import {
  COMMUNICATION_FLOWS_PATH,
  communicationFlowEditorPath,
  communicationFlowPath,
  isCommunicationFlowsNavActive,
  isCommunicationInboxNavActive,
  parseCommunicationFlowId
} from '~/utils/communication-routes'
import { mainDestinations } from '~/utils/navigation'
import {
  canManageCommunication,
  canManageCommunicationContacts,
  canManageCommunicationFlows,
  canManageCommunicationQuickReplies,
  canViewCommunication
} from '~/utils/permissions'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)
const read = (...parts: string[]) => readFileSync(root(...parts), 'utf8')

const sampleFlows: Flow[] = [
  {
    id: 1,
    name: 'Triagem inicial',
    status: 'paused',
    lock_version: 1,
    updated_at: '2026-07-23T10:00:00Z'
  },
  {
    id: 2,
    name: 'Handoff comercial',
    status: 'active',
    lock_version: 3,
    updated_at: '2026-07-23T11:00:00Z'
  }
]

describe('communication flows — helpers', () => {
  it('filtra, pagina e deriva empty/status', () => {
    expect(communicationFlowStatusLabel('paused')).toBe('Pausado')
    expect(communicationFlowStatusLabel(sampleFlows[1]!)).toBe('Ativo')
    expect(communicationFlowStatusColor('active')).toBe('success')
    expect(communicationFlowEmptyKind({ q: '', status: 'all' })).toBe('empty')
    expect(communicationFlowEmptyKind({ q: 'x', status: 'all' })).toBe('filtered')

    expect(filterCommunicationFlows(sampleFlows, { q: 'triagem', status: 'all' }).map(f => f.id))
      .toEqual([1])
    expect(filterCommunicationFlows(sampleFlows, { q: '', status: 'active' }).map(f => f.id))
      .toEqual([2])

    expect(paginateCommunicationFlows(sampleFlows, 1, 1)).toEqual({
      rows: [sampleFlows[0]],
      total: 2
    })
  })

  it('serializa e valida JSON de grafo', () => {
    expect(formatFlowGraphJson(null)).toContain('"nodes"')
    expect(parseFlowGraphJson('{')).toMatchObject({ ok: false })
    expect(parseFlowGraphJson('{"nodes":[],"edges":[]}')).toEqual({
      ok: true,
      graph: { nodes: [], edges: [] }
    })
    expect(parseFlowGraphJson('{"nodes":1,"edges":[]}')).toMatchObject({ ok: false })
  })

  it('bloqueia mutação fail-closed e sem permissão', () => {
    expect(communicationFlowsMutationBlocked(false, true))
      .toContain('desabilitada')
    expect(communicationFlowsMutationBlocked(true, false))
      .toContain('manage_flows')
    expect(communicationFlowsMutationBlocked(true, true)).toBeNull()
  })
})

describe('communication flows — deep-link fail-closed', () => {
  it('executa o middleware de permissão antes de instanciar loaders de lista, detalhe e editor', () => {
    const guard = read('app/utils/communication-route-access.ts')
    expect(guard).toContain('canViewCommunication(identity)')
    expect(guard).toContain('identity?.context_status !== \'ok\'')
    expect(guard).toContain('!identity.current_tenant')

    for (const path of [
      'app/pages/communication/flows/index.vue',
      'app/pages/communication/flows/[id]/index.vue',
      'app/pages/communication/flows/[id]/editor.vue',
      'app/pages/communication/quick-responses/index.vue'
    ]) {
      const page = read(path)
      expect(page).toContain('middleware: [requireCommunicationView]')
      expect(page).not.toContain('await navigateTo(\'/\')')
    }
  })
})

describe('communication flows — permissões, nav e rotas', () => {
  it('separa manage_flows de inboxes/contacts/quick_replies', () => {
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
    const quickOnly = {
      id: 4,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_quick_replies']
    } as MeUser
    const flows = {
      id: 5,
      role: 'OPERATOR',
      effective_permissions: ['communication.view', 'communication.manage_flows']
    } as MeUser

    expect(canViewCommunication(viewOnly)).toBe(true)
    expect(canManageCommunicationFlows(viewOnly)).toBe(false)
    expect(canManageCommunication(inboxOnly)).toBe(true)
    expect(canManageCommunicationFlows(inboxOnly)).toBe(false)
    expect(canManageCommunicationContacts(contactsOnly)).toBe(true)
    expect(canManageCommunicationFlows(contactsOnly)).toBe(false)
    expect(canManageCommunicationQuickReplies(quickOnly)).toBe(true)
    expect(canManageCommunicationFlows(quickOnly)).toBe(false)
    expect(canManageCommunicationFlows(flows)).toBe(true)
  })

  it('expõe Fluxos no grupo Atendimento e paths canônicos', () => {
    expect(COMMUNICATION_FLOWS_PATH).toBe('/communication/flows')
    expect(communicationFlowPath(9)).toBe('/communication/flows/9')
    expect(communicationFlowEditorPath(9)).toBe('/communication/flows/9/editor')
    expect(parseCommunicationFlowId('4')).toBe(4)
    expect(parseCommunicationFlowId('x')).toBeNull()
    expect(isCommunicationFlowsNavActive('/communication/flows/2')).toBe(true)
    expect(isCommunicationInboxNavActive('/communication/flows')).toBe(false)

    const user = {
      id: 9,
      role: 'OPERATOR',
      effective_permissions: ['communication.view']
    } as MeUser
    const group = mainDestinations(user, { path: '/communication/flows' })
      .find(item => item.id === 'communication')
    expect(group?.children?.map(item => item.label)).toEqual([
      'Conversas',
      'Contatos',
      'Respostas rápidas',
      'Fluxos'
    ])
    expect(group?.children?.find(item => item.id === 'communication-flows'))
      .toMatchObject({
        to: '/communication/flows',
        active: true
      })
  })
})

describe('communication flows — superfícies e contrato Shell', () => {
  it('lista e detalhe usam Shell, gate manage_flows; editor visual em rota dedicada', () => {
    const list = read('app/pages/communication/flows/index.vue')
    const detail = read('app/pages/communication/flows/[id]/index.vue')
    const api = read('app/composables/api/createCommunicationApi.ts')
    const types = read('app/types/communication/flows.ts')

    expect(list).toContain('ShellPagePanel')
    expect(list).toContain('ShellDataTable')
    expect(list).toContain('ShellListFilterToolbar')
    expect(list).toContain('useCommunicationFlowsCatalog')
    expect(list).toContain('catalog.flowsEnabled')
    expect(list).toContain('communication-flows-disabled-alert')
    expect(list).toContain('CommunicationFlowsCatalogTable')
    expect(list).toContain('CommunicationFlowsCatalogModals')
    expect(list).not.toContain('@vue-flow')
    expect(list).not.toContain('VueFlow')

    expect(detail).toContain('ShellSectionHeader')
    expect(detail).toContain('ShellSectionCard')
    expect(detail).toContain('communication-flow-draft-json')
    expect(detail).toContain('communication-flow-validate')
    expect(detail).toContain('communication-flow-publish')
    expect(detail).toContain('communicationFlowEditorPath')
    expect(detail).toContain('canManageCommunicationFlows')
    expect(detail).toContain('enableBinding')
    expect(detail).toContain('disableBinding')
    expect(detail).toContain('listRuns')
    expect(detail).not.toContain('@vue-flow')
    expect(detail).not.toContain('VueFlow')

    expect(api).toContain('flows:')
    expect(api).toContain('${base}/flows')
    expect(api).toContain('/draft')
    expect(api).toContain('/validate')
    expect(api).toContain('/publish')
    expect(api).toContain('/flow-bindings/')
    expect(api).toContain('listRuns')
    expect(api).toContain('lock_version')

    expect(types).toContain('Flow')
    expect(types).toContain('FlowDraft')
    expect(types).toContain('FlowBinding')
    expect(types).toContain('flows_enabled')
  })
})
