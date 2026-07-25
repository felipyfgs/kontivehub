/**
 * Convenções de overlays (tarefa 2.11).
 * Escolha pelo tipo de tarefa — não por gosto visual.
 */

export type OverlayKind = 'modal' | 'slideover' | 'drawer' | 'popover' | 'tooltip' | 'route'

export interface OverlayChoice {
  kind: OverlayKind
  /** Justificativa curta para a matriz / code review. */
  reason: string
}

/**
 * Regras canônicas do design da change:
 * - modal: confirmação e formulário curto/focado
 * - slideover: detalhe secundário desktop / tela menor
 * - drawer: ações e detalhes mobile
 * - route: processo longo, auditável ou URL canônica
 * - popover: filtro contextual curto
 * - tooltip: somente dica não interativa
 */
export function chooseOverlay(input: {
  task: 'confirm' | 'short-form' | 'detail' | 'mobile-action' | 'long-flow' | 'filter' | 'hint'
  viewport?: 'desktop' | 'mobile'
  needsCanonicalUrl?: boolean
}): OverlayChoice {
  const vp = input.viewport || 'desktop'

  if (input.task === 'hint') {
    return { kind: 'tooltip', reason: 'Dica não interativa' }
  }
  if (input.task === 'filter') {
    return { kind: 'popover', reason: 'Filtro contextual curto' }
  }
  if (input.task === 'long-flow' || input.needsCanonicalUrl) {
    return { kind: 'route', reason: 'Fluxo longo ou URL canônica' }
  }
  if (input.task === 'confirm' || input.task === 'short-form') {
    return { kind: 'modal', reason: 'Confirmação ou formulário focado' }
  }
  if (input.task === 'mobile-action' || (input.task === 'detail' && vp === 'mobile')) {
    return { kind: 'drawer', reason: 'Detalhe/ação mobile com retorno de foco' }
  }
  return { kind: 'slideover', reason: 'Detalhe secundário sem abandonar a lista' }
}

/** Props de conteúdo recomendadas para contenção de foco (Nuxt UI). */
export const OVERLAY_FOCUS = Object.freeze({
  /** Fechar com Escape e clicar fora quando não mutante crítico. */
  dismissible: true,
  /** Confirmações destrutivas: exigir ação explícita. */
  destructive: {
    dismissible: false
  }
})
