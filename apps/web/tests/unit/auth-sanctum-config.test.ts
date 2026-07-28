import { describe, expect, it } from 'vitest'
import { sanctumClientConfig } from '../../config/sanctum'

describe('configuração do bootstrap Sanctum', () => {
  it('deixa o middleware decidir quando carregar a identidade', () => {
    expect(sanctumClientConfig).toEqual({ initialRequest: false })
  })
})
