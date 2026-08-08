import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(process.cwd(), 'app/composables/useApi.ts'), 'utf8')

describe('useApi runtime efficiency', () => {
  it('memoiza uma fachada por Sanctum client e inicializa domínios sob demanda', () => {
    expect(source).toContain('const facadeByClient = new WeakMap<object, ApiFacade>()')
    expect(source).toContain('function lazyValue<T>')
    expect(source).toContain('get communication()')
    expect(source).toContain('get fiscal()')
    expect(source).toContain('facadeByClient.set(key, facade)')
    expect(source).not.toContain('const fiscalApi = createFiscalApi(client, apiUrl)')
  })
})
