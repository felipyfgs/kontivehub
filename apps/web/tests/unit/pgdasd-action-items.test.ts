import { describe, expect, it, vi } from 'vitest'
import { buildPgdasdSelectionMenu } from '~/utils/pgdasd-action-items'

function flattenLabels(groups: ReturnType<typeof buildPgdasdSelectionMenu>): string[] {
  return groups.flatMap(group => group.map(item => String(item.label || '')))
}

describe('buildPgdasdSelectionMenu', () => {
  it('lista só Solicitar consulta e Limpar — sem Associar/Excluir', () => {
    const menu = buildPgdasdSelectionMenu({
      clientIds: [7],
      handlers: { onConsult: vi.fn() },
      onClear: vi.fn()
    })
    const labels = flattenLabels(menu)
    const blob = JSON.stringify(menu)

    expect(labels).toEqual(['Solicitar consulta', 'Limpar seleção'])
    expect(blob).not.toMatch(/Associar|Excluir|Preferências|Destinatários|Histórico|description/i)
  })

  it('com seleção múltipla mantém Solicitar consulta habilitado', () => {
    const menu = buildPgdasdSelectionMenu({
      clientIds: [1, 2],
      handlers: { onConsult: vi.fn() },
      onClear: vi.fn()
    })
    expect(flattenLabels(menu)).toContain('Solicitar consulta')
    expect(JSON.stringify(menu)).not.toMatch(/"disabled":true/)
  })

  it('sem seleção retorna menu vazio', () => {
    const menu = buildPgdasdSelectionMenu({
      clientIds: [],
      handlers: { onConsult: vi.fn() },
      onClear: vi.fn()
    })
    expect(menu).toEqual([])
  })

  it('omite Solicitar consulta quando onConsult não é passado', () => {
    const menu = buildPgdasdSelectionMenu({
      clientIds: [7],
      handlers: {},
      onClear: vi.fn()
    })
    expect(flattenLabels(menu)).toEqual(['Limpar seleção'])
  })
})
