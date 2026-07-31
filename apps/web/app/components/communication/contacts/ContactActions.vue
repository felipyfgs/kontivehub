<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { CommunicationContact } from '~/types/communication'
import {
  COMMUNICATION_CONTACT_ACTION_LABELS,
  communicationContactActions,
  communicationContactRowActionsAriaLabel
} from '~/utils/communication-contacts'
import { communicationContactPath } from '~/utils/communication-routes'

const props = withDefaults(defineProps<{
  contact: CommunicationContact
  canManage: boolean
  label?: string
  compact?: boolean
  includeOpen?: boolean
  includeConversations?: boolean
  onExport?: () => void
  onPurge?: () => void
}>(), { label: 'Ações', compact: true, includeOpen: true, includeConversations: true })

const items = computed(() => communicationContactActions(props.contact, props.canManage, {
  onExport: props.onExport,
  onPurge: props.onPurge
}).map(group => group.map(item => item.label === COMMUNICATION_CONTACT_ACTION_LABELS.openDetail
  ? { ...item, to: communicationContactPath(props.contact.id) }
  : item).filter(item =>
  (props.includeOpen || item.label !== COMMUNICATION_CONTACT_ACTION_LABELS.openDetail)
  && (props.includeConversations || item.label !== COMMUNICATION_CONTACT_ACTION_LABELS.goToConversations)
)).filter(group => group.length) as DropdownMenuItem[][])
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      color="neutral"
      :variant="compact ? 'ghost' : 'outline'"
      :icon="compact ? 'i-lucide-ellipsis-vertical' : 'i-lucide-ellipsis'"
      :label="compact ? undefined : label"
      :square="compact"
      :aria-label="communicationContactRowActionsAriaLabel(contact)"
      data-testid="communication-contact-actions"
    />
  </UDropdownMenu>
</template>
