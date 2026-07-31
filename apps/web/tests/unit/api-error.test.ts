import { describe, expect, it } from 'vitest'
import { apiErrorStatus } from '../../app/utils/api-error'

describe('apiErrorStatus', () => {
  it('normaliza status HTTP válido e rejeita valores fora do contrato', () => {
    expect(apiErrorStatus({ statusCode: '404' })).toBe(404)
    expect(apiErrorStatus({ response: { status: 503 } })).toBe(503)
    expect(apiErrorStatus({ status: 99 })).toBeNull()
    expect(apiErrorStatus({ status: 600 })).toBeNull()
  })

  it('não propaga exceções de coerção de valores desconhecidos', () => {
    expect(apiErrorStatus({ status: Symbol('status') })).toBeNull()
    expect(apiErrorStatus({
      status: {
        valueOf() {
          throw new Error('falha de coerção')
        }
      }
    })).toBeNull()
  })
})
