import type { ComposerLifecycleCopy, ComposerLifecycleItem, ComposerLifecycleState } from '~/types/communication/composer-lifecycle'

const stateOrder: Partial<Record<ComposerLifecycleState, number>> = {
  validating: 0,
  uploading: 1,
  queued: 2,
  sent: 3,
  delivered: 4,
  read: 5
}

const terminalStates: readonly ComposerLifecycleState[] = ['blocked', 'cancelled', 'failed', 'partially_sent']

const copies: Record<ComposerLifecycleState, ComposerLifecycleCopy> = {
  validating: { label: 'Validando', cause: 'Conferimos os dados e as permissões deste item.', impact: 'O envio ainda não foi iniciado.', nextAction: 'Aguarde a validação.', icon: 'i-lucide-shield-check', color: 'info' },
  uploading: { label: 'Enviando arquivo', cause: 'O arquivo está sendo transferido com segurança.', impact: 'Não envie novamente enquanto o progresso estiver em andamento.', nextAction: 'Aguarde a conclusão do upload.', icon: 'i-lucide-upload', color: 'primary' },
  queued: { label: 'Aguardando fila', cause: 'O item foi aceito localmente e aguarda a fila de entrega.', impact: 'Ainda não há confirmação de envio ao destinatário.', nextAction: 'Aguarde a confirmação da fila.', icon: 'i-lucide-clock-3', color: 'warning' },
  sent: { label: 'Enviado', cause: 'O serviço confirmou o envio.', impact: 'A entrega ao destinatário ainda pode estar pendente.', nextAction: 'Acompanhe os recibos de entrega.', icon: 'i-lucide-send', color: 'success' },
  delivered: { label: 'Entregue', cause: 'O destinatário recebeu o item.', impact: 'Não é necessário enviar novamente.', nextAction: 'Nenhuma ação necessária.', icon: 'i-lucide-check-check', color: 'success' },
  read: { label: 'Lido', cause: 'O destinatário confirmou a leitura.', impact: 'Não é necessário enviar novamente.', nextAction: 'Nenhuma ação necessária.', icon: 'i-lucide-eye', color: 'success' },
  blocked: { label: 'Envio bloqueado', cause: 'A caixa de entrada ou a permissão atual não permite este envio.', impact: 'O rascunho foi preservado e não foi enviado.', nextAction: 'Revise o motivo e tente novamente quando o suporte estiver disponível.', icon: 'i-lucide-ban', color: 'warning' },
  cancelled: { label: 'Envio cancelado', cause: 'O envio foi cancelado antes da confirmação.', impact: 'Nenhuma mensagem foi criada por este item.', nextAction: 'Revise o rascunho antes de iniciar um novo envio.', icon: 'i-lucide-circle-x', color: 'neutral' },
  failed: { label: 'Falha no envio', cause: 'Não foi possível concluir este item.', impact: 'O rascunho foi preservado e os itens aceitos não serão reenviados.', nextAction: 'Tentar novamente este item.', icon: 'i-lucide-circle-alert', color: 'error' },
  partially_sent: { label: 'Envio parcial', cause: 'Parte do lote foi aceita e outra parte falhou.', impact: 'Os itens aceitos não serão reenviados.', nextAction: 'Tente novamente somente os itens com falha.', icon: 'i-lucide-triangle-alert', color: 'warning' }
}

export function composerLifecycleCopy(item: Pick<ComposerLifecycleItem, 'state' | 'cause'>): ComposerLifecycleCopy {
  const copy = copies[item.state]
  return item.cause ? { ...copy, cause: item.cause } : copy
}

export function composerLifecycleProgress(item: Pick<ComposerLifecycleItem, 'state' | 'progress'>): number {
  if (item.state === 'uploading') return Math.min(99, Math.max(0, item.progress ?? 0))
  if (item.state === 'validating') return 5
  return 100
}

export function canTransitionComposerLifecycle(from: ComposerLifecycleState, to: ComposerLifecycleState): boolean {
  if (from === to) return true
  if (terminalStates.includes(from)) return false
  if (terminalStates.includes(to)) return true
  const fromOrder = stateOrder[from]
  const toOrder = stateOrder[to]
  if (fromOrder !== undefined && toOrder !== undefined) return toOrder >= fromOrder
  return false
}

export function canRetryComposerLifecycle(state: ComposerLifecycleState): boolean {
  return state === 'failed'
}

/** Keep announcements to state changes that require attention; delivery receipts stay visually available. */
export function composerLifecycleAnnouncement(previous: ComposerLifecycleState | null, item: ComposerLifecycleItem): string | null {
  if (previous === item.state || !['queued', 'blocked', 'failed', 'partially_sent'].includes(item.state)) return null
  const copy = composerLifecycleCopy(item)
  return `${item.label}: ${copy.label}. ${copy.impact} ${copy.nextAction}`
}
