import type {
  OfficeRole,
  OfficeSerproAuthorization,
  SerproChecklistStep,
  SerproChecklistStepId,
  SerproNextAction,
  SerproPlatformHealth
} from '~/types/api'

export interface ChecklistContext {
  auth: OfficeSerproAuthorization | null
  health?: SerproPlatformHealth | null
  /** Há ao menos um poder ACTIVE listado para o office. */
  hasActiveProxyPower?: boolean
  /** Cliente + operação já selecionados/elegíveis no painel. */
  hasClientOperationReady?: boolean
  role?: OfficeRole | null
}

const STEP_ORDER: SerproChecklistStepId[] = [
  'environment',
  'author',
  'certificate_termo',
  'token',
  'proxy_power',
  'client_operation'
]

function roleAllows(action: SerproNextAction, role?: OfficeRole | null): boolean {
  if (!action.roles || action.roles.length === 0) return true
  if (!role) return false
  return action.roles.includes(role)
}

/**
 * Monta checklist tenant: ambiente → autor → cert/Termo → token → procuração/poder → cliente/operação.
 */
export function buildSerproOnboardingChecklist(ctx: ChecklistContext): SerproChecklistStep[] {
  const auth = ctx.auth
  const role = ctx.role
  const status = String(auth?.status || '').toUpperCase()
  const termoState = String(auth?.termo_authorization_state || auth?.authorization_state || '').toUpperCase()

  const envDone = Boolean(auth?.environment)
  const authorDone = Boolean(auth?.author_identity_masked)
  const certMode = String(auth?.certificate_mode || '')
  const needsManagedA1 = certMode === 'MANAGED_A1'
  const certOk = needsManagedA1 ? Boolean(auth?.has_managed_a1) : true
  const termoOk = Boolean(auth?.has_termo)
    && !['REJECTED', 'EXPIRED', 'PENDING'].includes(termoState)
  const termoSerproOk = termoState === 'SERPRO_ACCEPTED' || termoState === 'LOCAL_VALIDATED' || termoOk
  const certTermoDone = certOk && termoOk && termoSerproOk
  const tokenDone = Boolean(auth?.has_procurador_token)
    && status !== 'EXPIRED'
    && status !== 'BLOCKED'
    && status !== 'REVOKED'
  const proxyDone = Boolean(ctx.hasActiveProxyPower)
  const clientOpDone = Boolean(ctx.hasClientOperationReady)

  const flags: Record<SerproChecklistStepId, boolean> = {
    environment: envDone,
    author: authorDone,
    certificate_termo: certTermoDone,
    token: tokenDone,
    proxy_power: proxyDone,
    client_operation: clientOpDone
  }

  // first incomplete is current; previous incomplete block later
  let foundCurrent = false
  const steps: SerproChecklistStep[] = STEP_ORDER.map((id) => {
    const done = flags[id]
    let stepStatus: SerproChecklistStep['status']
    if (done) {
      stepStatus = 'done'
    } else if (!foundCurrent) {
      // blocked if a previous required step is incomplete
      const idx = STEP_ORDER.indexOf(id)
      const prevBlocked = STEP_ORDER.slice(0, idx).some(pid => !flags[pid])
      if (prevBlocked && id !== 'environment') {
        stepStatus = 'blocked'
      } else {
        stepStatus = 'current'
        foundCurrent = true
      }
    } else {
      stepStatus = 'pending'
    }

    return buildStep(id, stepStatus, auth, role)
  })

  // Platform health kill switch note on environment
  if (ctx.health?.kill_switch === true || (typeof ctx.health?.kill_switch === 'object' && ctx.health.kill_switch)) {
    const env = steps.find(s => s.id === 'environment')
    if (env && env.status === 'done') {
      env.reasons.push('Kill switch global pode bloquear egress mesmo com onboarding completo.')
    }
  }

  return steps
}

function buildStep(
  id: SerproChecklistStepId,
  status: SerproChecklistStep['status'],
  auth: OfficeSerproAuthorization | null,
  role?: OfficeRole | null
): SerproChecklistStep {
  const base = STEP_META[id]
  const actions = nextActionsForStep(id, auth).filter(a => roleAllows(a, role))
  const reasons = reasonsForStep(id, auth)

  return {
    id,
    label: base.label,
    description: base.description,
    status,
    href: base.href,
    reasons,
    next_actions: actions
  }
}

const STEP_META: Record<SerproChecklistStepId, { label: string, description: string, href: string }> = {
  environment: {
    label: 'Ambiente',
    description: 'Confirme Demonstração SERPRO ou Produção na autorização do escritório.',
    href: '/conta/escritorio'
  },
  author: {
    label: 'Autor do Pedido',
    description: 'Identidade do autor (CPF/CNPJ, inclusive alfanumérico) e modo de certificado.',
    href: '/conta/escritorio'
  },
  certificate_termo: {
    label: 'Certificado / Termo',
    description: 'A1 gerenciado (se aplicável) e Termo de Autorização assinado no cofre.',
    href: '/conta/escritorio'
  },
  token: {
    label: 'Token do procurador',
    description: 'Renove o token via /Apoiar — o valor nunca é exibido na UI.',
    href: '/conta/escritorio'
  },
  proxy_power: {
    label: 'Procuração / poder',
    description: 'Poderes ACTIVE por contribuinte alinhados ao catálogo de serviços.',
    href: '/conta/escritorio'
  },
  client_operation: {
    label: 'Cliente / operação',
    description: 'Selecione cliente e operação elegível no monitoramento fiscal.',
    href: '/monitoring'
  }
}

function reasonsForStep(id: SerproChecklistStepId, auth: OfficeSerproAuthorization | null): string[] {
  if (!auth) {
    return id === 'environment' || id === 'author'
      ? ['Integra Contador ainda não configurado neste escritório.']
      : ['Conclua os passos anteriores.']
  }

  switch (id) {
    case 'environment':
      return auth.environment ? [] : ['Ambiente não definido.']
    case 'author':
      return auth.author_identity_masked ? [] : ['Configure o Autor do Pedido.']
    case 'certificate_termo': {
      const r: string[] = []
      if (auth.certificate_mode === 'MANAGED_A1' && !auth.has_managed_a1) {
        r.push('Envie o A1 do Autor com consentimento (sem recuperação de PFX).')
      }
      if (!auth.has_termo) r.push('Envie o Termo XML assinado.')
      const ts = String(auth.termo_authorization_state || '')
      if (ts === 'REJECTED') r.push('Termo rejeitado — revise e reenvie.')
      if (ts === 'EXPIRED') r.push('Termo expirado.')
      return r
    }
    case 'token':
      return auth.has_procurador_token
        ? []
        : ['Token do procurador ausente ou expirado — renove sem expor o valor.']
    case 'proxy_power':
      return ['Importe ou sincronize poderes ACTIVE por cliente.']
    case 'client_operation':
      return ['Escolha cliente e módulo no monitoramento para operar.']
    default:
      return []
  }
}

function nextActionsForStep(id: SerproChecklistStepId, auth: OfficeSerproAuthorization | null): SerproNextAction[] {
  switch (id) {
    case 'environment':
      return [{
        code: 'CONFIRM_ENVIRONMENT',
        label: 'Revisar ambiente da autorização',
        href: '/conta/escritorio',
        roles: ['ADMIN'],
        requires_2fa: true
      }]
    case 'author':
      return [{
        code: 'CONFIGURE_AUTHOR',
        label: 'Configurar Autor do Pedido',
        href: '/conta/escritorio',
        roles: ['ADMIN'],
        requires_2fa: true,
        severity: 'warning'
      }]
    case 'certificate_termo': {
      const actions: SerproNextAction[] = []
      if (auth?.certificate_mode === 'MANAGED_A1' && !auth.has_managed_a1) {
        actions.push({
          code: 'UPLOAD_AUTHOR_A1',
          label: 'Armazenar A1 do Autor',
          href: '/conta/escritorio',
          roles: ['ADMIN'],
          requires_2fa: true,
          severity: 'warning'
        })
      }
      actions.push({
        code: 'UPLOAD_TERMO',
        label: auth?.has_termo ? 'Reenviar Termo assinado' : 'Enviar Termo assinado',
        href: '/conta/escritorio',
        roles: ['ADMIN'],
        requires_2fa: true,
        severity: 'warning'
      })
      return actions
    }
    case 'token':
      return [{
        code: 'REFRESH_PROCURADOR_TOKEN',
        label: 'Renovar token do procurador',
        href: '/conta/escritorio',
        roles: ['ADMIN'],
        requires_2fa: true
      }]
    case 'proxy_power':
      return [
        {
          code: 'IMPORT_PROXY',
          label: 'Importar procuração',
          href: '/conta/escritorio',
          roles: ['ADMIN', 'OPERATOR']
        },
        {
          code: 'SYNC_PROXY',
          label: 'Sincronizar poderes',
          href: '/conta/escritorio',
          roles: ['ADMIN', 'OPERATOR']
        }
      ]
    case 'client_operation':
      return [{
        code: 'OPEN_MONITORING',
        label: 'Abrir monitoramento fiscal',
        href: '/monitoring',
        roles: ['ADMIN', 'OPERATOR', 'VIEWER']
      }]
    default:
      return []
  }
}

/** Filtra próximas ações globais a partir do checklist (primeiro passo incompleto). */
export function primaryNextActions(steps: SerproChecklistStep[], role?: OfficeRole | null): SerproNextAction[] {
  const current = steps.find(s => s.status === 'current' || s.status === 'blocked')
  if (!current) return []
  return current.next_actions.filter(a => roleAllows(a, role))
}
