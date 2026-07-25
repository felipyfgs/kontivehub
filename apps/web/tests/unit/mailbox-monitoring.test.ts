import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { MailboxMonitoringStatus } from '../../app/types/mailbox-monitoring'
import { resolveMailboxMonitoringPresentation } from '../../app/utils/mailbox-monitoring'

const base: MailboxMonitoringStatus = {
  enabled: true,
  runtime_enabled: true,
  mode: 'ECONOMICO',
  daily_time: '00:30',
  timezone: 'America/Sao_Paulo',
  reconciliation_days: 30,
  auto_detail_limit: 0,
  monthly_budget_micros: null,
  coverage: {
    initialized_clients: 1,
    pending_clients: 0,
    blocked_clients: 0,
    failed_clients: 0
  },
  last_free_check_at: '2026-07-21T03:30:00Z',
  last_paid_check_at: '2026-07-21T04:00:00Z',
  last_full_reconciliation_at: '2026-07-21T04:00:00Z',
  last_dispatched_at: '2026-07-21T03:30:00Z',
  next_due_at: '2026-07-22T03:30:00Z',
  indicator_note: 'Zero não comprova caixa vazia.'
}

describe('mailbox monitoring presentation', () => {
  it.each([
    ['NEVER_SYNCED', { ...base, coverage: { ...base.coverage, initialized_clients: 0 }, last_paid_check_at: null }, 0],
    ['EMPTY_SYNCED', base, 0],
    ['HEALTHY', base, 3],
    ['LATE', { ...base, next_due_at: '2026-07-20T03:30:00Z' }, 3],
    ['BLOCKED', { ...base, coverage: { ...base.coverage, blocked_clients: 1 } }, 3],
    ['FAILED', { ...base, coverage: { ...base.coverage, failed_clients: 1 } }, 3]
  ] as const)('resolves %s', (expected, status, messages) => {
    expect(resolveMailboxMonitoringPresentation(
      status as MailboxMonitoringStatus,
      messages,
      new Date('2026-07-21T12:00:00Z')
    ).state).toBe(expected)
  })

  it('keeps the accountant experience simple while cost controls stay outside the UI', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/mailbox.vue'), 'utf8')
    const card = readFileSync(resolve(process.cwd(), 'app/components/monitoring/MailboxMonitoringCard.vue'), 'utf8')
    const mail = readFileSync(resolve(process.cwd(), 'app/components/monitoring/MailboxMail.vue'), 'utf8')

    expect(card).toContain('Atualizar agora')
    expect(page).toContain('Confirmar atualização')
    expect(card).toContain('Busca automática')
    expect(card).toContain('@update:model-value="toggleAutomation"')
    expect(card).not.toContain('<UCard')
    expect(card).not.toContain('label="Salvar"')
    expect(card).not.toContain('O sistema usa a rotina mais econômica')
    expect(card).not.toContain('MAILBOX_ECONOMIC_MONITORING_ENABLED')
    expect(card).not.toContain('Última busca paga')
    expect(card).not.toContain('aguardando reconciliação')
    expect(page).not.toContain('estimated_cost_micros')
    expect(page).not.toContain('price_source')
    expect(mail).toContain('Buscar conteúdo')
    expect(mail).not.toContain('estimated_cost_micros')
    expect(mail).not.toContain('price_source')
    expect(page).toContain('mailbox-alerts-trigger')
    expect(page).toContain('v-model:open="alertsModalOpen"')
    expect(page).toContain('<USlideover')
    expect(page).not.toContain('mailbox-alerts-strip')
    expect(page).toContain('mailbox-detail-toggle')
    expect(page).toContain('detailOpen')
    expect(page).toContain('mailbox-monitoring-collapsible')
    expect(page).toContain('UCollapsible')
  })
})
