import { existsSync } from 'node:fs'
import { resolve } from 'node:path'

export function publicOpenApiPath() {
  const candidates = [
    process.env.PUBLIC_OPENAPI_PATH,
    resolve(process.cwd(), '../api/resources/contracts/public.openapi.json'),
    '/workspace/api-contracts/public.openapi.json'
  ].filter(Boolean)

  const contract = candidates.find(candidate => existsSync(candidate))
  if (!contract) {
    throw new Error('Contrato public.openapi.json não encontrado.')
  }

  return contract
}
