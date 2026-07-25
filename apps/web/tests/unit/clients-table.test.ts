import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { clientCredentialInfo } from '~/utils/clients-credential'
import { clientsColumnLabels } from '~/utils/clients-table-labels'
import type { Client } from '~/types/api'

describe('clients-table', () => {
  it('mantém contratos de coluna alinhados ao ModuleDataTable', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/utils/clients-table.ts'),
      'utf8'
    )
    const pageSource = readFileSync(
      resolve(process.cwd(), 'app/components/clients/ClientCatalogList.vue'),
      'utf8'
    )
    const shellSource = readFileSync(
      resolve(process.cwd(), 'app/pages/clients.vue'),
      'utf8'
    )

    expect(source).toMatch(/id: 'legal_name'/)
    expect(source).not.toMatch(/id: 'cnpj'/)
    expect(source).toMatch(/id: 'credential'/)
    expect(source).toMatch(/id: 'procuracao'/)
    expect(source).toMatch(/id: 'is_active'/)
    expect(source).toMatch(/id: 'tax_regime'/)
    expect(source).toMatch(/id: 'actions'/)
    expect(source).toContain('tableCellBadgeProps')
    expect(source).toContain('tableIconGroup')
    expect(source).toContain('tableIconMenu')
    expect(source).toContain('clientsTableHeader(\'Ações\', \'center\')')
    expect(source).toContain('\'clients-actions-group\', { fill: true }')
    expect(source).toMatch(/th: 'w-32 min-w-32 text-center'/)
    expect(source).toContain('onCopyCnpj')
    expect(source).toContain('formatCnpj(rawCnpj)')
    expect(source).toContain('truncateText(label, 40)')
    expect(source).toContain('overflow-hidden')
    expect(source).toContain('min-w-48 w-full')
    expect(source).not.toMatch(/w-\[\d+%\]/)
    expect(source).not.toContain('max-w-[20rem]')
    expect(source.indexOf('id: \'tax_regime\'')).toBeLessThan(source.indexOf('id: \'actions\''))
    expect(source).toContain('label: \'Gerenciar categorias\'')
    expect(source).not.toContain('clients-category-tags')
    expect(source).toMatch(/'data-testid': 'clients-credential-cell'/)
    expect(source).toContain('\'data-testid\': \'clients-credential-actions\'')
    expect(source).toContain('variant: \'subtle\'')
    expect(source).toContain('color: \'neutral\'')
    expect(source).toContain('flex min-w-0 items-center gap-1.5')
    expect(source).toMatch(/h\('div', \{ class: 'min-w-0 flex-1' \}/)
    // customers.vue @ 0f30c09: LIST_TABLE_* no #body
    expect(pageSource).toContain('ShellDataTable')
    expect(shellSource).toContain('<ClientsClientCatalogList v-if="isList" />')
    expect(pageSource).toContain('v-model:row-selection="rowSelection"')
    expect(pageSource).toContain(':selection-enabled="canManageClients"')
    expect(pageSource).toContain('current.toggleAllPageRowsSelected(!!value)')
    expect(pageSource).toContain('api.clients.bulkStatus')
    expect(pageSource).toContain('<template #actions>')
    expect(pageSource).toContain('data-testid="clients-bulk-actions-menu"')
    expect(pageSource).toContain('aria-label="Ações em massa"')
    expect(pageSource).toContain('i-lucide-list-checks')
    expect(pageSource).toContain('Inativar ativos (')
    expect(pageSource).toContain('Reativar inativos (')
    expect(pageSource).toContain('label: \'Limpar seleção\'')
    expect(pageSource).toContain('label: \'Adicionar categorias\'')
    expect(pageSource).toContain('label: \'Remover categorias\'')
    expect(pageSource).toContain('[\'credential\', \'tax_regime\']')
    expect(pageSource).toContain('<ClientsCategoryManagerModal')
    expect(pageSource).toContain('<ClientsAssignCategoriesModal')
  })

  it('rotula colunas em pt-BR', () => {
    const labels = clientsColumnLabels()
    expect(labels.legal_name).toBe('Razão social / nome')
    expect(labels.credential).toBe('Certificado digital')
    expect(labels.is_active).toBe('Estado')
    expect(labels.tax_regime).toBe('Regime tributário')
  })

  it('chip de certificado resume validade sem inventar status', () => {
    const withCredential = clientCredentialInfo({
      id: 1,
      name: 'Cliente',
      legal_name: 'Cliente LTDA',
      credential_summary: {
        status: 'ACTIVE',
        valid_to: '2026-09-26'
      }
    } as Client)

    expect(withCredential.hasCredential).toBe(true)
    expect(withCredential.color).toBe('success')
    expect(withCredential.chipLabel).toContain('Válido até')

    const withoutCredential = clientCredentialInfo({
      id: 2,
      name: 'Sem certificado',
      legal_name: 'Sem certificado LTDA'
    } as Client)

    expect(withoutCredential).toEqual({
      chipLabel: 'Sem certificado',
      color: 'neutral',
      hasCredential: false
    })
  })
})
