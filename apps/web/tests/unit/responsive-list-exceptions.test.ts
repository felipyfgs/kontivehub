import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('responsive list exceptions', () => {
  it('lineariza o histórico SITFIS em cards com identidade e ação preservadas', () => {
    const history = source('app/components/monitoring/SitfisHistoryView.vue')

    expect(history).toContain('primary-column-id="observed_at"')
    expect(history).toContain(':summary-column-ids="[\'file\']"')
    expect(history).not.toContain(':mobile-cards="false"')
    expect(history).toContain('data-testid="sitfis-history-download"')
    expect(history).toContain('sitfis-history-file-unavailable')
  })

  it('mantém a árvore expansível de processos linearizada e legível no mobile', () => {
    const processes = source('app/pages/work/processes/index.vue')

    expect(processes).toContain(':mobile-cards="false"')
    expect(processes).toContain('grid min-w-0 grid-cols-2')
    expect(processes).toContain('md:table-row')
    expect(processes).not.toMatch(/text-\[(?:[0-9]|1[01])px\]/)
  })

  it('transforma regiões horizontais acionáveis antes de md', () => {
    const board = source('app/components/work/WorkKanbanBoard.vue')
    const column = source('app/components/work/WorkKanbanColumn.vue')
    const installments = source('app/pages/monitoring/installments.vue')
    const contact = source('app/components/communication/contacts/Context.vue')

    expect(board).toContain('flex-col gap-3 pb-2 md:flex-row md:overflow-x-auto')
    expect(column).toContain('w-full flex-col')
    expect(column).toContain('md:w-72 md:shrink-0')
    expect(installments).toContain('flex-wrap gap-2 pb-1 md:flex-nowrap md:overflow-x-auto')
    expect(contact).toContain('flex-wrap md:flex-nowrap md:overflow-x-auto')
  })
})
