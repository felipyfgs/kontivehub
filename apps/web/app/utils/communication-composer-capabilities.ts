import type { OutboundCapabilities } from '~/types/communication/conversations'
import type {
  ComposerDraftFamily,
  ComposerMediaItem,
  ComposerMediaKind
} from '~/types/communication/composer-draft'

export interface ComposerCapabilityVariant {
  enabled: boolean
  reason: string | null
}

export interface ComposerCapability {
  enabled: boolean
  reason: string | null
  maxBytes: number | null
  maxDurationSeconds: number | null
  maxItems: number | null
  mimeTypes: readonly string[]
  variants: Readonly<Record<string, ComposerCapabilityVariant>>
}

type ComposerCapabilityFamily = ComposerDraftFamily | ComposerMediaKind

const familyKeys: Record<ComposerCapabilityFamily, string> = {
  TEXT: 'TEXT',
  MEDIA_BATCH: 'MEDIA_BATCH',
  AUDIO: 'AUDIO',
  STICKER: 'STICKER',
  LOCATION: 'LOCATION',
  CONTACTS: 'CONTACT',
  POLL: 'POLL',
  EVENT: 'EVENT',
  INTERACTIVE: 'INTERACTIVE',
  IMAGE: 'IMAGE',
  VIDEO: 'VIDEO',
  DOCUMENT: 'DOCUMENT'
}

function asRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? value as Record<string, unknown> : {}
}

function finiteNumber(value: unknown): number | null {
  const number = Number(value)
  return Number.isFinite(number) && number >= 0 ? number : null
}

function capabilityReason(raw: Record<string, unknown>, fallback: string): string {
  return typeof raw.reason === 'string' && raw.reason ? raw.reason : fallback
}

function capabilityVariants(raw: Record<string, unknown>): Record<string, ComposerCapabilityVariant> {
  return Object.fromEntries(Object.entries(asRecord(raw.variants)).map(([key, value]) => {
    const variant = asRecord(value)
    const enabled = variant.enabled === true
    return [key, {
      enabled,
      reason: enabled ? null : capabilityReason(variant, 'Esta variação não está disponível agora.')
    }]
  }))
}

function disabledCapability(reason: string): ComposerCapability {
  return {
    enabled: false,
    reason,
    maxBytes: null,
    maxDurationSeconds: null,
    maxItems: null,
    mimeTypes: [],
    variants: {}
  }
}

/** Keeps the permissive generated API shape at this boundary and fails closed. */
export function composerCapability(
  capabilities: OutboundCapabilities | null,
  family: ComposerCapabilityFamily
): ComposerCapability {
  if (!capabilities) {
    return disabledCapability('Não foi possível confirmar este tipo de envio.')
  }

  const raw = asRecord(capabilities.kinds[familyKeys[family]])
  if (!Object.keys(raw).length) {
    return disabledCapability('Este tipo de mensagem não está habilitado para esta caixa de entrada.')
  }

  const limits = asRecord(raw.limits)
  const enabled = capabilities.enabled === true && raw.enabled === true
  const mimeTypes = Array.isArray(limits.mime_types)
    ? limits.mime_types.filter((value): value is string => typeof value === 'string')
    : []

  return {
    enabled,
    reason: enabled
      ? null
      : capabilityReason(raw, 'Este tipo de mensagem não está disponível agora.'),
    maxBytes: finiteNumber(limits.max_bytes ?? raw.max_bytes)
      ?? (family === 'TEXT' ? null : finiteNumber(capabilities.max_media_bytes)),
    maxDurationSeconds: finiteNumber(limits.max_duration_seconds ?? raw.max_duration_seconds),
    maxItems: finiteNumber(limits.max_items ?? raw.max_items),
    mimeTypes,
    variants: capabilityVariants(raw)
  }
}

export function composerCapabilityVariant(
  capabilities: OutboundCapabilities | null,
  family: ComposerCapabilityFamily,
  variant: string
): ComposerCapabilityVariant {
  const capability = composerCapability(capabilities, family)
  if (!capability.enabled) return { enabled: false, reason: capability.reason }
  return capability.variants[variant]
    ?? { enabled: false, reason: 'Esta variação não está habilitada para esta caixa de entrada.' }
}

export function composerMediaKindCapability(
  capabilities: OutboundCapabilities | null,
  kind: ComposerMediaKind
): ComposerCapability {
  return composerCapability(capabilities, kind)
}

export function composerMediaItemReason(
  capabilities: OutboundCapabilities | null,
  item: Pick<ComposerMediaItem, 'file' | 'kind' | 'gif' | 'ptv' | 'viewOnce'>
): string | null {
  const capability = composerMediaKindCapability(capabilities, item.kind)
  if (!capability.enabled) return capability.reason
  if (capability.maxBytes !== null && item.file.size > capability.maxBytes) {
    return `O arquivo excede o limite de ${Math.ceil(capability.maxBytes / 1024 / 1024)} MB.`
  }
  if (capability.mimeTypes.length && !capability.mimeTypes.includes(item.file.type)) {
    return 'O formato do arquivo não é aceito para este tipo de mensagem.'
  }

  const variants = [
    item.gif ? 'gif' : null,
    item.ptv ? 'ptv' : null,
    item.viewOnce ? 'view_once' : null
  ].filter((variant): variant is string => variant !== null)
  for (const variant of variants) {
    const support = capability.variants[variant]
    if (!support?.enabled) {
      return support?.reason || 'Esta variação não está disponível agora.'
    }
  }
  return null
}

export function composerMediaDraftCapability(
  capabilities: OutboundCapabilities | null,
  items: readonly Pick<ComposerMediaItem, 'file' | 'kind' | 'gif' | 'ptv' | 'viewOnce'>[]
): ComposerCapability {
  if (!items.length) return composerCapability(capabilities, 'MEDIA_BATCH')

  const firstItemReason = items
    .map(item => composerMediaItemReason(capabilities, item))
    .find((reason): reason is string => Boolean(reason))
  if (firstItemReason) return disabledCapability(firstItemReason)

  if (items.length === 1) return composerMediaKindCapability(capabilities, items[0]!.kind)

  const batch = composerCapability(capabilities, 'MEDIA_BATCH')
  if (!batch.enabled) return batch
  if (batch.maxItems !== null && items.length > batch.maxItems) {
    return { ...batch, enabled: false, reason: `O lote aceita no máximo ${batch.maxItems} itens.` }
  }
  if (batch.mimeTypes.length && items.some(item => !batch.mimeTypes.includes(item.file.type))) {
    return {
      ...batch,
      enabled: false,
      reason: 'Um dos formatos selecionados não é aceito em lote.'
    }
  }
  return batch
}

export function composerCapabilityReason(
  capabilities: OutboundCapabilities | null,
  family: ComposerDraftFamily
): string | null {
  return composerCapability(capabilities, family).reason
}
