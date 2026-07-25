import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { formatCnpj, normalizeCnpj } from '~/utils/format'

describe('FiscalClientCell CNPJ', () => {
  it('formata máscara Brasil e normaliza dígitos para cópia', () => {
    expect(formatCnpj('26461528000151')).toBe('26.461.528/0001-51')
    expect(normalizeCnpj('26.461.528/0001-51')).toBe('26461528000151')
  })

  it('célula usa formatCnpj, copia normalizeCnpj e não navega no clique do CNPJ', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/components/fiscal/FiscalClientCell.vue'),
      'utf8'
    )
    expect(source).toContain('formatCnpj')
    expect(source).toContain('normalizeCnpj')
    expect(source).toContain('navigator.clipboard.writeText')
    expect(source).toContain('CNPJ copiado')
    expect(source).toContain('event.stopPropagation()')
    expect(source).toContain('cnpjMasked')
    expect(source).toMatch(/cnpj\?:/)
  })

  it('builders das carteiras passam cnpj para a célula', () => {
    const files = [
      'app/utils/pgdasd-table.ts',
      'app/utils/pgmei-table.ts',
      'app/utils/dctfweb-table.ts',
      'app/utils/sitfis-table.ts',
      'app/utils/declarations-table.ts',
      'app/pages/monitoring/fgts.vue',
      'app/pages/monitoring/installments.vue'
    ]
    for (const file of files) {
      const source = readFileSync(resolve(process.cwd(), file), 'utf8')
      expect(source, file).toContain('cnpj: row.original.cnpj')
    }
  })
})
