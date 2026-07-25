<script setup lang="ts">
/**
 * Equipe do escritório — Filter Lite (busca ± select de papel; sem chips).
 * Chrome: ShellSectionHeader + ShellFilterToolbarLite.
 * Arquétipo: .local/reference/nuxt-dashboard-template/app/pages/settings/members.vue
 */
import type { ActivationMethod, CredentialDeliveryPayload, OfficeMember, OfficeRole } from '~/types/api'
import { canManageOfficeTeam } from '~/utils/permissions'
import { apiErrorMessage } from '~/utils/api-error'
import { filterTeamMembers, type TeamRoleFilter } from '~/utils/team-filter'

const api = useApi()
const toast = useToast()
const { me, sessionEpoch } = useDashboard()

const canMutate = computed(() => canManageOfficeTeam(me.value))

const items = ref<OfficeMember[]>([])
const occupied = ref(0)
const maxUsers = ref<number | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const forbidden = ref(false)
const q = ref('')
const roleFilter = ref<TeamRoleFilter>('ALL')
const actingId = ref<number | null>(null)

const secret = ref<CredentialDeliveryPayload | null>(null)

const roleFilterItems: Array<{ label: string, value: TeamRoleFilter }> = [
  { label: 'Todos', value: 'ALL' },
  { label: 'Admin', value: 'ADMIN' },
  { label: 'Operador', value: 'OPERATOR' },
  { label: 'Visualizador', value: 'VIEWER' }
]

const filtered = computed(() =>
  filterTeamMembers(items.value, q.value, roleFilter.value)
)

const seatsLabel = computed(() => {
  const max = maxUsers.value
  if (max == null) return `${occupied.value} em uso`
  return `${occupied.value} / ${max} vagas`
})

const emptyFilterDescription = computed(() => {
  const term = q.value.trim()
  const roleLabel = roleFilterItems.find(r => r.value === roleFilter.value)?.label
  const parts: string[] = []
  if (term) parts.push(`“${term}”`)
  if (roleFilter.value !== 'ALL' && roleLabel) parts.push(`papel ${roleLabel}`)
  if (!parts.length) return 'Nenhum membro corresponde aos filtros.'
  return `Nada encontrado para ${parts.join(' e ')}.`
})

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  forbidden.value = false
  try {
    const res = await api.office.members.list()
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    items.value = res.data || []
    occupied.value = res.meta?.occupied_seats ?? items.value.length
    maxUsers.value = res.meta?.max_users ?? null
  } catch (e) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    items.value = []
    const status = (e as { statusCode?: number, status?: number })?.statusCode
      ?? (e as { status?: number })?.status
      ?? (e as { response?: { status?: number } })?.response?.status
    if (status === 403) {
      forbidden.value = true
      loadError.value = null
    } else {
      loadError.value = apiErrorMessage(e, 'Falha ao listar a equipe.')
      toast.add({ title: loadError.value, color: 'error' })
    }
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

function showSecret(payload: CredentialDeliveryPayload) {
  if (payload.credential_delivery === 'delivered'
    && (payload.activation_url || payload.temporary_password)) {
    secret.value = payload
  } else {
    secret.value = null
  }
}

function clearSecret() {
  secret.value = null
}

async function withPassword(action: () => Promise<void>) {
  // Password is confirmed inside modal flows for create; for menu actions
  // we ask once via prompt modal pattern — simple confirmPassword field modal.
  const password = await promptPassword()
  if (!password) return
  try {
    await api.confirmPassword(password)
    await action()
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Ação não concluída.'), color: 'error' })
  }
}

const passwordOpen = ref(false)
const passwordValue = ref('')
let passwordResolve: ((v: string | null) => void) | null = null

function promptPassword(): Promise<string | null> {
  passwordValue.value = ''
  passwordOpen.value = true
  return new Promise((resolve) => {
    passwordResolve = resolve
  })
}

function submitPassword() {
  const v = passwordValue.value
  passwordOpen.value = false
  passwordValue.value = ''
  passwordResolve?.(v || null)
  passwordResolve = null
}

function cancelPassword() {
  passwordOpen.value = false
  passwordValue.value = ''
  passwordResolve?.(null)
  passwordResolve = null
}

async function onCreated(payload: CredentialDeliveryPayload) {
  showSecret(payload)
  await load()
}

async function changeRole(member: OfficeMember, role: OfficeRole) {
  actingId.value = member.id
  try {
    await withPassword(async () => {
      await api.office.members.updateRole(member.id, { role })
      toast.add({ title: 'Papel atualizado.', color: 'success' })
      await load()
    })
  } finally {
    actingId.value = null
  }
}

async function deactivate(member: OfficeMember) {
  actingId.value = member.id
  try {
    await withPassword(async () => {
      await api.office.members.deactivate(member.id)
      toast.add({ title: 'Membro desativado.', color: 'success' })
      await load()
    })
  } finally {
    actingId.value = null
  }
}

async function reactivate(member: OfficeMember) {
  actingId.value = member.id
  try {
    await withPassword(async () => {
      const res = await api.office.members.reactivate(member.id, {
        method: 'MANUAL_LINK' as ActivationMethod
      })
      showSecret(res.data)
      toast.add({ title: 'Membro reativado.', color: 'success' })
      await load()
    })
  } finally {
    actingId.value = null
  }
}

async function regenerate(member: OfficeMember) {
  actingId.value = member.id
  try {
    await withPassword(async () => {
      const res = await api.office.members.regenerateActivation(member.id, {
        method: (member.activation?.method as ActivationMethod) || 'MANUAL_LINK'
      })
      showSecret(res.data)
      toast.add({ title: 'Acesso regenerado.', color: 'success' })
      await load()
    })
  } finally {
    actingId.value = null
  }
}

watch(sessionEpoch, () => {
  items.value = []
  clearSecret()
  void load()
})
onMounted(load)
onBeforeUnmount(clearSecret)
</script>

<template>
  <div
    class="min-w-0 overflow-x-hidden"
    data-testid="settings-team"
  >
    <ShellSectionHeader
      title="Equipe"
      :description="canMutate ? `Membros do escritório · ${seatsLabel}` : 'Gestão de equipe'"
      test-id="settings-team-header"
    >
      <SettingsTeamAddModal
        v-if="canMutate && !forbidden"
        @created="onCreated"
      />
    </ShellSectionHeader>

    <ActivationOneTimeSecret
      v-if="secret"
      class="mb-4"
      :activation-url="secret.activation_url"
      :temporary-password="secret.temporary_password"
      :expires-at="secret.expires_at"
      :method="secret.method"
    />

    <UEmpty
      v-if="forbidden"
      icon="i-lucide-shield-off"
      title="Sem membership neste escritório"
      description="Somente ADMIN do escritório."
      class="py-10"
      data-testid="team-forbidden"
    />

    <template v-else>
      <ShellLoadError
        v-if="loadError"
        :title="loadError"
        test-id="team-load-error"
        @retry="load"
      />

      <!-- Lista settings: UPageCard subtle com header p-0 (exceção documentada vs SectionCard). -->
      <UPageCard
        variant="subtle"
        :ui="{
          container: 'p-0 sm:p-0 gap-y-0',
          wrapper: 'items-stretch',
          header: 'p-4 mb-0 border-b border-default'
        }"
      >
        <template #header>
          <ShellFilterToolbarLite
            :q="q"
            search-placeholder="Buscar por nome ou e-mail"
            search-aria-label="Buscar por nome ou e-mail"
            :loading="loading"
            test-id-prefix="team"
            @update:q="(value) => { q = value }"
            @refresh="load"
          >
            <template #actions>
              <USelect
                v-model="roleFilter"
                :items="roleFilterItems"
                value-key="value"
                label-key="label"
                color="neutral"
                class="w-full min-w-0 sm:w-40"
                aria-label="Filtrar por papel"
                data-testid="team-role-filter"
              />
              <UBadge
                v-if="maxUsers != null"
                variant="subtle"
                color="neutral"
                :label="seatsLabel"
                data-testid="team-seats"
              />
            </template>
          </ShellFilterToolbarLite>
        </template>

        <div
          v-if="loading && !items.length"
          class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 sm:gap-4 sm:p-6 xl:grid-cols-3"
          data-testid="team-loading"
        >
          <div
            v-for="i in 4"
            :key="i"
            class="flex flex-col gap-3 rounded-lg border border-default p-4"
          >
            <div class="flex items-center gap-3">
              <USkeleton class="size-10 shrink-0 rounded-full" />
              <div class="min-w-0 flex-1 space-y-2">
                <USkeleton class="h-4 w-32" />
                <USkeleton class="h-3 w-40" />
              </div>
            </div>
            <div class="flex gap-2">
              <USkeleton class="h-6 w-16 rounded-full" />
              <USkeleton class="h-6 w-24 rounded-full" />
            </div>
          </div>
        </div>

        <UEmpty
          v-else-if="!items.length"
          icon="i-lucide-users"
          title="Nenhum membro"
          description="Inclua o primeiro membro da equipe."
          class="py-10"
          data-testid="team-empty"
        />

        <UEmpty
          v-else-if="!filtered.length"
          icon="i-lucide-search-x"
          title="Nenhum resultado"
          :description="emptyFilterDescription"
          class="py-10"
          data-testid="team-search-empty"
        />

        <SettingsTeamList
          v-else
          :members="filtered"
          :acting-id="actingId"
          :can-mutate="canMutate"
          @change-role="changeRole"
          @deactivate="deactivate"
          @reactivate="reactivate"
          @regenerate="regenerate"
        />
      </UPageCard>
    </template>

    <ShellFormModal
      v-model:open="passwordOpen"
      title="Confirmar senha"
      description="Ação sensível da equipe."
      submit-label="Confirmar"
      @cancel="cancelPassword"
      @submit="submitPassword"
    >
      <template #body>
        <UFormField
          label="Sua senha"
          required
        >
          <UInput
            v-model="passwordValue"
            type="password"
            autocomplete="current-password"
            class="w-full"
            data-testid="team-action-reconfirm"
            @keyup.enter="submitPassword"
          />
        </UFormField>
      </template>
    </ShellFormModal>
  </div>
</template>
