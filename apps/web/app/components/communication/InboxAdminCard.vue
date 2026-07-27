<script setup lang="ts">
import QRCode from 'qrcode'
import type { TenantMember } from '~/types/api'
import type {
  CommunicationInbox,
  CommunicationPairingState,
  CommunicationSessionStatus
} from '~/types/communication'
import type { WorkDepartment } from '~/types/work'
import { COMMUNICATION_INBOX_STATUS, formatCommunicationDate } from '~/utils/communication'
import {
  communicationPairingDeadline,
  communicationPairingEvent,
  isCommunicationPairingActive,
  isCommunicationPairingTerminal
} from '~/utils/communication-pairing'
import { communicationSessionActions } from '~/utils/communication-session'

const props = defineProps<{
  inbox: CommunicationInbox
  members: TenantMember[]
  departments: WorkDepartment[]
}>()

const workspace = useCommunicationWorkspace()
const name = ref(props.inbox.name)
const enabled = ref(props.inbox.is_enabled)
const isDefault = ref(props.inbox.is_default)
const departmentId = ref<number>(props.inbox.work_department_id || 0)
const memberIds = ref<number[]>([...(props.inbox.member_ids ?? [])])
const saving = ref(false)
const actionLoading = ref<'connect' | 'disconnect' | 'logout' | 'delete' | null>(null)
const session = ref<CommunicationSessionStatus | null>(null)
const sessionStatusUnavailable = ref(false)
const pairing = ref<CommunicationPairingState | null>(null)
const qrDataUrl = ref<string | null>(null)
const pairingDeadlineAt = ref<number | null>(null)
const logoutOpen = ref(false)
const deleteOpen = ref(false)
let pollingTimer: ReturnType<typeof setTimeout> | null = null

interface SessionAlert {
  title: string
  description: string
  color: 'info' | 'warning' | 'error' | 'neutral'
  icon: string
}

const pairingEvent = computed(() => communicationPairingEvent(pairing.value))
const pairingActive = computed(() => pairingDeadlineAt.value !== null && isCommunicationPairingActive(
  pairing.value,
  pairingDeadlineAt.value
))
const effectiveStatus = computed(() => pairingActive.value
  ? 'CONNECTING'
  : session.value?.status ?? props.inbox.status)
const statusMeta = computed(() => COMMUNICATION_INBOX_STATUS[effectiveStatus.value])
const hasCredentials = computed(() => session.value?.has_credentials ?? false)
const actions = computed(() => communicationSessionActions(effectiveStatus.value, hasCredentials.value))
const pairingCode = computed(() => pairing.value?.code || pairing.value?.qr_code || null)
const gatewayAvailable = computed(() => Boolean(
  workspace.featureMeta.value.global_enabled && workspace.featureMeta.value.gateway_enabled
))
const connectAllowed = computed(() => Boolean(
  gatewayAvailable.value
  && workspace.featureMeta.value.tenant_enabled
  && enabled.value
  && name.value.trim()
))
const connectBlockedReason = computed(() => {
  if (!workspace.featureMeta.value.global_enabled) {
    return 'Comunicação global desativada.'
  }
  if (!workspace.featureMeta.value.gateway_enabled) {
    return 'Gateway do WhatsApp desativado.'
  }
  if (!workspace.featureMeta.value.tenant_enabled) {
    return 'Comunicação do escritório desativada.'
  }
  if (!enabled.value) {
    return 'Habilite o canal para conectar.'
  }
  if (!name.value.trim()) {
    return 'Informe o nome da sessão antes de conectar.'
  }
  return ''
})

const sessionAlert = computed<SessionAlert | null>(() => {
  if (!workspace.featureMeta.value.global_enabled) {
    return {
      title: 'Ambiente com comunicação desativada',
      description: 'O switch global está fechado. Nenhum comando de sessão será enviado.',
      color: 'warning',
      icon: 'i-lucide-shield-alert'
    }
  }
  if (!workspace.featureMeta.value.gateway_enabled) {
    return {
      title: 'Gateway do WhatsApp desativado',
      description: 'Ative o gateway no ambiente antes de controlar esta sessão.',
      color: 'warning',
      icon: 'i-lucide-server-off'
    }
  }
  if (!workspace.featureMeta.value.tenant_enabled) {
    return {
      title: 'Comunicação do escritório desativada',
      description: 'As sessões ficam desconectadas até a reativação. Reativar não conecta automaticamente.',
      color: 'warning',
      icon: 'i-lucide-building-2'
    }
  }
  if (!enabled.value) {
    return {
      title: 'Canal desativado',
      description: 'Habilite o canal para usar “Conectar”.',
      color: 'neutral',
      icon: 'i-lucide-circle-off'
    }
  }
  if (sessionStatusUnavailable.value) {
    return {
      title: 'Status do gateway indisponível',
      description: 'Não foi possível confirmar conexão ou credenciais agora. Tente novamente em instantes.',
      color: 'warning',
      icon: 'i-lucide-cloud-off'
    }
  }
  if (pairingEvent.value === 'timeout') {
    return {
      title: 'Tempo de conexão esgotado',
      description: 'A sessão voltou para desconectada. Use “Conectar” para iniciar outra tentativa.',
      color: 'warning',
      icon: 'i-lucide-clock-alert'
    }
  }
  if (pairingEvent.value === 'error' || pairingEvent.value.endsWith('failed')) {
    return {
      title: 'Não foi possível conectar',
      description: `A tentativa terminou com ${pairing.value?.error_code || 'erro de conexão'}. Tente novamente.`,
      color: 'error',
      icon: 'i-lucide-circle-alert'
    }
  }
  if (effectiveStatus.value === 'CONNECTING') {
    return {
      title: pairingCode.value ? 'QR Code pronto para leitura' : 'Conectando a sessão',
      description: pairingCode.value
        ? 'Leia o QR Code no WhatsApp. A sessão mudará para conectada após a confirmação.'
        : 'Credenciais existentes serão reutilizadas; se não houver credenciais, o QR Code aparecerá aqui.',
      color: 'info',
      icon: pairingCode.value ? 'i-lucide-qr-code' : 'i-lucide-loader-circle'
    }
  }
  if (effectiveStatus.value === 'DISCONNECTED' && hasCredentials.value) {
    return {
      title: 'Sessão desconectada com credenciais preservadas',
      description: 'Use “Conectar” para reconectar sem novo QR, ou “Remover credenciais” para exigir uma nova autenticação.',
      color: 'neutral',
      icon: 'i-lucide-unplug'
    }
  }
  if (effectiveStatus.value === 'DISCONNECTED') {
    return {
      title: 'Sessão sem credenciais',
      description: 'Use “Conectar” para gerar um novo QR Code. O histórico desta inbox será preservado.',
      color: 'neutral',
      icon: 'i-lucide-key-round'
    }
  }
  return null
})

const departmentItems = computed(() => [
  { label: 'Sem departamento', value: 0 },
  ...props.departments.map(department => ({ label: department.name, value: department.id }))
])

watch(() => props.inbox, (inbox) => {
  name.value = inbox.name
  enabled.value = inbox.is_enabled
  isDefault.value = inbox.is_default
  departmentId.value = inbox.work_department_id || 0
  memberIds.value = [...(inbox.member_ids ?? [])]
}, { deep: true })

watch(pairingCode, async (code) => {
  qrDataUrl.value = null
  if (!code || !['code', 'qr', 'qr_available'].includes(pairingEvent.value)) return
  try {
    qrDataUrl.value = await QRCode.toDataURL(String(code), {
      errorCorrectionLevel: 'M',
      margin: 2,
      width: 280
    })
  } catch {
    qrDataUrl.value = null
  }
})

function toggleMember(id: number, selected: boolean | 'indeterminate') {
  const next = new Set(memberIds.value)
  if (selected === true) next.add(id)
  else next.delete(id)
  memberIds.value = [...next]
}

async function persistSettings(): Promise<boolean> {
  saving.value = true
  const settingsSaved = await workspace.updateInbox(props.inbox, {
    name: name.value.trim(),
    is_enabled: enabled.value,
    is_default: isDefault.value,
    work_department_id: departmentId.value || null
  })
  if (!settingsSaved) {
    saving.value = false
    return false
  }
  const membersSaved = await workspace.replaceInboxMembers(props.inbox.id, memberIds.value)
  if (!enabled.value) stopSessionPolling()
  saving.value = false
  return membersSaved
}

async function save() {
  await persistSettings()
}

function applySessionStatus(current: CommunicationSessionStatus): void {
  session.value = current
  if (current.pairing) {
    pairing.value = current.pairing
    pairingDeadlineAt.value = communicationPairingDeadline(current.pairing, Date.now() + 120_000)
  }
}

async function refreshSessionStatus(): Promise<CommunicationSessionStatus | null> {
  const current = await workspace.getSessionStatus(props.inbox.id)
  sessionStatusUnavailable.value = current === null
  if (current) applySessionStatus(current)
  return current
}

async function pollSession() {
  stopSessionPolling()
  const current = await refreshSessionStatus()
  if (current === null) {
    pairing.value = null
    pairingDeadlineAt.value = null
    await workspace.loadInboxes()
    return
  }
  if (current?.status === 'CONNECTED') {
    pairing.value = null
    pairingDeadlineAt.value = null
    await workspace.loadInboxes()
    return
  }
  if (pairing.value && isCommunicationPairingTerminal(pairing.value)) {
    await workspace.loadInboxes()
    return
  }
  const deadline = pairingDeadlineAt.value
  if (deadline !== null && deadline <= Date.now()) {
    pairing.value = {
      event: 'timeout',
      error_code: 'CONNECT_TIMEOUT',
      expires_at: new Date(deadline).toISOString()
    }
    await workspace.loadInboxes()
    return
  }
  if (effectiveStatus.value === 'CONNECTING') {
    pollingTimer = setTimeout(() => void pollSession(), 2500)
  }
}

async function connect() {
  stopSessionPolling()
  actionLoading.value = 'connect'
  if (enabled.value && !props.inbox.is_enabled) {
    const persisted = await persistSettings()
    if (!persisted) {
      actionLoading.value = null
      return
    }
  }
  const state = await workspace.connectInbox(props.inbox.id)
  actionLoading.value = null
  if (!state) return
  pairing.value = state
  pairingDeadlineAt.value = communicationPairingDeadline(state, Date.now() + 120_000)
  pollingTimer = setTimeout(() => void pollSession(), 1200)
}

async function disconnect() {
  actionLoading.value = 'disconnect'
  const disconnected = await workspace.disconnectInbox(props.inbox.id)
  actionLoading.value = null
  if (!disconnected) return
  stopSessionPolling()
  pairing.value = null
  pairingDeadlineAt.value = null
  if (session.value) {
    session.value = {
      ...session.value,
      status: 'DISCONNECTED',
      desired_connected: false,
      connected: false,
      ready: false
    }
  }
}

async function logout() {
  actionLoading.value = 'logout'
  const loggedOut = await workspace.logoutInbox(props.inbox.id)
  actionLoading.value = null
  if (!loggedOut) return
  logoutOpen.value = false
  stopSessionPolling()
  pairing.value = null
  pairingDeadlineAt.value = null
  if (session.value) {
    session.value = {
      ...session.value,
      status: 'DISCONNECTED',
      desired_connected: false,
      connected: false,
      logged_in: false,
      ready: false,
      has_credentials: false
    }
  }
}

function openLogout(): void {
  logoutOpen.value = true
}

async function deleteSession() {
  stopSessionPolling()
  actionLoading.value = 'delete'
  const deleted = await workspace.deleteInbox(props.inbox.id)
  actionLoading.value = null
  if (!deleted) {
    if (effectiveStatus.value === 'CONNECTING') {
      pollingTimer = setTimeout(() => void pollSession(), 2500)
    }
    return
  }
  deleteOpen.value = false
}

function openDelete(): void {
  deleteOpen.value = true
}

function stopSessionPolling() {
  if (pollingTimer) clearTimeout(pollingTimer)
  pollingTimer = null
}

onMounted(async () => {
  const current = await refreshSessionStatus()
  if (current?.status === 'CONNECTING') {
    pairingDeadlineAt.value = communicationPairingDeadline(current.pairing, Date.now() + 120_000)
    pollingTimer = setTimeout(() => void pollSession(), 1200)
  }
})

onBeforeUnmount(stopSessionPolling)
</script>

<template>
  <UCard
    :data-testid="`communication-inbox-admin-${inbox.id}`"
    variant="subtle"
  >
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="min-w-0">
          <p class="truncate font-semibold text-highlighted">
            {{ inbox.name }}
          </p>
          <p class="text-xs text-muted">
            {{ inbox.address_masked || 'Número ainda não conectado' }}
          </p>
        </div>
        <UBadge
          :label="statusMeta.label"
          :icon="statusMeta.icon"
          :color="statusMeta.color"
          variant="subtle"
        />
      </div>
    </template>

    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField label="Nome da sessão">
        <UInput
          v-model="name"
          class="w-full"
          maxlength="120"
        />
      </UFormField>
      <UFormField label="Fila padrão">
        <USelectMenu
          v-model="departmentId"
          :items="departmentItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <USwitch
        v-model="enabled"
        label="Canal habilitado"
        description="Autoriza conexão e transporte; não conecta automaticamente."
      />
      <USwitch
        v-model="isDefault"
        label="Inbox geral"
        description="Saída padrão das automações fiscais."
      />
    </div>

    <USeparator class="my-4" />

    <div>
      <p class="mb-2 text-sm font-medium text-highlighted">
        Membros autorizados
      </p>
      <div class="grid max-h-40 gap-2 overflow-y-auto rounded-md border border-default p-3 sm:grid-cols-2">
        <UCheckbox
          v-for="member in members"
          :key="member.id"
          :model-value="memberIds.includes(member.id)"
          :label="member.name || member.email || `Membro #${member.id}`"
          @update:model-value="toggleMember(member.id, $event)"
        />
        <p
          v-if="!members.length"
          class="text-xs text-muted"
        >
          Nenhum membro ativo encontrado.
        </p>
      </div>
    </div>

    <UAlert
      v-if="sessionAlert"
      class="mt-4"
      :title="sessionAlert.title"
      :description="sessionAlert.description"
      :color="sessionAlert.color"
      :icon="sessionAlert.icon"
      variant="subtle"
    />

    <div
      v-if="effectiveStatus === 'CONNECTING' && pairingCode"
      class="mt-4 rounded-lg border border-default bg-default p-4 text-center"
    >
      <img
        v-if="qrDataUrl"
        :src="qrDataUrl"
        alt="QR Code efêmero para conectar o WhatsApp"
        class="mx-auto size-64 max-w-full rounded-md bg-white p-2"
      >
      <div
        v-else
        class="mx-auto max-w-sm rounded-md bg-elevated p-3 font-mono text-lg tracking-widest text-highlighted"
      >
        {{ pairingCode }}
      </div>
      <p class="mt-3 text-sm text-muted">
        Abra WhatsApp → Aparelhos conectados → Conectar aparelho.
      </p>
      <p
        v-if="pairing?.expires_at"
        class="mt-1 text-xs text-muted"
      >
        Expira em {{ formatCommunicationDate(pairing.expires_at) }}.
      </p>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          v-if="actions.includes('connect')"
          label="Conectar"
          icon="i-lucide-plug-zap"
          color="neutral"
          variant="outline"
          :loading="actionLoading === 'connect'"
          :disabled="!connectAllowed || actionLoading !== null || saving"
          :title="connectBlockedReason || undefined"
          :aria-label="connectBlockedReason ? `Conectar. ${connectBlockedReason}` : 'Conectar'"
          @click="connect"
        />
        <UButton
          v-if="actions.includes('disconnect')"
          label="Desconectar"
          icon="i-lucide-unplug"
          color="neutral"
          variant="outline"
          :loading="actionLoading === 'disconnect'"
          :disabled="!gatewayAvailable || actionLoading !== null"
          @click="disconnect"
        />
        <UButton
          v-if="actions.includes('logout')"
          label="Remover credenciais"
          icon="i-lucide-key-round"
          color="error"
          variant="soft"
          :disabled="!gatewayAvailable || actionLoading !== null"
          @click="openLogout"
        />
        <UButton
          label="Excluir sessão"
          icon="i-lucide-trash-2"
          color="error"
          variant="outline"
          :disabled="!gatewayAvailable || actionLoading !== null"
          @click="openDelete"
        />
        <UButton
          label="Salvar sessão"
          icon="i-lucide-save"
          :loading="saving"
          :disabled="!name.trim()"
          @click="save"
        />
      </div>
    </template>
  </UCard>

  <ShellConfirmModal
    v-model:open="logoutOpen"
    title="Remover credenciais desta sessão WhatsApp?"
    description="A sessão será desconectada e um novo QR Code será necessário para autenticar novamente."
    tone="danger"
    confirm-label="Remover credenciais"
    confirm-icon="i-lucide-key-round"
    :loading="actionLoading === 'logout'"
    :test-id="`communication-inbox-logout-${inbox.id}`"
    @confirm="logout"
  >
    <template #body>
      <UAlert
        title="O histórico será preservado"
        description="A inbox, as conversas e a auditoria permanecem no sistema. Somente as credenciais de autenticação serão removidas."
        color="warning"
        icon="i-lucide-history"
        variant="subtle"
      />
    </template>
  </ShellConfirmModal>

  <ShellConfirmModal
    v-model:open="deleteOpen"
    title="Excluir esta sessão WhatsApp?"
    description="A sessão será removida por completo, incluindo conversas e auditoria vinculadas a ela."
    tone="danger"
    confirm-label="Excluir sessão"
    confirm-icon="i-lucide-trash-2"
    :loading="actionLoading === 'delete'"
    :test-id="`communication-inbox-delete-${inbox.id}`"
    @confirm="deleteSession"
  >
    <template #body>
      <UAlert
        title="Esta ação não pode ser desfeita"
        description="Credenciais, histórico desta sessão e vínculos locais serão apagados. As demais sessões do escritório não são afetadas."
        color="warning"
        icon="i-lucide-trash-2"
        variant="subtle"
      />
    </template>
  </ShellConfirmModal>
</template>
