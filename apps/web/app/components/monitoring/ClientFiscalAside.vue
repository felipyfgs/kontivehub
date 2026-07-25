<script setup lang="ts">
/**
 * Sidebar interno do detalhe fiscal do cliente:
 * seletor de empresa + atalhos CRM + UNavigationMenu vertical.
 */
import type { NavigationMenuItem } from '@nuxt/ui'
import type { Client } from '~/types/api'
import { clientCrmHref } from '~/utils/client-cross-links'
import { clientIsMei } from '~/utils/client-detail-tabs'
import { formatCnpj } from '~/utils/format'

const props = withDefaults(defineProps<{
  client: Client | null
  clientId: number
  links: NavigationMenuItem[][]
  loading?: boolean
  /** true = só ícones; false = labels + contexto. */
  collapsed?: boolean
}>(), {
  loading: false,
  collapsed: true
})

const emit = defineEmits<{
  switchClient: [payload: { id: number, isMei: boolean }]
}>()

const switcherOpen = ref(false)

function onClientPicked(next: Client | null) {
  if (!next || next.id === props.clientId) return
  switcherOpen.value = false
  emit('switchClient', { id: next.id, isMei: clientIsMei(next) })
}
</script>

<template>
  <div
    class="flex h-full min-h-0 flex-col gap-3"
    :class="collapsed ? 'items-center' : undefined"
    data-testid="monitoring-client-fiscal-aside"
  >
    <div
      v-if="loading && !client"
      class="w-full space-y-2"
      role="status"
      aria-label="Carregando cliente"
    >
      <USkeleton class="h-8 w-full rounded-md" />
      <USkeleton
        v-if="!collapsed"
        class="h-4 w-2/3 rounded-md"
      />
    </div>

    <div
      v-else
      class="w-full shrink-0 space-y-2 border-b border-default pb-3"
      :class="collapsed ? 'flex flex-col items-center border-none pb-0' : undefined"
      data-testid="monitoring-client-switcher"
    >
      <template v-if="!collapsed">
        <FiscalClientPicker
          :model-value="clientId"
          class="w-full min-w-0"
          placeholder="Trocar empresa…"
          @select="onClientPicked"
        />
        <div
          v-if="client"
          class="flex flex-wrap items-center gap-1.5 text-xs"
        >
          <UBadge
            size="sm"
            :color="client.is_active ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ client.is_active ? 'Ativo' : 'Inativo' }}
          </UBadge>
          <span class="truncate text-muted tabular-nums">
            {{ client.cnpj ? formatCnpj(client.cnpj) : client.root_cnpj }}
          </span>
        </div>
      </template>

      <UPopover
        v-else
        v-model:open="switcherOpen"
        :content="{ side: 'right', align: 'start', sideOffset: 8 }"
      >
        <UTooltip text="Trocar empresa">
          <UButton
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-building-2"
            square
            aria-label="Trocar empresa"
            data-testid="monitoring-client-switcher-collapsed"
          />
        </UTooltip>
        <template #content>
          <div class="w-72 p-2">
            <FiscalClientPicker
              :model-value="clientId"
              class="w-full min-w-0"
              placeholder="Trocar empresa…"
              @select="onClientPicked"
            />
          </div>
        </template>
      </UPopover>

      <div
        class="flex gap-1"
        :class="collapsed ? 'flex-col' : 'flex-row'"
      >
        <UTooltip text="Abrir cadastro">
          <UButton
            size="xs"
            color="primary"
            variant="soft"
            icon="i-lucide-clipboard-list"
            :label="collapsed ? undefined : 'Cadastro'"
            :square="collapsed"
            :to="clientCrmHref(clientId, 'cadastro')"
            data-testid="monitoring-client-to-cadastro"
          />
        </UTooltip>
        <UTooltip text="Dados adicionais">
          <UButton
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-list"
            :label="collapsed ? undefined : 'Adicionais'"
            :square="collapsed"
            :to="clientCrmHref(clientId, 'dados-adicionais')"
            data-testid="monitoring-client-to-config"
          />
        </UTooltip>
      </div>
    </div>

    <UNavigationMenu
      :items="links"
      :collapsed="collapsed"
      orientation="vertical"
      tooltip
      highlight
      class="w-full min-w-0"
      data-testid="monitoring-client-section-navigation"
      aria-label="Navegação fiscal do cliente"
    />
  </div>
</template>
