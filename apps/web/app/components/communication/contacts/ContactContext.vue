<script setup lang="ts">
import type { CommunicationContact, CommunicationIdentity, CommunicationIdentityLinkEntry } from '~/types/communication'

defineProps<{
  contact: CommunicationContact
  canMutate: boolean
  canManage: boolean
  identityLinks: CommunicationIdentityLinkEntry[]
  unlinkingKey: string | null
  exporting: boolean
}>()

const emit = defineEmits<{
  addIdentity: []
  link: [identity: CommunicationIdentity]
  unlink: [identityId: number, linkId: number]
  export: []
  openPurge: []
  jumpToMessage: [input: { conversationId: number, messageId: number }]
}>()

const items = [
  { label: 'Histórico', icon: 'i-lucide-messages-square', slot: 'conversations' },
  { label: 'Compartilhado', icon: 'i-lucide-images', slot: 'shared' },
  { label: 'Identidades', icon: 'i-lucide-smartphone', slot: 'identities' },
  { label: 'Vínculos', icon: 'i-lucide-link-2', slot: 'links' },
  { label: 'Privacidade', icon: 'i-lucide-shield-check', slot: 'privacy' }
]
</script>

<template>
  <UTabs
    :items="items"
    class="h-full min-h-0 w-full"
    :ui="{
      list: 'flex w-full overflow-x-auto',
      trigger: 'min-w-max flex-1 justify-center px-2',
      label: 'text-xs',
      content: 'min-h-0 flex-1 overflow-y-auto pt-2'
    }"
  >
    <template #conversations>
      <CommunicationContactsConversationHistory :contact-id="contact.id" />
    </template>
    <template #identities>
      <CommunicationContactsIdentitiesSection
        :contact="contact"
        :can-mutate="canMutate"
        @add="emit('addIdentity')"
        @link="emit('link', $event)"
      />
    </template>
    <template #links>
      <CommunicationContactsLinksSection
        :identity-links="identityLinks"
        :can-mutate="canMutate"
        :unlinking-key="unlinkingKey"
        @unlink="(identityId, linkId) => emit('unlink', identityId, linkId)"
      />
    </template>
    <template #shared>
      <CommunicationSharedContent
        compact
        :contact-id="contact.id"
        @jump="emit('jumpToMessage', $event)"
      />
    </template>
    <template #privacy>
      <CommunicationContactsPrivacySection
        :contact="contact"
        :can-manage="canManage"
        :exporting="exporting"
        @export="emit('export')"
        @open-purge="emit('openPurge')"
      />
    </template>
  </UTabs>
</template>
