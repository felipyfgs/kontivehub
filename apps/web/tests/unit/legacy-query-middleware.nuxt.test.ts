import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import legacyQueryMiddleware from '../../app/middleware/00.legacy-query.global'
import {
  COMMUNICATION_SURFACES,
  SURFACE_NAVIGATION,
  WORK_SURFACES,
  clearSurfaceNavigationState,
  consumeSurfaceNavigationIntent
} from '../../app/composables/useSurfaceNavigationState'
import { consumeResetPasswordCredentials } from '../../app/utils/reset-password'

const mocks = vi.hoisted(() => ({
  navigateTo: vi.fn()
}))

mockNuxtImport('navigateTo', () => mocks.navigateTo)

async function run(
  path: string,
  query: Record<string, unknown>,
  params: Record<string, unknown> = {},
  hash = ''
) {
  return await legacyQueryMiddleware(
    { path, query, params, hash } as never,
    { path: '/', query: {}, params: {} } as never
  )
}

function firstCanonicalTarget(path: string, target: string): string {
  return path === target ? `${target}#legacy-canonical` : target
}

describe('middleware de queries legadas', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    clearSurfaceNavigationState()
  })

  it.each([
    {
      title: 'Atendimento e mensagem',
      path: '/communication/conversations/89',
      query: { unassigned: '1', message_id: '501' },
      params: { id: '89' },
      target: '/communication/conversations/89/messages/501',
      surface: COMMUNICATION_SURFACES.workspace,
      expected: expect.objectContaining({ unassignedOnly: true })
    },
    {
      title: 'Fila de tarefas',
      path: '/work/tasks',
      query: { tab: 'atrasadas', client_id: '7', page: '2' },
      target: '/work/tasks',
      surface: WORK_SURFACES.queue,
      expected: expect.objectContaining({ tab: 'atrasadas', client_id: 7, page: 2 })
    },
    {
      title: 'Processos',
      path: '/work/processes',
      query: { group: 'client', competence: '2026-07' },
      target: '/work/processes',
      surface: WORK_SURFACES.processes,
      expected: expect.objectContaining({ group: 'client', competence: '2026-07' })
    },
    {
      title: 'Calendário',
      path: '/work/calendar',
      query: { view: 'week', date: '2026-07-30', department_id: '4' },
      target: '/work/calendar/week/2026-07-30',
      surface: WORK_SURFACES.calendar,
      expected: expect.objectContaining({ department_id: 4 })
    },
    {
      title: 'Clientes',
      path: '/clients',
      query: { q: 'Alfa', status: 'active', sort: 'tax_regime' },
      target: '/clients',
      surface: SURFACE_NAVIGATION.clients,
      expected: expect.objectContaining({ q: 'Alfa', status: 'active', sort: 'tax_regime' })
    },
    {
      title: 'Documentos por cliente',
      path: '/docs/catalog',
      query: { client_id: '42', kind: 'cte', status: 'AUTHORIZED' },
      target: '/docs/catalog/client/42',
      surface: SURFACE_NAVIGATION.documents.catalog,
      expected: expect.objectContaining({ client_id: '42', kind: 'CTE', status: 'AUTHORIZED' })
    },
    {
      title: 'Fechamento',
      path: '/closing',
      query: { competence: '2026-07', band: 'ATTENTION', page: '3' },
      target: '/closing',
      surface: SURFACE_NAVIGATION.closing,
      expected: expect.objectContaining({ competence: '2026-07', band: 'ATTENTION', page: 3 })
    },
    {
      title: 'Saúde',
      path: '/health',
      query: { type: 'cte_656', severity: 'high' },
      target: '/health/type/cte_656',
      surface: SURFACE_NAVIGATION.health,
      expected: { severity: 'high' }
    },
    {
      title: 'Exportações',
      path: '/exports',
      query: { new: '1', page: '2' },
      target: '/exports/new',
      surface: SURFACE_NAVIGATION.exports,
      expected: expect.objectContaining({ page: 2 })
    }
  ])('canonicaliza $title e publica intenção normalizada', async ({ path, query, params, target, surface, expected }) => {
    await run(path, query, params)

    expect(mocks.navigateTo).toHaveBeenLastCalledWith(
      firstCanonicalTarget(path, target),
      { replace: true }
    )
    expect(consumeSurfaceNavigationIntent(surface)).toEqual(expected)
    expect(consumeSurfaceNavigationIntent(surface)).toBeNull()
  })

  it('converte seção de processo em path e descarta from', async () => {
    await run('/work/processes/12', { section: 'comentarios', from: '/work/processes' }, { id: '12' })
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/work/processes/12/comments', { replace: true })
  })

  it('consome credenciais legadas antes de montar a página de reset', async () => {
    await run('/reset-password', {
      token: 'abc+123',
      email: 'pessoa+teste@example.test'
    })

    expect(mocks.navigateTo).toHaveBeenLastCalledWith(
      '/reset-password#legacy-canonical',
      { replace: true }
    )
    expect(consumeResetPasswordCredentials(
      { pathname: '/reset-password', hash: '' },
      { replaceState: vi.fn() }
    )).toEqual({
      token: 'abc+123',
      email: 'pessoa+teste@example.test'
    })
  })

  it('consome o fragmento novo antes de montar a página de reset', async () => {
    await run(
      '/reset-password',
      {},
      {},
      '#token=abc%2B123&email=pessoa%2Bteste%40example.test'
    )

    expect(mocks.navigateTo).toHaveBeenLastCalledWith(
      '/reset-password#legacy-canonical',
      { replace: true }
    )
    expect(consumeResetPasswordCredentials(
      { pathname: '/reset-password', hash: '' },
      { replaceState: vi.fn() }
    )).toEqual({
      token: 'abc+123',
      email: 'pessoa+teste@example.test'
    })
  })

  it('não mantém telefone de bookmark do catálogo de contatos', async () => {
    await run('/communication/contacts', { q: '(11) 99999-8888', page: '2' })
    expect(consumeSurfaceNavigationIntent(COMMUNICATION_SURFACES.contacts)).toEqual(
      expect.objectContaining({ q: '', page: 2 })
    )
  })

  it('descarta chaves desconhecidas sem publicar intenção', async () => {
    await run('/communication/conversations/89', { desconhecida: 'segredo' }, { id: '89' })

    expect(mocks.navigateTo).toHaveBeenLastCalledWith(
      '/communication/conversations/89#legacy-canonical',
      { replace: true }
    )
    expect(consumeSurfaceNavigationIntent(COMMUNICATION_SURFACES.workspace)).toBeNull()
  })

  it('remove o fragmento técnico na segunda etapa da canonicalização', async () => {
    await run('/work/tasks', {}, {}, '#legacy-canonical')

    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/work/tasks', { replace: true })
  })
})
