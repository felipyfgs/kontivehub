import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('contratos de padronização do frontend', () => {
  it('fixa green/zinc e impede a mutação runtime da paleta', () => {
    const config = source('app/app.config.ts')
    const menu = source('app/components/UserMenu.vue')

    expect(config).toContain('primary: \'green\'')
    expect(config).toContain('neutral: \'zinc\'')
    expect(menu).not.toContain('appConfig.ui.colors.primary =')
    expect(menu).not.toContain('appConfig.ui.colors.neutral =')
    expect(menu).not.toContain('label: \'Tema\'')
  })

  it('mantém a Home operacional baseada apenas nos dados reais disponíveis', () => {
    const home = source('app/pages/index.vue')

    expect(home).toContain('api.operations.summary()')
    expect(home).toContain('api.operations.inbox({ limit: 5 })')
    expect(home).toContain('<HomeWorkKpisBlock')
    expect(home).not.toContain('HomeChart')
    expect(home).not.toContain('DateRangePicker')
  })

  it('delega a autorização de fluxos e respostas rápidas ao middleware antes do carregamento', () => {
    const editor = source('app/pages/communication/flows/[id]/editor.vue')
    const quickResponses = source('app/pages/communication/quick-responses/index.vue')

    expect(editor).toContain('middleware: [requireCommunicationView]')
    expect(editor).toContain('onMounted(() => {')
    expect(editor).toContain('void load()')
    expect(quickResponses).toContain('middleware: [requireCommunicationView]')
    expect(quickResponses).not.toContain('catalog.load()')
  })

  it('oferece teclado roving para o master-detail da Caixa Postal', () => {
    const list = source('app/components/monitoring/MailboxList.vue')

    expect(list).toContain('role="listbox"')
    expect(list).toContain('role="option"')
    expect(list).toContain('@keydown="selectFromKeyboard($event, index)"')
    expect(list).toContain('ArrowDown')
    expect(list).toContain('ArrowUp')
    expect(list).toContain('\'Home\'')
    expect(list).toContain('\'End\'')
    expect(list).toContain(':tabindex=')
  })

  it('respeita preferência de movimento reduzido em loaders contínuos', () => {
    const css = source('app/assets/css/main.css')
    const mailbox = source('app/components/monitoring/MailboxList.vue')

    expect(css).toContain('@media (prefers-reduced-motion: reduce)')
    expect(css).toContain('.animate-spin')
    expect(mailbox).toContain('role="status"')
  })

  it('transforma tabelas densas em cards antes de md', () => {
    const table = source('app/components/shell/DataTable.vue')

    expect(table).toContain('ShellMobileCards')
    expect(table).toContain('smaller(\'md\')')
    expect(table).toContain('primaryColumnId')
    expect(table).toContain('summaryColumnIds')
  })
})
