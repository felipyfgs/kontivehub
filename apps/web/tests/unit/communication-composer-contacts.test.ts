import { describe, expect, it } from 'vitest'
import { composerContactVCard } from '~/utils/communication-composer-contacts'

describe('composer contacts', () => {
  it('usa somente identidade WhatsApp ativa com telefone autorizado, nunca a máscara', () => {
    expect(composerContactVCard({ id: 1, is_active: true, display_name: 'Ana; Silva', is_provisional: false, identities: [{ id: 2, channel: 'WHATSAPP', is_active: true, address_masked: '***', phone: '+5511999', links: [] }] })).toEqual({ displayName: 'Ana; Silva', vcard: 'BEGIN:VCARD\nVERSION:3.0\nFN:Ana\\; Silva\nTEL;TYPE=CELL:+5511999\nEND:VCARD' })
  })
  it('bloqueia contato inativo ou sem telefone WhatsApp revalidado', () => {
    expect(composerContactVCard({ id: 1, is_active: false, name: 'Ana', is_provisional: false })).toBeNull()
    expect(composerContactVCard({ id: 1, is_active: true, name: 'Ana', is_provisional: false, identities: [] })).toBeNull()
  })
})
