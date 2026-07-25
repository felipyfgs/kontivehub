<script setup lang="ts">
/**
 * Lista de departamentos em settings.
 * Fonte: .local/reference/nuxt-dashboard-template/app/components/settings/MembersList.vue
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { WorkDepartment } from '~/types/work'

const props = defineProps<{
  departments: WorkDepartment[]
  togglingId?: number | null
}>()

const emit = defineEmits<{
  toggle: [department: WorkDepartment]
}>()

function menuItems(department: WorkDepartment): DropdownMenuItem[][] {
  return [[{
    label: department.is_active ? 'Desativar' : 'Reativar',
    icon: department.is_active ? 'i-lucide-circle-off' : 'i-lucide-circle-check',
    color: (department.is_active ? 'warning' : 'success') as 'warning' | 'success',
    onSelect: () => emit('toggle', department)
  }]]
}
</script>

<template>
  <ul
    role="list"
    class="divide-y divide-default"
    data-testid="departments-list"
  >
    <li
      v-for="department in props.departments"
      :key="department.id"
      class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6"
      :data-testid="`department-row-${department.id}`"
    >
      <div class="flex min-w-0 items-center gap-3">
        <div
          class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-elevated text-sm font-semibold text-muted"
          aria-hidden="true"
        >
          {{ department.code.slice(0, 3).toUpperCase() }}
        </div>

        <div class="min-w-0 text-sm">
          <p class="truncate font-medium text-highlighted">
            {{ department.name }}
          </p>
          <p class="truncate text-muted">
            {{ department.code }}
          </p>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        <UBadge
          size="sm"
          variant="subtle"
          :color="department.is_active ? 'success' : 'neutral'"
          :label="department.is_active ? 'Ativo' : 'Inativo'"
        />

        <UButton
          size="sm"
          color="neutral"
          variant="soft"
          class="hidden sm:inline-flex"
          :loading="props.togglingId === department.id"
          :label="department.is_active ? 'Desativar' : 'Reativar'"
          :data-testid="`department-toggle-${department.id}`"
          @click="emit('toggle', department)"
        />

        <UDropdownMenu
          :items="menuItems(department)"
          :content="{ align: 'end' }"
        >
          <UButton
            icon="i-lucide-ellipsis-vertical"
            color="neutral"
            variant="ghost"
            :aria-label="`Ações de ${department.name}`"
            :loading="props.togglingId === department.id"
          />
        </UDropdownMenu>
      </div>
    </li>
  </ul>
</template>
