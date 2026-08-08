import { describe, expect, it } from 'vitest'
import {
  composerCapability,
  composerCapabilityVariant,
  composerMediaDraftCapability
} from '~/utils/communication-composer-capabilities'

const baseCapabilities = {
  enabled: true,
  requires_permission: 'communication.reply',
  max_media_bytes: 100,
  conversation_initiation: {
    enabled: false,
    reason: 'inbox_unavailable',
    requires_permission: 'communication.reply'
  }
}

describe('communication composer capabilities', () => {
  it('bloqueia uma família indisponível preservando o motivo estável retornado pela API', () => {
    const capability = composerCapability({
      ...baseCapabilities,
      kinds: { EVENT: { enabled: false, reason: 'rollout_disabled' } }
    }, 'EVENT')

    expect(capability).toMatchObject({ enabled: false, reason: 'rollout_disabled', maxBytes: 100 })
  })

  it('não infere suporte para famílias estruturadas ausentes', () => {
    expect(composerCapability({ ...baseCapabilities, kinds: {} }, 'POLL').enabled).toBe(false)
  })

  it('lê limites e variantes do DTO discriminado sem cair em campos legados', () => {
    const capabilities = {
      ...baseCapabilities,
      kinds: {
        CONTACT: { enabled: true, limits: { max_items: 4 } },
        VIDEO: {
          enabled: true,
          limits: { max_bytes: 80, mime_types: ['video/mp4', 'video/webm'] },
          variants: {
            gif: { enabled: true, reason: null },
            provider_search: { enabled: false, reason: 'GIF_PROVIDER_DISABLED' }
          }
        }
      }
    }

    expect(composerCapability(capabilities, 'CONTACTS').maxItems).toBe(4)
    expect(composerCapability(capabilities, 'VIDEO')).toMatchObject({
      maxBytes: 80,
      mimeTypes: ['video/mp4', 'video/webm']
    })
    expect(composerCapabilityVariant(capabilities, 'VIDEO', 'provider_search')).toEqual({
      enabled: false,
      reason: 'GIF_PROVIDER_DISABLED'
    })
  })

  it('usa capability singular para um item e MEDIA_BATCH somente a partir de dois', () => {
    const capabilities = {
      ...baseCapabilities,
      kinds: {
        IMAGE: {
          enabled: true,
          limits: { max_bytes: 100, mime_types: ['image/png'] }
        },
        VIDEO: {
          enabled: true,
          limits: { max_bytes: 100, mime_types: ['video/mp4'] },
          variants: { gif: { enabled: true, reason: null } }
        },
        MEDIA_BATCH: {
          enabled: false,
          reason: 'MESSAGE_BATCH_UNIMPLEMENTED',
          limits: { max_items: 10 }
        }
      }
    }
    const image = new File(['image'], 'image.png', { type: 'image/png' })
    const item = {
      file: image,
      kind: 'IMAGE' as const,
      gif: false,
      ptv: false,
      viewOnce: false
    }

    expect(composerMediaDraftCapability(capabilities, [item]).enabled).toBe(true)
    expect(composerMediaDraftCapability(capabilities, [item, item])).toMatchObject({
      enabled: false,
      reason: 'MESSAGE_BATCH_UNIMPLEMENTED'
    })
  })
})
