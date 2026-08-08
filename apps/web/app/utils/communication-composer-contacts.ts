import type { Contact } from '~/types/communication/contacts'

const maxNameLength = 120

function escapeVCard(value: string): string {
  return value.replace(/[\\;,\n\r]/g, char => ({ '\\': '\\\\', ';': '\\;', ',': '\\,', '\n': '\\n', '\r': '' })[char] || char)
}

export function composerContactVCard(contact: Contact): { displayName: string, vcard: string } | null {
  if (!contact.is_active) return null
  const phone = contact.identities?.find(identity => identity.channel === 'WHATSAPP' && identity.is_active && identity.phone)?.phone
  const displayName = (contact.display_name || contact.name || '').trim().slice(0, maxNameLength)
  if (!phone || !displayName) return null
  return { displayName, vcard: `BEGIN:VCARD\nVERSION:3.0\nFN:${escapeVCard(displayName)}\nTEL;TYPE=CELL:${escapeVCard(phone)}\nEND:VCARD` }
}
