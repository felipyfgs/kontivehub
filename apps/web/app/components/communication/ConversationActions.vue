<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { Conversation, ConversationAction, ConversationActionPayload, ConversationStatus } from '~/types/communication/conversations'
import type { Inbox } from '~/types/communication/inboxes'
import type { Label } from '~/types/communication/contacts'
import type { WorkDepartment } from '~/types/work'
import {
  communicationDisplayName,
  communicationSnoozeTomorrowMorning,
  communicationSnoozeUntil
} from '~/utils/communication'

const props = defineProps<{
  conversation: Conversation
  inbox?: Inbox | null
  departments: WorkDepartment[]
  labels: Label[]
  canView: boolean
  canReply: boolean
  disabled?: boolean
  testId?: string
  ariaLabel?: string
}>()

const emit = defineEmits<{
  action: [payload: ConversationActionPayload]
}>()

function emitAction(action: ConversationAction): void {
  emit('action', { conversation: props.conversation, action })
}

function statusAction(
  status: ConversationStatus,
  label: string,
  icon: string
): DropdownMenuItem | null {
  if (props.conversation.status === status) return null
  return {
    label,
    icon,
    onSelect: () => emitAction({
      type: 'SET_STATUS',
      status,
      snoozed_until: null
    })
  }
}

const readItems = computed<DropdownMenuItem[]>(() => {
  if (!props.canView) return []
  const unread = (props.conversation.unread_count ?? 0) > 0
  return [{
    label: unread ? 'Marcar como lida' : 'Marcar como não lida',
    icon: unread ? 'i-lucide-mail-check' : 'i-lucide-mail',
    onSelect: () => emitAction({ type: unread ? 'MARK_READ' : 'MARK_UNREAD' })
  }]
})

const statusItems = computed<DropdownMenuItem[]>(() => [
  statusAction('RESOLVED', 'Resolver', 'i-lucide-circle-check'),
  statusAction('PENDING', 'Pendente', 'i-lucide-clock-3'),
  statusAction('OPEN', 'Reabrir', 'i-lucide-rotate-ccw'),
  {
    label: 'Adiar 1 hora',
    icon: 'i-lucide-alarm-clock',
    onSelect: () => emitAction({
      type: 'SET_STATUS',
      status: 'SNOOZED',
      snoozed_until: communicationSnoozeUntil(1)
    })
  },
  {
    label: 'Adiar até amanhã 9h',
    icon: 'i-lucide-sunrise',
    onSelect: () => emitAction({
      type: 'SET_STATUS',
      status: 'SNOOZED',
      snoozed_until: communicationSnoozeTomorrowMorning()
    })
  }
].filter((item): item is DropdownMenuItem => item !== null))

const assigneeItems = computed<DropdownMenuItem[]>(() => {
  const currentId = props.conversation.assignee_membership_id ?? null
  return [{
    label: 'Sem responsável',
    icon: currentId === null ? 'i-lucide-check' : 'i-lucide-user-round-x',
    disabled: currentId === null,
    onSelect: () => emitAction({ type: 'SET_ASSIGNEE', assignee_membership_id: null })
  }, ...(props.inbox?.members ?? []).map(member => ({
    label: member.name?.trim() || `Membro #${member.id}`,
    icon: currentId === member.id ? 'i-lucide-check' : 'i-lucide-user-round-check',
    disabled: currentId === member.id,
    onSelect: () => emitAction({
      type: 'SET_ASSIGNEE' as const,
      assignee_membership_id: member.id
    })
  }))]
})

const departmentItems = computed<DropdownMenuItem[]>(() => {
  const currentId = props.conversation.work_department_id ?? null
  return [{
    label: 'Sem fila',
    icon: currentId === null ? 'i-lucide-check' : 'i-lucide-list-x',
    disabled: currentId === null,
    onSelect: () => emitAction({ type: 'SET_DEPARTMENT', work_department_id: null })
  }, ...props.departments.map(department => ({
    label: department.name,
    icon: currentId === department.id ? 'i-lucide-check' : 'i-lucide-list-tree',
    disabled: currentId === department.id,
    onSelect: () => emitAction({
      type: 'SET_DEPARTMENT' as const,
      work_department_id: department.id
    })
  }))]
})

const labelItems = computed<DropdownMenuItem[]>(() => props.labels.map((label) => {
  const assigned = props.conversation.labels?.some(item => item.id === label.id) ?? false
  return {
    label: label.name,
    type: 'checkbox' as const,
    checked: assigned,
    onSelect: (event: Event) => {
      event.preventDefault()
      emitAction({
        type: 'SET_LABEL',
        label_id: label.id,
        assigned: !assigned
      })
    }
  }
}))

const triageItems = computed<DropdownMenuItem[]>(() => {
  if (!props.canReply) return []
  const items: DropdownMenuItem[] = [{
    label: 'Status',
    icon: 'i-lucide-circle-fading-arrow-up',
    children: statusItems.value,
    content: { collisionPadding: 8 }
  }]
  if (assigneeItems.value.some(item => !item.disabled)) {
    items.push({
      label: 'Responsável',
      icon: 'i-lucide-user-round-check',
      children: assigneeItems.value,
      content: { collisionPadding: 8 }
    })
  }
  if (departmentItems.value.some(item => !item.disabled)) {
    items.push({
      label: 'Fila',
      icon: 'i-lucide-list-tree',
      children: departmentItems.value,
      content: { collisionPadding: 8 }
    })
  }
  if (labelItems.value.length) {
    items.push({
      label: 'Marcadores',
      icon: 'i-lucide-tags',
      children: labelItems.value,
      content: { collisionPadding: 8 }
    })
  }
  return items
})

const menuItems = computed<DropdownMenuItem[][]>(() => [
  readItems.value,
  triageItems.value
].filter(group => group.length > 0))

const actionLabel = computed(() => props.ariaLabel
  || `Ações de ${communicationDisplayName(props.conversation)}`)
</script>

<template>
  <UDropdownMenu
    v-if="menuItems.length"
    :items="menuItems"
    :disabled="disabled"
    :content="{
      align: 'end',
      side: 'bottom',
      sideOffset: 6,
      collisionPadding: 8
    }"
  >
    <UTooltip text="Mais ações">
      <UButton
        icon="i-lucide-ellipsis-vertical"
        color="neutral"
        variant="ghost"
        size="xs"
        square
        class="shrink-0 [@media(pointer:coarse)]:size-11"
        :disabled="disabled"
        :aria-label="actionLabel"
        :title="actionLabel"
        :data-testid="testId || 'communication-conversation-actions'"
      />
    </UTooltip>
  </UDropdownMenu>
</template>
