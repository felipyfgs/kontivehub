import { describe, expect, it } from 'vitest'
import {
  coerceWorkQueueTabForView,
  defaultWorkQueueTab,
  parseWorkQueueQuery,
  parseWorkQueueView,
  serializeWorkQueueQuery,
  type WorkQueueFilters
} from '../../app/composables/useWorkQueueFilters'

const base: WorkQueueFilters = {
  tab: 'open',
  q: '',
  department_id: null,
  assignee_membership_id: null,
  client_id: null,
  scope: 'default',
  page: 1,
  per_page: 10,
  view: 'fila',
  sort: null,
  direction: null
}

describe('work-queue-filters view', () => {
  it('parseWorkQueueView trata lista, kanban e default fila', () => {
    expect(parseWorkQueueView('lista')).toBe('lista')
    expect(parseWorkQueueView(['lista'])).toBe('lista')
    expect(parseWorkQueueView('kanban')).toBe('kanban')
    expect(parseWorkQueueView(['kanban'])).toBe('kanban')
    expect(parseWorkQueueView('fila')).toBe('fila')
    expect(parseWorkQueueView(undefined)).toBe('fila')
    expect(parseWorkQueueView('outro')).toBe('fila')
  })

  it('serializa view=lista|kanban e omite na Fila', () => {
    expect(serializeWorkQueueQuery({ ...base, view: 'fila' }).view).toBeUndefined()
    expect(serializeWorkQueueQuery({ ...base, view: 'lista' }).view).toBe('lista')
    expect(serializeWorkQueueQuery({ ...base, view: 'kanban' }).view).toBe('kanban')
  })

  it('preserva filtros ao round-trip com view=lista', () => {
    const query = serializeWorkQueueQuery({
      ...base,
      tab: 'atrasadas',
      q: 'xml',
      department_id: 3,
      client_id: 9,
      scope: 'mine',
      page: 2,
      per_page: 20,
      view: 'lista'
    })

    expect(query).toMatchObject({
      tab: 'atrasadas',
      q: 'xml',
      department_id: '3',
      client_id: '9',
      scope: 'mine',
      page: '2',
      per_page: '20',
      view: 'lista'
    })

    expect(parseWorkQueueQuery(query as Record<string, unknown>)).toEqual({
      tab: 'atrasadas',
      q: 'xml',
      department_id: 3,
      assignee_membership_id: null,
      client_id: 9,
      scope: 'mine',
      page: 2,
      per_page: 20,
      view: 'lista',
      sort: null,
      direction: null
    })
  })

  it('preserva filtros ao round-trip com view=kanban e tab de urgência', () => {
    const query = serializeWorkQueueQuery({
      ...base,
      tab: 'atrasadas',
      q: 'mei',
      department_id: 2,
      scope: 'office',
      page: 1,
      per_page: 100,
      view: 'kanban'
    })

    expect(query.view).toBe('kanban')
    expect(query.tab).toBe('atrasadas')
    expect(query.per_page).toBe('100')
    expect(parseWorkQueueQuery(query as Record<string, unknown>)).toMatchObject({
      tab: 'atrasadas',
      q: 'mei',
      department_id: 2,
      scope: 'office',
      per_page: 100,
      view: 'kanban'
    })
  })

  it('serializa sort e direction na Lista', () => {
    const query = serializeWorkQueueQuery({
      ...base,
      view: 'lista',
      sort: 'title',
      direction: 'desc'
    })
    expect(query.sort).toBe('title')
    expect(query.direction).toBe('desc')
    expect(parseWorkQueueQuery(query as Record<string, unknown>)).toMatchObject({
      sort: 'title',
      direction: 'desc'
    })
  })
})

describe('work-queue-filters tab×kanban', () => {
  it('default de tab depende da visão', () => {
    expect(defaultWorkQueueTab('fila')).toBe('open')
    expect(defaultWorkQueueTab('lista')).toBe('open')
    expect(defaultWorkQueueTab('kanban')).toBe('todas')
  })

  it('parse: view=kanban sem tab → todas', () => {
    expect(parseWorkQueueQuery({ view: 'kanban' })).toMatchObject({
      view: 'kanban',
      tab: 'todas'
    })
  })

  it('parse: Fila/Lista sem tab → open', () => {
    expect(parseWorkQueueQuery({})).toMatchObject({ view: 'fila', tab: 'open' })
    expect(parseWorkQueueQuery({ view: 'lista' })).toMatchObject({ view: 'lista', tab: 'open' })
  })

  it('parse: tab=todas explícito em kanban é aceito', () => {
    expect(parseWorkQueueQuery({ view: 'kanban', tab: 'todas' })).toMatchObject({
      view: 'kanban',
      tab: 'todas'
    })
  })

  it('serialize omite tab quando todas+kanban', () => {
    const query = serializeWorkQueueQuery({
      ...base,
      view: 'kanban',
      tab: 'todas',
      per_page: 100
    })
    expect(query.view).toBe('kanban')
    expect(query.tab).toBeUndefined()
    expect(query.per_page).toBe('100')
  })

  it('serialize omite tab quando open em Fila/Lista', () => {
    expect(serializeWorkQueueQuery({ ...base, view: 'fila', tab: 'open' }).tab).toBeUndefined()
    expect(serializeWorkQueueQuery({ ...base, view: 'lista', tab: 'open' }).tab).toBeUndefined()
  })

  it('coerção: entrar em kanban com open|impedidas|concluidas → todas', () => {
    expect(coerceWorkQueueTabForView('open', 'kanban')).toBe('todas')
    expect(coerceWorkQueueTabForView('impedidas', 'kanban')).toBe('todas')
    expect(coerceWorkQueueTabForView('concluidas', 'kanban')).toBe('todas')
  })

  it('coerção: sair de kanban com todas → open', () => {
    expect(coerceWorkQueueTabForView('todas', 'fila')).toBe('open')
    expect(coerceWorkQueueTabForView('todas', 'lista')).toBe('open')
  })

  it('coerção: hoje|atrasadas|semana preservados na troca', () => {
    for (const tab of ['hoje', 'atrasadas', 'semana'] as const) {
      expect(coerceWorkQueueTabForView(tab, 'kanban')).toBe(tab)
      expect(coerceWorkQueueTabForView(tab, 'fila')).toBe(tab)
      expect(coerceWorkQueueTabForView(tab, 'lista')).toBe(tab)
    }
  })

  it('parse coerção deep-link: kanban+open → todas; fila+todas → open', () => {
    expect(parseWorkQueueQuery({ view: 'kanban', tab: 'open' }).tab).toBe('todas')
    expect(parseWorkQueueQuery({ view: 'kanban', tab: 'impedidas' }).tab).toBe('todas')
    expect(parseWorkQueueQuery({ tab: 'todas' }).tab).toBe('open')
    expect(parseWorkQueueQuery({ view: 'lista', tab: 'todas' }).tab).toBe('open')
  })

  it('round-trip kanban default: serialize omite tab e parse restaura todas', () => {
    const query = serializeWorkQueueQuery({
      ...base,
      view: 'kanban',
      tab: 'todas',
      per_page: 100
    })
    expect(query).toEqual({
      view: 'kanban',
      per_page: '100'
    })
    expect(parseWorkQueueQuery(query as Record<string, unknown>)).toMatchObject({
      view: 'kanban',
      tab: 'todas',
      per_page: 100
    })
  })
})
