import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(process.cwd())
const source = (path: string) => readFileSync(resolve(root, path), 'utf8')

function vueFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return vueFiles(path)
    return extname(entry.name) === '.vue' ? [path] : []
  })
}

describe('auditoria transversal da padronização', () => {
  it('não mantém exceções tipográficas abaixo de 12 px nos componentes', () => {
    const occurrences = vueFiles(resolve(root, 'app'))
      .flatMap(path => source(path).match(/text-\[(?:[0-9]|1[01])px\]/g)?.map(token => ({ path, token })) ?? [])

    expect(occurrences).toEqual([])
    expect(source('tests/fixtures/frontend-standardization-audit.md')).toContain('complete-whatsapp-message-composer')
  })

  it('mantém cores cruas apenas nas exceções de canvas e tags configuráveis', () => {
    const app = source('app/app.vue')
    const categories = source('app/utils/client-category-colors.ts')
    const audit = source('tests/fixtures/frontend-standardization-audit.md')

    expect(app).toContain('#09090b')
    expect(categories).toContain('CLIENT_CATEGORY_COLOR_PALETTE')
    expect(categories).toContain('Paleta curada para tags/categorias')
    expect(audit).toContain('conteúdo configurável explicitamente delimitado')
  })

  it('registra contratos de formulário, mídia, overlay, toque e async', () => {
    const audit = source('tests/fixtures/frontend-standardization-audit.md')
    const mailbox = source('app/components/monitoring/MailboxList.vue')
    const media = source('app/components/communication/MediaViewer.vue')
    const fiscal = source('app/pages/admin/fiscal-modules.vue')

    expect(audit).toContain('Formulários, mídia e overlays')
    expect(audit).toContain('Async, sessão e tenant')
    expect(mailbox).toContain('min-h-11')
    expect(mailbox).toContain('focusMessage')
    expect(media).toContain(':alt="current.attachment.filename"')
    expect(fiscal).toContain('<UFormField label="Motivo" name="reason" required>')
    expect(fiscal).toContain('autofocus')
  })
})
