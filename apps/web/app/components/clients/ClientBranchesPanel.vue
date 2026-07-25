<script setup lang="ts">
/**
 * Aba Estabelecimentos: lista o CNPJ deste cliente + filiais vinculadas
 * (cada filial é um cliente com cadastro próprio; a matriz só linka).
 */
import type { Client, Establishment, LinkedClientSummary } from '~/types/api'
import {
  registrationStatusColor,
  registrationStatusIcon,
  registrationStatusLabel
} from '~/utils/registration-labels'

const props = withDefaults(defineProps<{
  client: Client
  establishments: Establishment[]
  canManageClients: boolean
  canManageCredentials?: boolean
  /** false quando a page já fornece ShellSectionHeader. */
  showHeader?: boolean
}>(), {
  canManageCredentials: false,
  showHeader: true
})

const emit = defineEmits<{
  updated: []
  branchCreated: [id: number]
}>()

const formOpen = ref(false)

const primary = computed(() => props.establishments[0] || null)

const branches = computed<LinkedClientSummary[]>(() => props.client.branches || [])

/** Este cliente é matriz (não é filial de outra)? */
const isMatrixProfile = computed(() => !props.client.matrix_client_id)

const matrixLabel = computed(() =>
  props.client.display_name || props.client.legal_name || props.client.name
)

function openCreateBranch() {
  formOpen.value = true
}

function onBranchSaved(payload: { id: number }) {
  formOpen.value = false
  emit('branchCreated', payload.id)
  emit('updated')
}

function openClient(id: number) {
  navigateTo(clientSectionPath(id))
}
</script>

<template>
  <div class="space-y-4" data-testid="client-branches-panel">
    <ShellSectionHeader
      v-if="showHeader"
      title="Estabelecimentos"
      description="Cada CNPJ tem cadastro próprio. A matriz lista e vincula as filiais."
    >
      <UButton
        v-if="canManageClients && isMatrixProfile"
        icon="i-lucide-plus"
        label="Cadastrar filial"
        color="primary"
        variant="soft"
        class="w-fit lg:ms-auto"
        data-testid="client-branch-create"
        @click="openCreateBranch"
      />
    </ShellSectionHeader>
    <div
      v-else-if="canManageClients && isMatrixProfile"
      class="mb-2 flex justify-end"
    >
      <UButton
        icon="i-lucide-plus"
        label="Cadastrar filial"
        color="primary"
        variant="soft"
        class="w-fit"
        data-testid="client-branch-create"
        @click="openCreateBranch"
      />
    </div>

    <UAlert
      v-if="client.matrix"
      color="neutral"
      variant="subtle"
      icon="i-lucide-link"
      title="Este cliente é filial"
    >
      <template #actions>
        <UButton
          size="sm"
          color="neutral"
          variant="soft"
          label="Abrir matriz"
          @click="openClient(client.matrix!.id)"
        />
      </template>
    </UAlert>

    <!-- Este CNPJ (cadastro atual) -->
    <UPageCard variant="subtle">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-highlighted">
              {{ primary?.trade_name || client.trade_name || client.legal_name || client.name }}
            </span>
            <UBadge
              v-if="isMatrixProfile"
              color="primary"
              variant="subtle"
              size="sm"
            >
              Matriz (este cadastro)
            </UBadge>
            <UBadge
              v-else
              color="neutral"
              variant="subtle"
              size="sm"
            >
              Filial (este cadastro)
            </UBadge>
            <UBadge
              :color="client.is_active ? 'success' : 'neutral'"
              variant="subtle"
              size="sm"
            >
              {{ client.is_active ? 'Ativo' : 'Inativo' }}
            </UBadge>
            <UBadge
              v-if="primary"
              :color="registrationStatusColor(primary.registration_status)"
              variant="subtle"
              size="sm"
              :icon="registrationStatusIcon(primary.registration_status)"
            >
              {{ registrationStatusLabel(primary.registration_status) }}
            </UBadge>
          </div>
          <p class="mt-1 font-mono text-sm text-muted">
            {{ client.cnpj || primary?.cnpj || client.root_cnpj }}
          </p>
        </div>
        <UBadge color="neutral" variant="outline" size="sm">
          Cadastro atual
        </UBadge>
      </div>
    </UPageCard>

    <!-- Filiais vinculadas (só na matriz) -->
    <template v-if="isMatrixProfile">
      <UPageCard
        title="Filiais vinculadas"
        :description="branches.length
          ? `${branches.length} filial(is) com cadastro próprio, listadas aqui por vínculo.`
          : 'Nenhuma filial vinculada. Cadastre com “Cadastrar filial” e informe esta matriz.'"
        variant="naked"
        class="mb-2 mt-2"
      />

      <UPageCard variant="subtle">
        <div v-if="branches.length" class="divide-y divide-default">
          <div
            v-for="branch in branches"
            :key="branch.id"
            class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="text-left font-medium text-highlighted hover:text-primary hover:underline"
                  @click="openClient(branch.id)"
                >
                  {{ branch.display_name || branch.legal_name || branch.name }}
                </button>
                <UBadge color="neutral" variant="subtle" size="sm">
                  Filial
                </UBadge>
                <UBadge
                  :color="branch.is_active ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                >
                  {{ branch.is_active ? 'Ativo' : 'Inativo' }}
                </UBadge>
                <UBadge
                  v-if="branch.credential_summary"
                  color="success"
                  variant="subtle"
                  size="sm"
                  icon="i-lucide-badge-check"
                >
                  Com certificado
                </UBadge>
                <UBadge
                  v-else
                  color="warning"
                  variant="subtle"
                  size="sm"
                  icon="i-lucide-shield-off"
                >
                  Sem certificado
                </UBadge>
              </div>
              <p class="font-mono text-sm text-muted">
                {{ branch.cnpj || branch.root_cnpj }}
              </p>
              <p v-if="branch.trade_name" class="text-xs text-muted">
                {{ branch.trade_name }}
              </p>
            </div>
            <div class="flex shrink-0 gap-2">
              <UButton
                size="sm"
                color="primary"
                variant="soft"
                icon="i-lucide-external-link"
                label="Abrir cadastro"
                @click="openClient(branch.id)"
              />
            </div>
          </div>
        </div>
        <UEmpty
          v-else
          icon="i-lucide-git-branch"
          title="Nenhuma filial vinculada"
          description="Cadastre uma filial vinculada a esta matriz."
        >
          <UButton
            v-if="canManageClients"
            label="Cadastrar filial"
            icon="i-lucide-plus"
            color="primary"
            variant="soft"
            @click="openCreateBranch"
          />
        </UEmpty>
      </UPageCard>
    </template>

    <UAlert
      v-else
      color="neutral"
      variant="subtle"
      icon="i-lucide-info"
      title="Filiais não se aninham"
    />

    <ClientsClientFormModal
      v-if="canManageClients && isMatrixProfile"
      v-model:open="formOpen"
      :client="null"
      :can-manage-clients="canManageClients"
      :can-manage-credentials="canManageCredentials"
      :matrix-client-id="client.id"
      :matrix-label="matrixLabel"
      @saved="onBranchSaved"
      @open-existing="openClient"
    />
  </div>
</template>
