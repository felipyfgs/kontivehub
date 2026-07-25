/**
 * Precedência da coluna Situação Simples/MEI quando falta procuração e-CAC.
 */
import type { SimplesMeiClientRow } from '~/types/fiscal-modules'
import {
  normalizeProcuracaoStatus,
  procuracaoActionHint,
  procuracaoLabel,
  procuracaoTone
} from '~/utils/procuracao'

export interface SimplesMeiSituationOverride {
  label: string
  color: 'success' | 'warning' | 'error' | 'neutral' | 'info'
  icon: string
  tooltip: string
  testId: string
}

/** Quando `missing`, a Situação da carteira exibe Sem procuração. */
export function simplesMeiMissingProcuracaoSituation(
  row: SimplesMeiClientRow,
  testId: string
): SimplesMeiSituationOverride | null {
  const status = normalizeProcuracaoStatus(row.detail?.procuracao_status)
  if (status !== 'missing') {
    return null
  }

  const hint = procuracaoActionHint('missing')
  return {
    label: procuracaoLabel('missing'),
    color: procuracaoTone('missing'),
    icon: 'i-lucide-stamp',
    tooltip: hint || 'Cliente sem procuração e-CAC sincronizada.',
    testId
  }
}
