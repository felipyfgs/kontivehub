<script setup lang="ts">
/**
 * Perfil institucional (4 campos) — arquétipo settings/index do template.
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { OfficeInstitutionalProfile } from '~/types/api'

const props = withDefaults(defineProps<{
  profile: OfficeInstitutionalProfile | null
  loading?: boolean
  saving?: boolean
  readonly?: boolean
  /** false quando o pai usa ShellPanelAccordion (evita double title). */
  showHeader?: boolean
}>(), {
  loading: false,
  saving: false,
  readonly: false,
  showHeader: true
})

const emit = defineEmits<{
  save: [payload: {
    cnpj: string
    legal_name: string
    institutional_email: string
    institutional_phone: string
    confirm_cnpj_change?: boolean
  }]
}>()

const schema = z.object({
  cnpj: z.string().max(18).refine((value) => {
    const normalized = value.replace(/[^0-9A-Za-z]/g, '')
    return normalized.length === 0 || normalized.length === 14
  }, 'Se informado, o CNPJ deve ter 14 caracteres.'),
  legal_name: z.string().min(2, 'Informe a razão social.').max(200),
  institutional_email: z.string().email('E-mail institucional inválido.'),
  institutional_phone: z.string().min(8, 'Informe o telefone institucional.').max(32)
})

type Schema = z.output<typeof schema>

const state = reactive<Schema>({
  cnpj: '',
  legal_name: '',
  institutional_email: '',
  institutional_phone: ''
})

const confirmCnpjOpen = ref(false)
const pendingSubmit = ref<Schema | null>(null)

const originalCnpj = computed(() =>
  String(props.profile?.cnpj || '').replace(/\D/g, '').toUpperCase()
)

watch(
  () => props.profile,
  (p) => {
    if (!p) return
    state.cnpj = p.cnpj ? String(p.cnpj) : ''
    state.legal_name = p.legal_name ? String(p.legal_name) : ''
    state.institutional_email = p.institutional_email ? String(p.institutional_email) : ''
    state.institutional_phone = p.institutional_phone ? String(p.institutional_phone) : ''
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<Schema>) {
  if (props.readonly) return
  const nextCnpj = event.data.cnpj.replace(/[^0-9A-Za-z]/g, '').toUpperCase()
  const prev = originalCnpj.value
  if (prev && nextCnpj && prev !== nextCnpj) {
    pendingSubmit.value = event.data
    confirmCnpjOpen.value = true
    return
  }
  emit('save', {
    cnpj: nextCnpj,
    legal_name: event.data.legal_name.trim(),
    institutional_email: event.data.institutional_email.trim(),
    institutional_phone: event.data.institutional_phone.trim()
  })
}

function confirmCnpjChange() {
  const data = pendingSubmit.value
  confirmCnpjOpen.value = false
  if (!data) return
  emit('save', {
    cnpj: data.cnpj.replace(/[^0-9A-Za-z]/g, '').toUpperCase(),
    legal_name: data.legal_name.trim(),
    institutional_email: data.institutional_email.trim(),
    institutional_phone: data.institutional_phone.trim(),
    confirm_cnpj_change: true
  })
  pendingSubmit.value = null
}
</script>

<template>
  <div data-testid="settings-profile-section">
    <UForm
      id="office-profile-form"
      :schema="schema"
      :state="state"
      @submit="onSubmit"
    >
      <ShellSectionHeader
        v-if="showHeader"
        title="Perfil do escritório"
      >
        <UButton
          v-if="!readonly"
          form="office-profile-form"
          type="submit"
          label="Salvar"
          color="neutral"
          icon="i-lucide-save"
          :loading="saving"
          :disabled="loading"
          data-testid="settings-profile-save"
        />
      </ShellSectionHeader>
      <div
        v-else-if="!readonly"
        class="mb-3 flex justify-end"
      >
        <UButton
          form="office-profile-form"
          type="submit"
          label="Salvar"
          color="neutral"
          icon="i-lucide-save"
          :loading="saving"
          :disabled="loading"
          data-testid="settings-profile-save"
        />
      </div>

      <ShellSectionCard>
        <div
          v-if="loading && !profile"
          class="space-y-3"
          role="status"
          aria-label="Carregando perfil"
        >
          <USkeleton class="h-10 w-full" />
          <USkeleton class="h-10 w-full" />
          <USkeleton class="h-10 w-2/3" />
        </div>
        <template v-else>
          <UFormField
            name="cnpj"
            label="CNPJ"
            hint="Opcional — pode preencher depois"
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInput
              v-model="state.cnpj"
              autocomplete="off"
              placeholder="Opcional"
              class="w-full sm:w-64"
              :disabled="readonly"
              data-testid="settings-profile-cnpj"
            />
          </UFormField>
          <USeparator />
          <UFormField
            name="legal_name"
            label="Razão social"
            required
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInput
              v-model="state.legal_name"
              autocomplete="organization"
              class="w-full sm:w-64"
              :disabled="readonly"
              data-testid="settings-profile-legal-name"
            />
          </UFormField>
          <USeparator />
          <UFormField
            name="institutional_email"
            label="E-mail institucional"
            required
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInput
              v-model="state.institutional_email"
              type="email"
              autocomplete="email"
              class="w-full sm:w-64"
              :disabled="readonly"
              data-testid="settings-profile-email"
            />
          </UFormField>
          <USeparator />
          <UFormField
            name="institutional_phone"
            label="Telefone institucional"
            required
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInput
              v-model="state.institutional_phone"
              type="tel"
              autocomplete="tel"
              class="w-full sm:w-64"
              :disabled="readonly"
              data-testid="settings-profile-phone"
            />
          </UFormField>
        </template>
      </ShellSectionCard>
    </UForm>

    <ShellFormModal
      v-model:open="confirmCnpjOpen"
      title="Trocar CNPJ?"
      description="O certificado A1 atual será invalidado se for de outro CNPJ."
      :show-default-footer="false"
    >
      <template #footer>
        <ShellModalFooter
          submit-label="Confirmar troca"
          submit-color="warning"
          submit-test-id="settings-confirm-cnpj-change"
          @cancel="() => { confirmCnpjOpen = false }"
          @submit="confirmCnpjChange"
        />
      </template>
    </ShellFormModal>
  </div>
</template>
