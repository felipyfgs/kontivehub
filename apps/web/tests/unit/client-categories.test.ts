import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  CLIENTS_LIST_QUERY_SCHEMA,
  parseListFilterQuery,
  serializeListFilterQuery
} from '~/composables/useListFilterQuery'
import {
  clientsFiltersToPayload,
  clientsPayloadToFilters,
  hasActiveClientsFiltersForSave
} from '~/utils/saved-list-filters'
import { clientTaxRegimeLabel } from '~/utils/clients-tax-regime'

describe('categorias e regime na lista de clientes', () => {
  it('oferece paleta curada em popover no catálogo de categorias', () => {
    const modal = readFileSync(
      resolve(process.cwd(), 'app/components/clients/CategoryManagerModal.vue'),
      'utf8'
    )
    const palette = readFileSync(
      resolve(process.cwd(), 'app/utils/client-category-colors.ts'),
      'utf8'
    )

    expect(modal).toContain('<UPopover')
    expect(modal).toContain('role="radiogroup"')
    expect(modal).toContain('CLIENT_CATEGORY_COLOR_PALETTE')
    expect(modal).toContain('grid-cols-5')
    expect(modal).not.toContain('<USelect')
    expect(modal).not.toContain('variant="card"')
    expect(palette).toContain(`label: 'Laranja'`)
    expect(palette).toContain(`label: 'Rosa'`)
    expect(palette).toContain(`label: 'Índigo'`)
    expect(palette).toContain(`value: 'yellow'`)
    expect(palette.match(/value: '/g)?.length).toBeGreaterThanOrEqual(16)
  })

  it('faz round-trip de URL para filtros múltiplos', () => {
    const parsed = parseListFilterQuery({
      category_ids: '7,2',
      tax_regimes: 'MEI,LUCRO_REAL',
      sort: 'tax_regime',
      sort_direction: 'desc'
    }, CLIENTS_LIST_QUERY_SCHEMA)

    expect(parsed.category_ids).toBe('7,2')
    expect(parsed.tax_regimes).toBe('MEI,LUCRO_REAL')
    expect(serializeListFilterQuery(parsed, CLIENTS_LIST_QUERY_SCHEMA)).toMatchObject({
      category_ids: '7,2',
      tax_regimes: 'MEI,LUCRO_REAL',
      sort: 'tax_regime',
      sort_direction: 'desc'
    })
  })

  it('preserva presets antigos e serializa os novos campos', () => {
    expect(clientsPayloadToFilters({
      schema_version: 1,
      q: 'Alfa',
      status: 'active',
      operational_filter: 'total'
    })).toEqual({
      q: 'Alfa',
      status: 'active',
      operational_filter: 'total',
      category_ids: '',
      tax_regimes: '',
      procuracao_statuses: ''
    })

    const payload = clientsFiltersToPayload({
      q: '',
      status: 'all',
      operational_filter: 'credential_expired',
      category_ids: '10,2,10',
      tax_regimes: 'MEI,LUCRO_PRESUMIDO',
      procuracao_statuses: 'missing,expiring,missing'
    })

    expect(payload.category_ids).toBe('10,2')
    expect(payload.tax_regimes).toBe('LUCRO_PRESUMIDO,MEI')
    expect(payload.operational_filter).toBe('credential_expired')
    expect(payload.procuracao_statuses).toBe('expiring,missing')
    expect(hasActiveClientsFiltersForSave({
      q: '',
      status: 'all',
      operational_filter: 'total',
      category_ids: '2',
      tax_regimes: '',
      procuracao_statuses: ''
    })).toBe(true)
    expect(hasActiveClientsFiltersForSave({
      q: '',
      status: 'all',
      operational_filter: 'total',
      category_ids: '',
      tax_regimes: '',
      procuracao_statuses: 'authorized'
    })).toBe(true)
  })

  it('usa rótulos canônicos de regime', () => {
    expect(clientTaxRegimeLabel('SIMPLES_NACIONAL')).toBe('Simples Nacional')
    expect(clientTaxRegimeLabel('IMUNE_ISENTO')).toBe('Imune / Isento')
    expect(clientTaxRegimeLabel(null)).toBeNull()
  })
})
