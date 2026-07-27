<script setup lang="ts">
/**
 * Grade de cards de membros — arquétipo MembersList do template, adaptado a cards.
 * Fonte: .local/reference/nuxt-dashboard-template/app/components/settings/MembersList.vue
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { TenantMember, TenantRole } from '~/types/api'

const props = defineProps<{
  members: TenantMember[]
  actingId?: number | null
  canMutate?: boolean
}>()

const emit = defineEmits<{
  changeRole: [member: TenantMember, role: TenantRole]
  deactivate: [member: TenantMember]
  reactivate: [member: TenantMember]
  regenerate: [member: TenantMember]
}>()

const roleItems: Array<{ label: string, value: TenantRole }> = [
  { label: 'Administrador', value: 'tenant_admin' },
  { label: 'Usuário', value: 'tenant_user' }
]

function roleLabel(role: string): string {
  return roleItems.find(r => r.value === role)?.label || role
}

function statusColor(status: string): 'success' | 'warning' | 'error' | 'neutral' {
  switch (status) {
    case 'active': return 'success'
    case 'pending': return 'warning'
    case 'expired': return 'error'
    default: return 'neutral'
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'active': return 'Ativo'
    case 'pending': return 'Pendente'
    case 'expired': return 'Expirado'
    case 'deactivated': return 'Desativado'
    default: return status
  }
}

function menuItems(member: TenantMember): DropdownMenuItem[][] {
  if (!props.canMutate) return []

  const items: DropdownMenuItem[] = []

  if (member.status === 'pending' || member.status === 'expired') {
    items.push({
      label: 'Regenerar acesso',
      icon: 'i-lucide-refresh-cw',
      onSelect: () => emit('regenerate', member)
    })
  }

  if (member.is_active || member.status === 'active') {
    items.push({
      label: 'Desativar',
      icon: 'i-lucide-circle-off',
      color: 'warning',
      onSelect: () => emit('deactivate', member)
    })
  } else if (member.status === 'deactivated') {
    items.push({
      label: 'Reativar',
      icon: 'i-lucide-circle-check',
      color: 'success',
      onSelect: () => emit('reactivate', member)
    })
  }

  return items.length ? [items] : []
}

function onRoleChange(member: TenantMember, role: TenantRole) {
  if (role === member.role) return
  emit('changeRole', member, role)
}

function canChangeRole(member: TenantMember): boolean {
  return Boolean(props.canMutate && (member.is_active || member.status === 'active'))
}
</script>

<template>
  <ul
    role="list"
    class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 sm:gap-4 sm:p-6 xl:grid-cols-3"
    data-testid="team-list"
  >
    <li
      v-for="member in props.members"
      :key="member.id"
      class="min-w-0"
      :data-testid="`team-card-${member.id}`"
    >
      <article
        class="flex h-full min-w-0 flex-col gap-3 rounded-lg border border-default bg-default p-4"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="flex min-w-0 items-center gap-3">
            <UAvatar
              :alt="member.name || member.email || '?'"
              size="md"
            />
            <div class="min-w-0 text-sm">
              <p class="truncate font-medium text-highlighted">
                {{ member.name || '—' }}
              </p>
              <p class="truncate text-muted">
                {{ member.email || '—' }}
              </p>
            </div>
          </div>

          <UDropdownMenu
            v-if="props.canMutate && menuItems(member).length"
            :items="menuItems(member)"
            :content="{ align: 'end' }"
          >
            <UButton
              icon="i-lucide-ellipsis-vertical"
              color="neutral"
              variant="ghost"
              :aria-label="`Ações de ${member.name || member.email}`"
              :loading="props.actingId === member.id"
              :data-testid="`team-actions-${member.id}`"
            />
          </UDropdownMenu>
        </div>

        <div class="mt-auto flex min-w-0 flex-wrap items-center gap-2">
          <UBadge
            size="sm"
            variant="subtle"
            :color="statusColor(member.status)"
            :label="statusLabel(member.status)"
            data-testid="team-card-status"
          />

          <USelect
            v-if="canChangeRole(member)"
            :model-value="member.role"
            :items="roleItems"
            value-key="value"
            label-key="label"
            color="neutral"
            size="sm"
            class="w-full min-w-0 sm:w-36"
            :disabled="props.actingId === member.id"
            :aria-label="`Papel de ${member.name || member.email}`"
            data-testid="team-card-role-select"
            @update:model-value="(v: TenantRole) => onRoleChange(member, v)"
          />
          <UBadge
            v-else
            size="sm"
            variant="outline"
            color="neutral"
            :label="roleLabel(member.role)"
            data-testid="team-card-role"
          />
          <UBadge
            v-if="member.permission_profile"
            size="sm"
            variant="subtle"
            color="neutral"
            :label="member.permission_profile.name"
            data-testid="team-card-permission-profile"
          />
        </div>
      </article>
    </li>
  </ul>
</template>
