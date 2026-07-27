<script setup lang="ts">
import { isPlatformAdmin } from '~/utils/permissions'

/**
 * Cabeçalho da sidebar — arquétipo TeamsMenu do template
 * (`.local/reference/nuxt-dashboard-template/app/components/TeamsMenu.vue`).
 * Memberships autorizadas OU seletor global (PLATFORM_ADMIN).
 */
defineProps<{
  collapsed?: boolean
}>()

const tenantSearchId = useId()
const { me } = useDashboard()
const {
  memberships,
  loading: membershipsLoading,
  switching: membershipSwitching,
  loadMemberships,
  switchTo
} = useTenantSwitch()

const {
  tenants: platformTenants,
  loading: platformLoading,
  switching: platformSwitching,
  loadError: platformLoadError,
  loadTenants,
  selectTenant,
  enabled: platformEnabled,
  privileged
} = usePlatformTenantSelect()

const tenantLabel = computed(() => me.value?.current_tenant?.name || (platformEnabled.value ? 'Selecione um escritório' : 'Escritório'))
const tenantSlug = computed(() => me.value?.current_tenant?.slug || '')
const tenantId = computed(() => me.value?.current_tenant?.id ?? null)
const isPlatform = computed(() => isPlatformAdmin(me.value))
const identityIcon = computed(() => privileged.value ? 'i-lucide-shield' : 'i-lucide-building-2')
const useGlobalPlatformSelector = computed(() =>
  isPlatform.value && (privileged.value || me.value?.has_real_membership !== true)
)

/** Texto completo para tooltip quando a sidebar está recolhida. */
const displayLabel = computed(() => {
  if (privileged.value && tenantLabel.value) {
    return `PLATFORM_ADMIN · ${tenantLabel.value}`
  }
  return tenantLabel.value
})

const accessibleLabel = computed(() => privileged.value
  ? `Perfil PLATFORM_ADMIN. Escritório ativo: ${tenantLabel.value}. Abrir seletor global de escritórios`
  : `Escritório ativo: ${tenantLabel.value}${multiMembership.value ? '. Abrir seletor entre memberships autorizadas' : '. Única membership da sessão'}`)

const multiMembership = computed(() => memberships.value.length > 1)
const switching = computed(() => membershipSwitching.value || platformSwitching.value)
const loading = computed(() => membershipsLoading.value || platformLoading.value)

interface TenantSelectorOption {
  id: number
  label: string
  description?: string
  avatar: {
    alt: string
    icon: 'i-lucide-building-2'
  }
  disabled?: boolean
}

const selectorOptions = computed<TenantSelectorOption[]>(() => {
  // PLATFORM_ADMIN sem membership real: seletor global, quando habilitado no backend.
  if (useGlobalPlatformSelector.value) {
    return platformTenants.value
      .filter(o => o.selectable !== false && o.is_active !== false)
      .map(o => ({
        id: o.id,
        label: o.name || `Escritório #${o.id}`,
        description: [o.slug, `#${o.id}`].filter(Boolean).join(' · '),
        avatar: {
          alt: o.name || 'Escritório',
          icon: 'i-lucide-building-2' as const
        },
        disabled: switching.value
      }))
  }

  // Memberships do escritório (usuário comum).
  if (memberships.value.length) {
    return memberships.value.map(m => ({
      id: m.tenant_id,
      label: m.tenant_name || `Escritório #${m.tenant_id}`,
      description: [m.tenant_slug, m.role].filter(Boolean).join(' · ') || undefined,
      avatar: {
        alt: m.tenant_name || 'Escritório',
        icon: 'i-lucide-building-2' as const
      },
      disabled: switching.value
    }))
  }

  // Mantém a identidade da sessão visível enquanto memberships são carregadas.
  return tenantId.value
    ? [{
        id: tenantId.value,
        label: tenantLabel.value,
        description: tenantSlug.value ? `Slug: ${tenantSlug.value}` : undefined,
        avatar: {
          alt: tenantLabel.value,
          icon: 'i-lucide-building-2' as const
        },
        disabled: switching.value
      }]
    : []
})

const selectedSelectorId = computed(() => tenantId.value ?? undefined)

const footerTitle = computed(() => {
  return multiMembership.value ? 'Somente memberships autorizadas' : 'Escritório da sessão'
})

const footerDescription = computed(() => {
  if (multiMembership.value) {
    return tenantSlug.value ? `Ativo: ${tenantSlug.value}` : 'Troca explícita · sem tenant livre'
  }
  return tenantSlug.value ? `Slug: ${tenantSlug.value} · única membership` : 'Vinculado ao usuário autenticado'
})

function handleTenantSelection(value: unknown) {
  const targetTenantId = Number(value)
  if (!Number.isInteger(targetTenantId) || targetTenantId <= 0 || targetTenantId === tenantId.value) return

  if (useGlobalPlatformSelector.value) {
    void selectTenant(targetTenantId)
  } else {
    void switchTo(targetTenantId)
  }
}

onMounted(() => {
  if (useGlobalPlatformSelector.value) {
    void loadTenants()
  } else {
    void loadMemberships()
  }
})

watch(tenantId, () => {
  if (useGlobalPlatformSelector.value) {
    void loadTenants()
  } else {
    void loadMemberships()
  }
})

watch(useGlobalPlatformSelector, (v) => {
  if (v) void loadTenants()
  else void loadMemberships()
})
</script>

<template>
  <USelectMenu
    :model-value="selectedSelectorId"
    :items="selectorOptions"
    value-key="id"
    :filter-fields="['label', 'description']"
    :search-input="{
      placeholder: 'Buscar escritório…',
      icon: 'i-lucide-search',
      id: tenantSearchId
    }"
    :content="{ align: 'start', side: 'bottom', sideOffset: 6, collisionPadding: 12 }"
    :icon="identityIcon"
    :placeholder="collapsed ? undefined : tenantLabel"
    :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
    color="neutral"
    variant="ghost"
    :disabled="switching"
    :aria-busy="loading || switching"
    :class="collapsed ? 'size-8 justify-center p-0' : 'w-full py-2'"
    :ui="{
      base: 'data-[state=open]:bg-elevated',
      leading: collapsed ? 'inset-0 justify-center ps-0' : undefined,
      trailing: collapsed ? 'hidden' : undefined,
      content: 'w-88 max-w-[calc(100vw-1.5rem)] max-h-[min(28rem,var(--reka-combobox-content-available-height))]',
      viewport: 'max-h-72',
      item: 'py-2',
      itemLabel: 'whitespace-normal leading-5',
      itemDescription: 'whitespace-normal break-words leading-4',
      trailingIcon: 'text-dimmed'
    }"
    :aria-label="useGlobalPlatformSelector && !privileged ? `Seletor global de escritórios. ${tenantLabel}` : accessibleLabel"
    aria-haspopup="listbox"
    :title="collapsed ? displayLabel : undefined"
    data-testid="tenant-identity"
    :data-tenant-id="useGlobalPlatformSelector ? 'platform-global' : 'session'"
    :data-tenant-name="tenantLabel"
    :data-privileged="privileged ? 'true' : 'false'"
    :data-platform-seal="privileged ? 'true' : 'false'"
    @update:model-value="handleTenantSelection"
  >
    <template #default>
      <span
        data-slot="value"
        class="pointer-events-none truncate text-left"
        :class="collapsed && 'hidden'"
        :aria-hidden="collapsed || undefined"
      >
        {{ tenantLabel }}
      </span>
    </template>

    <template #content-top>
      <label
        :for="tenantSearchId"
        class="sr-only"
      >
        Buscar escritório por nome ou slug
      </label>
    </template>

    <template #empty="{ searchTerm }">
      <div class="flex flex-col items-center gap-1 py-2">
        <UIcon
          :name="loading ? 'i-lucide-loader-circle' : 'i-lucide-search-x'"
          class="size-5 text-dimmed"
          :class="loading && 'animate-spin'"
        />
        <span>
          {{ loading
            ? 'Carregando escritórios…'
            : platformLoadError || (searchTerm ? 'Nenhum escritório corresponde à busca' : 'Nenhum escritório disponível') }}
        </span>
      </div>
    </template>

    <template #content-bottom>
      <div
        class="border-t border-default px-3 py-2.5"
        data-testid="tenant-selector-context"
        :data-context-style="isPlatform ? 'compact' : 'detailed'"
      >
        <div
          v-if="isPlatform"
          class="flex min-w-0 items-center gap-1.5"
        >
          <span class="sr-only">
            Perfil PLATFORM_ADMIN. Escritório ativo: {{ tenantLabel }}.
          </span>
          <UIcon
            name="i-lucide-shield"
            class="size-4 shrink-0 text-dimmed"
            aria-hidden="true"
          />
          <span class="text-xs font-medium text-highlighted">Plataforma</span>
          <template v-if="tenantLabel !== 'Plataforma'">
            <span
              class="text-xs text-dimmed"
              aria-hidden="true"
            >·</span>
            <span class="truncate text-xs text-muted">{{ tenantLabel }}</span>
          </template>
        </div>

        <div
          v-else
          class="flex items-start gap-2"
        >
          <UIcon
            :name="multiMembership ? 'i-lucide-shield-check' : 'i-lucide-lock'"
            class="mt-0.5 size-4 shrink-0 text-dimmed"
          />
          <div class="min-w-0">
            <p class="text-xs font-medium text-highlighted">
              {{ footerTitle }}
            </p>
            <p class="mt-0.5 text-xs leading-4 text-muted">
              {{ footerDescription }}
            </p>
          </div>
        </div>
      </div>
    </template>
  </USelectMenu>
</template>
