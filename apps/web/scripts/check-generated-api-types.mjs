import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { publicOpenApiPath } from './public-openapi-path.mjs'

const temporaryDirectory = mkdtempSync(join(tmpdir(), 'kontivehub-public-api-'))
const generated = join(temporaryDirectory, 'public-api.ts')
const contract = publicOpenApiPath()
const checkedIn = 'app/types/generated/public-api.ts'

try {
  execFileSync('openapi-typescript', [contract, '-o', generated], {
    cwd: process.cwd(),
    stdio: 'inherit'
  })

  if (readFileSync(generated, 'utf8') !== readFileSync(checkedIn, 'utf8')) {
    console.error('Os tipos públicos estão desatualizados. Execute pnpm run types:api.')
    process.exitCode = 1
  } else {
    console.log('Os tipos públicos estão sincronizados com o OpenAPI.')
  }
} finally {
  rmSync(temporaryDirectory, { recursive: true, force: true })
}
