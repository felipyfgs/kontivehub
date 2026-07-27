import { execFileSync } from 'node:child_process'
import { publicOpenApiPath } from './public-openapi-path.mjs'

execFileSync(
  'openapi-typescript',
  [publicOpenApiPath(), '-o', 'app/types/generated/public-api.ts'],
  { cwd: process.cwd(), stdio: 'inherit' }
)
