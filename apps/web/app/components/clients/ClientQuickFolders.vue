<script setup lang="ts">
/**
 * Atalhos rápidos para hubs do produto relacionados ao cliente.
 */
import { clientFiscalHref } from '~/utils/client-cross-links'

const props = defineProps<{
  clientId: number
}>()

type FolderItem = {
  id: string
  label: string
  icon: string
  to?: string
  soon?: boolean
}

const items = computed((): FolderItem[] => [
  {
    id: 'monitor',
    label: 'Monitoramento fiscal',
    icon: 'i-lucide-radar',
    to: clientFiscalHref(props.clientId)
  },
  {
    id: 'docs',
    label: 'Documentos',
    icon: 'i-lucide-folder-open',
    to: `/docs?client_id=${props.clientId}`
  },
  {
    id: 'connect',
    label: 'ConnectHub',
    icon: 'i-lucide-plug',
    soon: true
  },
  {
    id: 'task',
    label: 'TaskHub',
    icon: 'i-lucide-list-checks',
    soon: true
  },
  {
    id: 'xml',
    label: 'XMLHub',
    icon: 'i-lucide-file-code-2',
    soon: true
  }
])
</script>

<template>
  <UCard
    variant="subtle"
    :ui="{ body: 'space-y-2 p-4 sm:p-4' }"
    data-testid="client-quick-folders"
  >
    <h2 class="text-sm font-semibold text-highlighted">
      Pastas rápidas
    </h2>

    <ul class="space-y-1">
      <li
        v-for="item in items"
        :key="item.id"
      >
        <NuxtLink
          v-if="item.to && !item.soon"
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-highlighted transition-colors hover:bg-elevated"
        >
          <UIcon
            :name="item.icon"
            class="size-4 shrink-0 text-muted"
          />
          <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          <UIcon
            name="i-lucide-arrow-up-right"
            class="size-3.5 shrink-0 text-muted"
          />
        </NuxtLink>
        <div
          v-else
          class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-muted"
        >
          <UIcon
            :name="item.icon"
            class="size-4 shrink-0"
          />
          <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          <UBadge
            color="neutral"
            variant="subtle"
            size="sm"
            label="Em breve"
          />
        </div>
      </li>
    </ul>
  </UCard>
</template>
