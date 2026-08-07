import type { ComposerDraftFamily } from '~/types/communication/composer-draft'
import type { OutboundCapabilities } from '~/types/communication/conversations'
import { composerCapability } from './communication-composer-capabilities'

export type ComposerLauncherGroup = 'FILES_MEDIA' | 'CLIENT_CONTEXT' | 'CREATE' | 'MORE'

export interface ComposerLauncherAction {
  id: string
  label: string
  icon: string
  family?: ComposerDraftFamily
}

export const composerLauncherGroups: ReadonlyArray<{ id: ComposerLauncherGroup, label: string, icon: string, actions: readonly ComposerLauncherAction[] }> = [
  { id: 'FILES_MEDIA', label: 'Arquivos e mídia', icon: 'i-lucide-paperclip', actions: [
    { id: 'media', label: 'Fotos e vídeos', icon: 'i-lucide-image', family: 'MEDIA_BATCH' },
    { id: 'document', label: 'Documento', icon: 'i-lucide-file-text', family: 'MEDIA_BATCH' },
    { id: 'camera', label: 'Câmera', icon: 'i-lucide-camera', family: 'MEDIA_BATCH' },
    { id: 'audio', label: 'Áudio', icon: 'i-lucide-mic', family: 'AUDIO' }
  ] },
  { id: 'CLIENT_CONTEXT', label: 'Cliente e contexto', icon: 'i-lucide-contact-round', actions: [
    { id: 'location', label: 'Localização', icon: 'i-lucide-map-pin', family: 'LOCATION' },
    { id: 'contacts', label: 'Contatos', icon: 'i-lucide-contact-round', family: 'CONTACTS' }
  ] },
  { id: 'CREATE', label: 'Criar', icon: 'i-lucide-wand-sparkles', actions: [
    { id: 'poll', label: 'Enquete', icon: 'i-lucide-chart-no-axes-column', family: 'POLL' },
    { id: 'event', label: 'Evento', icon: 'i-lucide-calendar-days', family: 'EVENT' }
  ] },
  { id: 'MORE', label: 'Mais', icon: 'i-lucide-ellipsis', actions: [
    { id: 'sticker', label: 'Figurinha', icon: 'i-lucide-sticker', family: 'STICKER' },
    { id: 'interactive', label: 'Interativo', icon: 'i-lucide-list-checks', family: 'INTERACTIVE' }
  ] }
]

export function availableComposerLauncherGroups(capabilities: OutboundCapabilities | null) {
  return composerLauncherGroups.map(group => ({
    ...group,
    actions: group.actions.filter(action => !action.family || composerCapability(capabilities, action.family).enabled)
  })).filter(group => group.actions.length > 0)
}
