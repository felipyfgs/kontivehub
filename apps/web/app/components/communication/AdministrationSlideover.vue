<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })
const workspace = useCommunicationWorkspace()
const activeTab = ref('channels')
const newInboxName = ref('')
const creatingInbox = ref(false)
const officeSwitchLoading = ref(false)

const tabs = [
  { label: 'Canais', value: 'channels', icon: 'i-lucide-message-square-more' },
  { label: 'Automações', value: 'automation', icon: 'i-lucide-bot' },
  { label: 'Destinatários', value: 'recipients', icon: 'i-lucide-contact-round' },
  { label: 'Catálogos', value: 'catalog', icon: 'i-lucide-tags' }
]

watch(open, (visible) => {
  if (visible) void workspace.loadAdministration()
})

async function createInbox() {
  if (!newInboxName.value.trim()) return
  creatingInbox.value = true
  const created = await workspace.createInbox({
    name: newInboxName.value.trim(),
    is_enabled: false,
    is_default: workspace.inboxes.value.length === 0
  })
  if (created) newInboxName.value = ''
  creatingInbox.value = false
}

async function toggleOffice(value: boolean) {
  officeSwitchLoading.value = true
  await workspace.updateOfficeEnabled(value)
  officeSwitchLoading.value = false
}

function policyFor(scope: string) {
  const [moduleKey, submoduleKey] = scope.split(':')
  return workspace.policies.value.find(policy =>
    policy.module_key === moduleKey && policy.submodule_key === submoduleKey)
}
</script>

<template>
  <USlideover
    v-model:open="open"
    title="Administração da comunicação"
    description="Canais WhatsApp, equipe, automações e destinatários."
    data-testid="communication-administration-slideover"
    :ui="{ content: 'max-w-5xl' }"
  >
    <template #body>
      <div class="space-y-5">
        <UTabs
          v-model="activeTab"
          :items="tabs"
          :content="false"
          class="w-full"
        />

        <div
          v-if="workspace.adminLoading.value"
          class="space-y-3"
        >
          <USkeleton class="h-24 w-full" />
          <USkeleton class="h-64 w-full" />
        </div>

        <template v-else-if="activeTab === 'channels'">
          <UAlert
            v-if="!workspace.featureMeta.value.global_enabled || !workspace.featureMeta.value.gateway_enabled"
            title="Comunicação global ou gateway está desligado"
            description="Os defaults permanecem fail-closed. Canais podem ser preparados, mas nenhum pareamento ou envio será executado até a ativação operacional."
            color="warning"
            icon="i-lucide-shield-alert"
            variant="subtle"
          />

          <UCard variant="subtle">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p class="font-semibold text-highlighted">
                  Switch do escritório
                </p>
                <p class="text-sm text-muted">
                  Ao desligar, sessões ativas são desconectadas sem remover credenciais.
                </p>
              </div>
              <USwitch
                :model-value="workspace.featureMeta.value.office_enabled"
                label="Comunicação habilitada"
                :loading="officeSwitchLoading"
                @update:model-value="toggleOffice"
              />
            </div>
          </UCard>

          <UCard variant="subtle">
            <template #header>
              <p class="font-semibold text-highlighted">
                Nova sessão WhatsApp
              </p>
            </template>
            <div class="flex flex-col gap-2 sm:flex-row">
              <UInput
                v-model="newInboxName"
                placeholder="Ex.: Atendimento geral"
                class="flex-1"
                maxlength="120"
                @keydown.enter.prevent="createInbox"
              />
              <UButton
                label="Criar desativada"
                icon="i-lucide-plus"
                :loading="creatingInbox"
                :disabled="!newInboxName.trim()"
                @click="createInbox"
              />
            </div>
          </UCard>

          <div class="space-y-4">
            <CommunicationInboxAdminCard
              v-for="inbox in workspace.inboxes.value"
              :key="inbox.id"
              :inbox="inbox"
              :members="workspace.officeMembers.value"
              :departments="workspace.departments.value"
            />
            <UAlert
              v-if="!workspace.inboxes.value.length"
              title="Nenhuma sessão cadastrada"
              description="Crie a primeira sessão, defina membros, habilite o canal e use “Conectar”."
              color="neutral"
              variant="subtle"
            />
          </div>
        </template>

        <template v-else-if="activeTab === 'automation'">
          <UAlert
            title="Consulta e envio possuem horários independentes"
            description="No cutoff, o documento canônico da mesma competência é obrigatório. Sem ele, o dispatch termina em SKIPPED_NO_DOCUMENT e não reabre automaticamente."
            color="info"
            icon="i-lucide-calendar-clock"
            variant="subtle"
          />
          <div class="space-y-4">
            <CommunicationAutomationPolicyCard
              v-for="scope in workspace.automationMeta.value.supported_scopes"
              :key="scope"
              :scope="scope"
              :policy="policyFor(scope)"
              :inboxes="workspace.inboxes.value"
            />
          </div>
        </template>

        <CommunicationRecipientConfigurationPanel
          v-else-if="activeTab === 'recipients'"
          :clients="workspace.selectedConversation.value?.clients || []"
          :scopes="workspace.automationMeta.value.supported_scopes"
        />

        <CommunicationCatalogAdminPanel v-else />
      </div>
    </template>
  </USlideover>
</template>
