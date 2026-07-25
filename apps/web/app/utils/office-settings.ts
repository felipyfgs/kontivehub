/**
 * Labels e helpers da configuração unificada do escritório (/settings).
 * Estados acionáveis — sem jargão OAuth/mTLS/ETag.
 */
import type {
  OfficeCanonicalCredential,
  OfficeOnboardingActionable,
  OfficeOnboardingStatus
} from '~/types/api'

export function onboardingStatusLabel(status?: string | null): string {
  switch (status as OfficeOnboardingStatus | string) {
    case 'incomplete':
      return 'Cadastro incompleto'
    case 'configuring':
      return 'Configurando'
    case 'validating':
      return 'Validando certificado'
    case 'authorizing':
      return 'Autorizando acesso'
    case 'loading_proxy_powers':
      return 'Carregando procurações'
    case 'syncing':
      return 'Sincronizando dados'
    case 'ready':
      return 'Pronto para ativação'
    case 'provisioning':
      return 'Ativando integrações…'
    case 'authorized':
      return 'Integrações ativas'
    case 'action_required':
      return 'Ação necessária'
    case 'technical_error':
      return 'Falha técnica (suporte da plataforma)'
    case 'revoked':
      return 'Revogado'
    default:
      return status || '—'
  }
}

export function onboardingStatusColor(
  status?: string | null
): 'success' | 'warning' | 'error' | 'info' | 'neutral' {
  switch (status) {
    case 'authorized':
    case 'ready':
      return 'success'
    case 'configuring':
    case 'validating':
    case 'authorizing':
    case 'loading_proxy_powers':
    case 'syncing':
    case 'provisioning':
      return 'info'
    case 'action_required':
    case 'incomplete':
      return 'warning'
    case 'technical_error':
    case 'revoked':
      return 'error'
    default:
      return 'neutral'
  }
}

export const OFFICE_ONBOARDING_STAGES = [
  { key: 'CONFIGURANDO', label: 'Configurando' },
  { key: 'VALIDANDO', label: 'Validando' },
  { key: 'AUTORIZANDO', label: 'Autorizando' },
  { key: 'CARREGANDO_PROCURACOES', label: 'Procurações' },
  { key: 'SINCRONIZANDO', label: 'Sincronizando' },
  { key: 'PRONTO', label: 'Pronto' }
] as const

export function onboardingStageIndex(stage?: string | null): number {
  const index = OFFICE_ONBOARDING_STAGES.findIndex(item => item.key === stage)
  return index < 0 ? 0 : index
}

export function onboardingIsInProgress(status?: string | null): boolean {
  return [
    'configuring',
    'validating',
    'authorizing',
    'loading_proxy_powers',
    'syncing',
    'provisioning'
  ].includes(String(status || ''))
}

export function credentialStatusLabel(status?: string | null): string {
  switch (String(status || '').toUpperCase()) {
    case 'ACTIVE':
      return 'Ativo'
    case 'EXPIRED':
      return 'Vencido'
    case 'REVOKED':
      return 'Removido'
    case 'PENDING':
      return 'Pendente'
    default:
      return status || 'Ausente'
  }
}

export function credentialAlerts(credential: OfficeCanonicalCredential | null | undefined): string[] {
  if (!credential) return []
  const alerts: string[] = []
  if (credential.expires_alert_1) alerts.push('Vence em 1 dia')
  else if (credential.expires_alert_7) alerts.push('Vence em até 7 dias')
  else if (credential.expires_alert_30) alerts.push('Vence em até 30 dias')
  return alerts
}

/** Mensagem de erro acionável para o tenant (sem stack/OAuth). */
export function actionableOfficeError(
  message: string | null | undefined,
  fallback = 'Não foi possível carregar a configuração do escritório.'
): string {
  const raw = (message || '').trim()
  if (!raw) return fallback
  // Sanitiza jargão técnico conhecido se vazar de API legada.
  if (/oauth|mtls|etag|consumer.?secret|pfx.?password|vault/i.test(raw)) {
    return 'Há uma pendência técnica na plataforma. Complete o perfil e o certificado ou contate o suporte.'
  }
  return raw
}

export function emptyOnboarding(): OfficeOnboardingActionable {
  return {
    status: 'incomplete',
    actions: [
      { code: 'COMPLETE_PROFILE', label: 'Completar perfil' },
      { code: 'ACCEPT_CONSENT', label: 'Aceitar consentimento' },
      { code: 'UPLOAD_A1', label: 'Enviar certificado A1' }
    ]
  }
}

/** Monitores SERPRO com agenda mensal (chaves estáveis). */
export const DEFAULT_MONITOR_SCHEDULES: Array<{ key: string, label: string }> = [
  { key: 'sitfis', label: 'Situação fiscal' },
  { key: 'simples_mei', label: 'Simples Nacional | MEI' },
  { key: 'dctfweb', label: 'DCTFWeb' },
  { key: 'installments', label: 'Parcelamentos' },
  { key: 'mailbox', label: 'Caixa postal' },
  { key: 'declarations', label: 'Declarações' },
  { key: 'guides', label: 'Guias' },
  { key: 'fgts', label: 'FGTS (parcial)' }
]
