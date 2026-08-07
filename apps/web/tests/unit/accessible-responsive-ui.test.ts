import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('accessible responsive UI', () => {
  it('gives the mailbox list a roving keyboard model and selected announcement', () => {
    const mailbox = source('app/components/monitoring/MailboxList.vue')

    expect(mailbox).toContain('selectFromKeyboard')
    expect(mailbox).toContain('[\'ArrowUp\', \'ArrowDown\', \'Home\', \'End\']')
    expect(mailbox).toContain(':tabindex="selectedId === mail.id || (!selectedId && index === 0) ? 0 : -1"')
    expect(mailbox).toContain('Mensagem {{ props.messages.findIndex')
    expect(mailbox).toContain('role="status"')
    expect(mailbox).toContain('min-h-11')
    expect(mailbox.indexOf('role="status"')).toBeLessThan(mailbox.indexOf('role="listbox"'))
  })

  it('keeps motion and async feedback accessible', () => {
    const css = source('app/assets/css/main.css')
    const table = source('app/components/shell/DataTable.vue')
    const cards = source('app/components/shell/MobileCards.vue')

    expect(css).toContain('@media (prefers-reduced-motion: reduce)')
    expect(css).toContain('.animate-spin')
    expect(css).toContain('.animate-pulse')
    expect(css).toContain('animation: none !important')
    expect(css).not.toContain('animation-duration: 0.01ms')
    expect(source('app/components/monitoring/MailboxList.vue')).toContain('motion-reduce:transition-none')
    expect(source('app/components/communication/TimelinePanel.vue')).toContain('motion-reduce:transition-none')
    expect(source('app/components/shell/MobileCards.vue')).toContain('motion-reduce:transition-none')
    expect(table).toContain(':aria-busy="loading || undefined"')
    expect(table).toContain('\'Tabela de dados com rolagem horizontal\'')
    expect(cards).toContain(':aria-busy="loading || undefined"')
    expect(cards).toContain('min-h-11 w-full justify-between')
  })

  it('transforms fiscal matrices and DAS history into mobile detail cards', () => {
    const fiscal = source('app/pages/admin/fiscal-modules.vue')
    const das = source('app/components/monitoring/PgdasdDasHistoryModal.vue')

    expect(fiscal).not.toContain(':mobile-cards="false"')
    expect(fiscal).not.toContain('min-w-[70rem]')
    expect(fiscal).not.toContain('min-w-[78rem]')
    expect(fiscal).toContain(':summary-column-ids')
    expect(das).toContain('data-testid="pgdasd-das-history-cards"')
    expect(das).toContain('md:hidden')
    expect(das).toContain('hidden overflow-x-auto rounded-lg border border-default md:block')
    expect(das).toContain('UCollapsible')
  })

  it('keeps calendar controls operable with 44px targets', () => {
    const calendar = source('app/pages/work/calendar.vue')

    expect(calendar).toContain('min-h-11 min-w-11')
    expect(calendar).toContain('class="min-h-11 w-full')
  })
})
