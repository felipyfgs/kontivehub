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

const officeSearchId = useId()
const { me } = useDashboard()
const {
  memberships,
  loading: membershipsLoading,
  switching: membershipSwitching,
  loadMemberships,
  switchTo
} = useTenantSwitch()

const {
  offices: platformOffices,
  loading: platformLoading,
  switching: platformSwitching,
  loadError: platformLoadError,
  loadOffices,
  selectOffice,
  enabled: platformEnabled,
  privileged
} = usePlatformOfficeSelect()

const officeLabel = computed(() => me.value?.current_office?.name || me.value?.office?.name || (platformEnabled.value ? 'Selecione um escritório' : 'Escritório'))
const officeSlug = computed(() => me.value?.current_office?.slug || me.value?.office?.slug || '')
const officeId = computed(() => me.value?.current_office?.id ?? me.value?.office?.id ?? null)
const isPlatform = computed(() => isPlatformAdmin(me.value))
const identityIcon = computed(() => privileged.value ? 'i-lucide-shield' : 'i-lucide-building-2')
const useGlobalPlatformSelector = computed(() =>
  isPlatform.value && (privileged.value || me.value?.has_real_membership !== true)
)

/** Texto completo para tooltip quando a sidebar está recolhida. */
const displayLabel = computed(() => {
  if (privileged.value && officeLabel.value) {
    return `PLATFORM_ADMIN · ${officeLabel.value}`
  }
  return officeLabel.value
})

const accessibleLabel = computed(() => privileged.value
  ? `Perfil PLATFORM_ADMIN. Escritório ativo: ${officeLabel.value}. Abrir seletor global de escritórios`
  : `Escritório ativo: ${officeLabel.value}${multiMembership.value ? '. Abrir seletor entre memberships autorizadas' : '. Única membership da sessão'}`)

const multiMembership = computed(() => memberships.value.length > 1)
const switching = computed(() => membershipSwitching.value || platformSwitching.value)
const loading = computed(() => membershipsLoading.value || platformLoading.value)

interface OfficeSelectorOption {
  id: number
  label: string
  description?: string
  avatar: {
    alt: string
    icon: 'i-lucide-building-2'
  }
  disabled?: boolean
}

const selectorOptions = computed<OfficeSelectorOption[]>(() => {
  // PLATFORM_ADMIN sem membership real: seletor global, quando habilitado no backend.
  if (useGlobalPlatformSelector.value) {
    return platformOffices.value
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
      id: m.office_id,
      label: m.office_name || `Escritório #${m.office_id}`,
      description: [m.office_slug, m.role].filter(Boolean).join(' · ') || undefined,
      avatar: {
        alt: m.office_name || 'Escritório',
        icon: 'i-lucide-building-2' as const
      },
      disabled: switching.value
    }))
  }

  // Mantém a identidade da sessão visível enquanto memberships são carregadas.
  return officeId.value
    ? [{
        id: officeId.value,
        label: officeLabel.value,
        description: officeSlug.value ? `Slug: ${officeSlug.value}` : undefined,
        avatar: {
          alt: officeLabel.value,
          icon: 'i-lucide-building-2' as const
        },
        disabled: switching.value
      }]
    : []
})

const selectedSelectorId = computed(() => officeId.value ?? undefined)

const footerTitle = computed(() => {
  return multiMembership.value ? 'Somente memberships autorizadas' : 'Escritório da sessão'
})

const footerDescription = computed(() => {
  if (multiMembership.value) {
    return officeSlug.value ? `Ativo: ${officeSlug.value}` : 'Troca explícita · sem office livre'
  }
  return officeSlug.value ? `Slug: ${officeSlug.value} · única membership` : 'Vinculado ao usuário autenticado'
})

function handleOfficeSelection(value: unknown) {
  const targetOfficeId = Number(value)
  if (!Number.isInteger(targetOfficeId) || targetOfficeId <= 0 || targetOfficeId === officeId.value) return

  if (useGlobalPlatformSelector.value) {
    void selectOffice(targetOfficeId)
  } else {
    void switchTo(targetOfficeId)
  }
}

onMounted(() => {
  if (useGlobalPlatformSelector.value) {
    void loadOffices()
  } else {
    void loadMemberships()
  }
})

watch(officeId, () => {
  if (useGlobalPlatformSelector.value) {
    void loadOffices()
  } else {
    void loadMemberships()
  }
})

watch(useGlobalPlatformSelector, (v) => {
  if (v) void loadOffices()
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
      id: officeSearchId
    }"
    :content="{ align: 'start', side: 'bottom', sideOffset: 6, collisionPadding: 12 }"
    :icon="identityIcon"
    :placeholder="collapsed ? undefined : officeLabel"
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
    :aria-label="useGlobalPlatformSelector && !privileged ? `Seletor global de escritórios. ${officeLabel}` : accessibleLabel"
    aria-haspopup="listbox"
    :title="collapsed ? displayLabel : undefined"
    data-testid="office-identity"
    :data-office-id="useGlobalPlatformSelector ? 'platform-global' : 'session'"
    :data-office-name="officeLabel"
    :data-privileged="privileged ? 'true' : 'false'"
    :data-platform-seal="privileged ? 'true' : 'false'"
    @update:model-value="handleOfficeSelection"
  >
    <template #default>
      <span
        data-slot="value"
        class="pointer-events-none truncate text-left"
        :class="collapsed && 'hidden'"
        :aria-hidden="collapsed || undefined"
      >
        {{ officeLabel }}
      </span>
    </template>

    <template #content-top>
      <label
        :for="officeSearchId"
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
        data-testid="office-selector-context"
        :data-context-style="isPlatform ? 'compact' : 'detailed'"
      >
        <div
          v-if="isPlatform"
          class="flex min-w-0 items-center gap-1.5"
        >
          <span class="sr-only">
            Perfil PLATFORM_ADMIN. Escritório ativo: {{ officeLabel }}.
          </span>
          <UIcon
            name="i-lucide-shield"
            class="size-4 shrink-0 text-dimmed"
            aria-hidden="true"
          />
          <span class="text-xs font-medium text-highlighted">Plataforma</span>
          <template v-if="officeLabel !== 'Plataforma'">
            <span
              class="text-xs text-dimmed"
              aria-hidden="true"
            >·</span>
            <span class="truncate text-xs text-muted">{{ officeLabel }}</span>
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
