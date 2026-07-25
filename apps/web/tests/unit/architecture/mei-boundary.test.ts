import { readFileSync, readdirSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(import.meta.dirname, '../../..')
const sourceRoots = ['app', 'server']
const forbidden = [
  /MEI_AUTOMATION_URL/,
  /NUXT_PUBLIC_MEI(?:_|\b)/,
  /https?:\/\/mei(?::\d+)?(?:\/|['"`])/,
  /meiAutomation(?:Base)?Url/
]

function sourceFiles(path: string): string[] {
  if (!statSync(path).isDirectory()) {
    return [path]
  }

  return readdirSync(path)
    .flatMap(entry => sourceFiles(resolve(path, entry)))
    .filter(file => /\.(?:ts|vue|mjs)$/.test(file))
}

describe('fronteira Nuxt para o sidecar MEI', () => {
  it('não contém cliente ou URL direta do sidecar', () => {
    const files = [
      ...sourceRoots.flatMap(directory => sourceFiles(resolve(root, directory))),
      resolve(root, 'nuxt.config.ts')
    ]
    const violations = files.flatMap((file) => {
      const source = readFileSync(file, 'utf8')
      return forbidden
        .filter(pattern => pattern.test(source))
        .map(pattern => `${file.replace(`${root}/`, '')}: ${pattern}`)
    })

    expect(violations).toEqual([])
  })
})
