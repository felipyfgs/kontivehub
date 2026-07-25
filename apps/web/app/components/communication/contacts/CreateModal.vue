<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  loading: boolean
  error: string | null
  canManage: boolean
}>()

const emit = defineEmits<{
  submit: [body: { name: string | null, phone: string, client_id?: number }]
}>()

const name = ref('')
const phone = ref('')
const clientId = ref<number | null>(null)
const validationError = ref<string | null>(null)

function reset() {
  name.value = ''
  phone.value = ''
  clientId.value = null
  validationError.value = null
}

function submit() {
  if (!props.canManage) return
  const normalizedPhone = phone.value.trim()
  if (normalizedPhone.length < 8) {
    validationError.value = 'Informe um telefone WhatsApp válido.'
    return
  }
  validationError.value = null
  emit('submit', {
    name: name.value.trim() || null,
    phone: normalizedPhone,
    ...(clientId.value ? { client_id: clientId.value } : {})
  })
}

watch(open, (isOpen) => {
  if (!isOpen) reset()
})
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Novo contato"
    description="Informe o WhatsApp. O nome é opcional; sem nome o contato fica provisório."
    submit-label="Criar"
    :loading="loading"
    :disabled="!canManage || !phone.trim()"
    test-id="communication-contact-create-modal"
    @submit="submit"
    @cancel="reset"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="validationError || error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="validationError || error || undefined"
        />
        <UFormField label="Nome" name="name">
          <UInput
            v-model="name"
            placeholder="Opcional"
            autocomplete="name"
            class="w-full"
          />
        </UFormField>
        <UFormField label="WhatsApp" name="phone" required>
          <UInput
            v-model="phone"
            placeholder="Ex.: 11999998888"
            autocomplete="tel"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Cliente (opcional)"
          name="client_id"
          hint="Vincula a identidade ao cliente do escritório."
        >
          <FiscalClientPicker
            v-model="clientId"
            search-mode="select"
            placeholder="Selecionar cliente"
            class="w-full min-w-0"
          />
        </UFormField>
      </div>
    </template>
  </ShellFormModal>
</template>
