import type { MailboxMonitoringStatus } from '~/types/mailbox-monitoring'

export type MailboxMonitoringViewState
  = | 'NEVER_SYNCED'
    | 'EMPTY_SYNCED'
    | 'HEALTHY'
    | 'LATE'
    | 'BLOCKED'
    | 'FAILED'

export interface MailboxMonitoringPresentation {
  state: MailboxMonitoringViewState
  label: string
  description: string
  color: 'neutral' | 'success' | 'warning' | 'error'
  icon: string
}

export function resolveMailboxMonitoringPresentation(
  status: MailboxMonitoringStatus,
  messageCount: number,
  now = new Date()
): MailboxMonitoringPresentation {
  if (status.coverage.failed_clients > 0) {
    return view('FAILED', 'Falha na última verificação', 'Há clientes que precisam de nova tentativa.', 'error', 'i-lucide-circle-x')
  }
  if (status.coverage.blocked_clients > 0) {
    return view('BLOCKED', 'Clientes sem autorização', 'Revise procurações antes da próxima consulta.', 'warning', 'i-lucide-shield-alert')
  }
  if (!status.last_paid_check_at && status.coverage.initialized_clients === 0) {
    return view('NEVER_SYNCED', 'Nunca sincronizada', 'Faça a primeira busca para preencher a caixa postal.', 'neutral', 'i-lucide-inbox')
  }
  if (status.next_due_at && new Date(status.next_due_at).getTime() < now.getTime()) {
    return view('LATE', 'Verificação atrasada', 'A próxima execução prevista já venceu.', 'warning', 'i-lucide-clock-alert')
  }
  if (messageCount === 0 && status.last_paid_check_at) {
    return view('EMPTY_SYNCED', 'Caixa vazia após consulta', 'A busca terminou sem mensagens para exibir.', 'success', 'i-lucide-inbox')
  }

  return view('HEALTHY', 'Monitoramento saudável', 'A caixa postal está dentro da janela configurada.', 'success', 'i-lucide-circle-check')
}

function view(
  state: MailboxMonitoringViewState,
  label: string,
  description: string,
  color: MailboxMonitoringPresentation['color'],
  icon: string
): MailboxMonitoringPresentation {
  return { state, label, description, color, icon }
}
