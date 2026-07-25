<script setup lang="ts">
import * as z from 'zod'
import type { AccordionItem, FormSubmitEvent } from '@nuxt/ui'
import type {
  AddressPayload,
  Client,
  CnaePayload,
  CnpjLookupResult,
  ShareholderPayload,
  StateRegistrationPayload
} from '~/types/api'
import { CLIENT_TAX_REGIME_ITEMS } from '~/utils/clients-tax-regime'
import { registrationStatusLabel } from '~/utils/registration-labels'

const props = defineProps<{
  client?: Client | null
  canManageCredentials?: boolean
  canManageClients?: boolean
  formId?: string
  matrixClientId?: number | null
  matrixLabel?: string | null
  locked?: boolean
  hideActions?: boolean
  /** Revisão de consulta RFB antes de gravar (campos RFB editáveis). */
  reviewMode?: boolean
  reviewLookup?: CnpjLookupResult | null
}>()

const emit = defineEmits<{
  saved: [payload: {
    id: number
    mode: 'create' | 'edit'
    section?: 'resumo' | 'certificado'
    tax_regime?: string | null
  }]
  cancel: []
  openExisting: [id: number]
  confirmRefresh: [lookup: CnpjLookupResult]
}>()

const isEdit = computed(() => !!props.client?.id)
const isReview = computed(() => props.reviewMode === true)
const canEdit = computed(() => props.canManageClients === true)
const fieldsLocked = computed(() => props.locked === true || !canEdit.value)
const cnpjLocked = computed(() => isEdit.value || fieldsLocked.value || isReview.value)
/** Em revisão RFB, endereço/contato/nome fantasia ficam editáveis. */
const rfbLocked = computed(() => fieldsLocked.value || (isEdit.value && !isReview.value))

const optionalEmail = z.string().refine(
  value => !value || z.string().email().safeParse(value).success,
  'Informe um e-mail válido.'
).optional()

const customFieldSchema = z.object({
  label: z.string().min(1, 'Informe o nome do campo.'),
  type: z.enum(['TEXT', 'SECRET']),
  value: z.string().optional()
})

const schema = z.object({
  legal_name: z.string().min(2, 'Informe a razão social.'),
  display_name: z.string().optional(),
  cnpj: z.string().optional(),
  trade_name: z.string().optional(),
  contact_name: z.string().optional(),
  contact_email: optionalEmail,
  contact_phone: z.string().optional(),
  contact_is_whatsapp: z.boolean(),
  notes: z.string().optional(),
  is_active: z.boolean(),
  inactive_reason: z.string().optional(),
  legal_nature_code: z.string().optional(),
  legal_nature_name: z.string().optional(),
  company_size_code: z.string().optional(),
  company_size_name: z.string().optional(),
  capital_social: z.string().optional(),
  tax_regime: z.string().optional(),
  credential_password: z.string().optional(),
  custom_fields: z.array(customFieldSchema).max(20, 'Use no máximo 20 campos adicionais.'),
  address_postal_code: z.string().optional(),
  address_street_type: z.string().optional(),
  address_street: z.string().optional(),
  address_number: z.string().optional(),
  address_complement: z.string().optional(),
  address_district: z.string().optional(),
  address_city: z.string().optional(),
  address_state: z.string().optional(),
  public_email: z.string().optional(),
  public_phone: z.string().optional(),
  public_phone_secondary: z.string().optional()
}).superRefine((data, context) => {
  if (!isEdit.value) {
    const cnpj = normalizeCnpj(data.cnpj || '')
    if (!/^[A-Z0-9]{14}$/.test(cnpj)) {
      context.addIssue({
        code: 'custom',
        path: ['cnpj'],
        message: 'Informe o CNPJ completo com 14 caracteres.'
      })
    }
  }

  const hasContact = Boolean(data.contact_name || data.contact_email || data.contact_phone)
  if (!hasContact || isEdit.value) return

  if (!data.contact_name || data.contact_name.trim().length < 2) {
    context.addIssue({
      code: 'custom',
      path: ['contact_name'],
      message: 'Informe o nome do contato.'
    })
  }
  if (!data.contact_email && !data.contact_phone) {
    context.addIssue({
      code: 'custom',
      path: ['contact_email'],
      message: 'Informe ao menos e-mail ou telefone.'
    })
  }
})

type Schema = z.output<typeof schema>

const api = useApi()
const toast = useToast()
const saving = ref(false)
const lookingUp = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const preview = ref<CnpjLookupResult | null>(null)
const lookupWarning = ref<string | null>(null)
const existingClientId = ref<number | null>(null)
const credentialFile = ref<File | null>(null)
const fileInputKey = ref(0)

const secondaryCnaes = ref<CnaePayload[]>([])
const stateRegistrations = ref<StateRegistrationPayload[]>([])
const shareholders = ref<ShareholderPayload[]>([])
const registrationStatus = ref<string | null>(null)
const registrationStatusAt = ref<string | null>(null)
const registrationStatusReason = ref<string | null>(null)
const specialSituation = ref<string | null>(null)
const activityStartedAt = ref<string | null>(null)
const mainCnaeCode = ref<string | null>(null)
const mainCnaeName = ref<string | null>(null)
const simplesOptant = ref<boolean | null>(null)
const meiOptant = ref<boolean | null>(null)
const sourcesUsed = ref<string[]>([])

const state = reactive<Schema>({
  legal_name: '',
  display_name: '',
  cnpj: '',
  trade_name: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  contact_is_whatsapp: false,
  notes: '',
  is_active: true,
  inactive_reason: '',
  legal_nature_code: '',
  legal_nature_name: '',
  company_size_code: '',
  company_size_name: '',
  capital_social: '',
  tax_regime: 'none',
  credential_password: '',
  custom_fields: [],
  address_postal_code: '',
  address_street_type: '',
  address_street: '',
  address_number: '',
  address_complement: '',
  address_district: '',
  address_city: '',
  address_state: '',
  public_email: '',
  public_phone: '',
  public_phone_secondary: ''
})

const normalizedCnpj = computed(() => normalizeCnpj(state.cnpj || ''))
const canLookup = computed(() => !isEdit.value && /^\d{14}$/.test(normalizedCnpj.value))
const hasContact = computed(() => Boolean(state.contact_name || state.contact_email || state.contact_phone))
const sourceLabel = computed(() => {
  const sources = sourcesUsed.value.length
    ? sourcesUsed.value
    : (preview.value?.sources_used || (preview.value?.source ? [preview.value.source] : []))
  if (!sources.length) return null
  return sources.map((source) => {
    if (source === 'CNPJ_WS') return 'Consulta pública'
    if (source === 'SERPRO_CONSULTA') return 'SERPRO Consulta CNPJ'
    if (source === 'CCMEI') return 'CCMEI'
    return source
  }).join(' + ')
})

const formSectionItems = computed((): AccordionItem[] => {
  const items: AccordionItem[] = [
    {
      label: 'Identificação',
      icon: 'i-lucide-fingerprint',
      value: 'identificacao',
      slot: 'identificacao' as const
    },
    {
      label: 'Situação cadastral',
      icon: 'i-lucide-badge-check',
      value: 'situacao',
      slot: 'situacao' as const
    },
    {
      label: 'Qualificação',
      icon: 'i-lucide-building-2',
      value: 'qualificacao',
      slot: 'qualificacao' as const
    },
    {
      label: 'Atividades',
      icon: 'i-lucide-layers',
      value: 'atividades',
      slot: 'atividades' as const
    },
    {
      label: 'Endereço',
      icon: 'i-lucide-map-pin',
      value: 'endereco',
      slot: 'endereco' as const
    },
    {
      label: 'Contato RFB',
      icon: 'i-lucide-phone',
      value: 'contato-rfb',
      slot: 'contato-rfb' as const
    },
    {
      label: 'Inscrições estaduais',
      icon: 'i-lucide-scroll-text',
      value: 'inscricoes',
      slot: 'inscricoes' as const
    },
    {
      label: 'Quadro societário',
      icon: 'i-lucide-users',
      value: 'qsa',
      slot: 'qsa' as const
    }
  ]

  if (!isReview.value) {
    items.push({
      label: 'Dados internos do escritório',
      icon: 'i-lucide-notebook-pen',
      value: 'escritorio',
      slot: 'escritorio' as const
    })
  }

  return items
})

const formSectionsOpen = ref<string[]>(['identificacao', 'escritorio'])

function normalizeCnpj(value: string): string {
  return value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function formatCapital(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  const num = typeof value === 'number' ? value : Number(String(value).replace(',', '.'))
  if (Number.isNaN(num)) return String(value)
  return num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function applyAddress(address?: AddressPayload | null) {
  state.address_postal_code = address?.postal_code || ''
  state.address_street_type = address?.street_type || ''
  state.address_street = address?.street || ''
  state.address_number = address?.number || ''
  state.address_complement = address?.complement || ''
  state.address_district = address?.district || ''
  state.address_city = address?.city || ''
  state.address_state = address?.state || ''
}

function applyLookupSnapshot(result: CnpjLookupResult) {
  preview.value = result
  sourcesUsed.value = result.sources_used || [result.source]
  state.cnpj = result.establishment.cnpj
  state.legal_name = result.client.legal_name
  state.trade_name = result.establishment.trade_name || ''
  state.legal_nature_code = result.client.legal_nature_code || ''
  state.legal_nature_name = result.client.legal_nature_name || ''
  state.company_size_code = result.client.company_size_code || ''
  state.company_size_name = result.client.company_size_name || ''
  state.capital_social = formatCapital(result.client.capital_social)
  state.public_email = result.establishment.public_email || ''
  state.public_phone = result.establishment.public_phone || ''
  state.public_phone_secondary = result.establishment.public_phone_secondary || ''
  applyAddress(result.establishment.address)
  registrationStatus.value = String(result.establishment.registration_status || '')
  registrationStatusAt.value = result.establishment.registration_status_at || null
  registrationStatusReason.value = result.establishment.registration_status_reason || null
  specialSituation.value = result.establishment.special_situation || null
  activityStartedAt.value = result.establishment.activity_started_at || null
  mainCnaeCode.value = result.establishment.main_cnae_code || null
  mainCnaeName.value = result.establishment.main_cnae_name || null
  secondaryCnaes.value = result.establishment.secondary_cnaes || []
  stateRegistrations.value = result.establishment.state_registrations || []
  shareholders.value = result.establishment.shareholders || []
  simplesOptant.value = result.establishment.simples_optant ?? null
  meiOptant.value = result.establishment.mei_optant ?? null
}

function clearRfbSnapshot() {
  preview.value = null
  sourcesUsed.value = []
  secondaryCnaes.value = []
  stateRegistrations.value = []
  shareholders.value = []
  registrationStatus.value = null
  registrationStatusAt.value = null
  registrationStatusReason.value = null
  specialSituation.value = null
  activityStartedAt.value = null
  mainCnaeCode.value = null
  mainCnaeName.value = null
  simplesOptant.value = null
  meiOptant.value = null
}

function emptyState() {
  state.legal_name = ''
  state.display_name = ''
  state.cnpj = ''
  state.trade_name = ''
  state.contact_name = ''
  state.contact_email = ''
  state.contact_phone = ''
  state.contact_is_whatsapp = false
  state.notes = ''
  state.is_active = true
  state.inactive_reason = ''
  state.legal_nature_code = ''
  state.legal_nature_name = ''
  state.company_size_code = ''
  state.company_size_name = ''
  state.capital_social = ''
  state.tax_regime = 'none'
  state.credential_password = ''
  state.custom_fields.splice(0)
  state.address_postal_code = ''
  state.address_street_type = ''
  state.address_street = ''
  state.address_number = ''
  state.address_complement = ''
  state.address_district = ''
  state.address_city = ''
  state.address_state = ''
  state.public_email = ''
  state.public_phone = ''
  state.public_phone_secondary = ''
  credentialFile.value = null
  fileInputKey.value += 1
  fieldErrors.value = {}
  lookupWarning.value = null
  existingClientId.value = null
  clearRfbSnapshot()
}

function hydrateFromClient(client: Client) {
  const est = client.establishments?.find(e => e.is_matrix) || client.establishments?.[0]
  state.legal_name = client.legal_name || client.name || ''
  state.display_name = client.display_name || ''
  state.cnpj = client.cnpj || est?.cnpj || client.root_cnpj || ''
  state.trade_name = client.trade_name || est?.trade_name || ''
  state.notes = client.notes || ''
  state.is_active = client.is_active
  state.inactive_reason = client.inactive_reason || ''
  state.legal_nature_code = client.legal_nature_code || ''
  state.legal_nature_name = client.legal_nature_name || ''
  state.company_size_code = client.company_size_code || ''
  state.company_size_name = client.company_size_name || ''
  state.capital_social = formatCapital(client.capital_social)
  state.tax_regime = client.tax_regime || 'none'
  state.contact_name = ''
  state.contact_email = ''
  state.contact_phone = ''
  state.contact_is_whatsapp = false
  state.credential_password = ''
  state.custom_fields.splice(0)
  state.public_email = est?.public_email || ''
  state.public_phone = est?.public_phone || ''
  state.public_phone_secondary = est?.public_phone_secondary || ''
  applyAddress(est?.address)
  registrationStatus.value = est?.registration_status ? String(est.registration_status) : null
  registrationStatusAt.value = est?.registration_status_at || null
  registrationStatusReason.value = est?.registration_status_reason || null
  specialSituation.value = est?.special_situation || null
  activityStartedAt.value = est?.activity_started_at || null
  mainCnaeCode.value = est?.main_cnae_code || null
  mainCnaeName.value = est?.main_cnae_name || null
  secondaryCnaes.value = est?.secondary_cnaes || []
  stateRegistrations.value = est?.state_registrations || []
  shareholders.value = est?.shareholders || []
  simplesOptant.value = est?.simples_optant ?? null
  meiOptant.value = est?.mei_optant ?? null
  sourcesUsed.value = est?.registration_source ? [String(est.registration_source)] : []
  preview.value = null
  credentialFile.value = null
  fileInputKey.value += 1
  fieldErrors.value = {}
  lookupWarning.value = null
  existingClientId.value = null
}

function reset() {
  if (props.client?.id) {
    hydrateFromClient(props.client)
  } else {
    emptyState()
  }
}

function clearSensitive() {
  state.credential_password = ''
  credentialFile.value = null
  fileInputKey.value += 1
  for (const field of state.custom_fields) {
    if (field.type === 'SECRET') {
      field.value = ''
    }
  }
}

function addCustomField() {
  if (state.custom_fields.length >= 20) return
  state.custom_fields.push({ label: '', type: 'TEXT', value: '' })
}

function removeCustomField(index: number) {
  state.custom_fields.splice(index, 1)
}

function selectCredentialFile(event: Event) {
  const input = event.target as HTMLInputElement
  credentialFile.value = input.files?.[0] || null
}

async function lookupCnpj() {
  if (lookingUp.value || isEdit.value) return

  if (!canLookup.value) {
    if (/^[A-Z0-9]{14}$/.test(normalizedCnpj.value) && /[A-Z]/.test(normalizedCnpj.value)) {
      lookupWarning.value = 'A consulta pública ainda não aceita CNPJ alfanumérico. Preencha os nomes manualmente.'
    }
    return
  }

  lookingUp.value = true
  lookupWarning.value = null
  try {
    const response = await api.cnpj.lookup(normalizedCnpj.value)
    applyLookupSnapshot(response.data)
    toast.add({ title: 'Dados encontrados. Revise antes de salvar.', color: 'success' })
  } catch (caught) {
    clearRfbSnapshot()
    lookupWarning.value = apiErrorMessage(
      caught,
      'Não foi possível consultar o CNPJ. Continue o cadastro manualmente.'
    )
    toast.add({ title: lookupWarning.value, color: 'warning' })
  } finally {
    lookingUp.value = false
  }
}

function extractExistingClientId(caught: unknown): number | null {
  const error = caught as {
    data?: { data?: { existing_client_id?: number }, existing_client_id?: number }
    response?: { _data?: { data?: { existing_client_id?: number } } }
  }

  return error.data?.data?.existing_client_id
    ?? error.data?.existing_client_id
    ?? error.response?._data?.data?.existing_client_id
    ?? null
}

function buildAddressPayload(): AddressPayload | null {
  if (!state.address_street && !state.address_postal_code && !state.address_city) {
    return preview.value?.establishment.address ?? null
  }
  return {
    postal_code: state.address_postal_code || null,
    street_type: state.address_street_type || null,
    street: state.address_street || null,
    number: state.address_number || null,
    complement: state.address_complement || null,
    district: state.address_district || null,
    city: state.address_city || null,
    city_ibge_code: preview.value?.establishment.address?.city_ibge_code ?? null,
    state: state.address_state || null,
    country: preview.value?.establishment.address?.country ?? 'BR'
  }
}

function buildLookupFromState(): CnpjLookupResult {
  const base = preview.value
  if (!base) {
    throw new Error('Sem snapshot de consulta para confirmar.')
  }

  return {
    source: base.source,
    source_updated_at: base.source_updated_at,
    sources_used: sourcesUsed.value.length ? [...sourcesUsed.value] : (base.sources_used || [base.source]),
    client: {
      ...base.client,
      legal_name: state.legal_name.trim(),
      legal_nature_code: state.legal_nature_code?.trim() || null,
      legal_nature_name: state.legal_nature_name?.trim() || null,
      company_size_code: state.company_size_code?.trim() || null,
      company_size_name: state.company_size_name?.trim() || null,
      capital_social: state.capital_social?.trim() || null
    },
    establishment: {
      ...base.establishment,
      trade_name: state.trade_name?.trim() || null,
      public_email: state.public_email?.trim() || null,
      public_phone: state.public_phone?.trim() || null,
      public_phone_secondary: state.public_phone_secondary?.trim() || null,
      main_cnae_code: mainCnaeCode.value,
      main_cnae_name: mainCnaeName.value,
      secondary_cnaes: [...secondaryCnaes.value],
      state_registrations: [...stateRegistrations.value],
      shareholders: [...shareholders.value],
      simples_optant: simplesOptant.value,
      mei_optant: meiOptant.value,
      registration_status: registrationStatus.value || 'UNKNOWN',
      registration_status_at: registrationStatusAt.value,
      registration_status_reason: registrationStatusReason.value,
      special_situation: specialSituation.value,
      activity_started_at: activityStartedAt.value,
      address: {
        postal_code: state.address_postal_code || null,
        street_type: state.address_street_type || null,
        street: state.address_street || null,
        number: state.address_number || null,
        complement: state.address_complement || null,
        district: state.address_district || null,
        city: state.address_city || null,
        city_ibge_code: base.establishment.address?.city_ibge_code ?? null,
        state: state.address_state || null,
        country: base.establishment.address?.country ?? 'BR'
      }
    }
  }
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (!canEdit.value || fieldsLocked.value) return
  fieldErrors.value = {}
  existingClientId.value = null

  if (isReview.value) {
    saving.value = true
    try {
      emit('confirmRefresh', buildLookupFromState())
    } finally {
      saving.value = false
    }
    return
  }

  if (!isEdit.value && credentialFile.value && !event.data.credential_password) {
    fieldErrors.value = { credential_password: ['Informe a senha do certificado.'] }
    return
  }

  saving.value = true
  try {
    if (isEdit.value && props.client) {
      await api.clients.update(props.client.id, {
        legal_name: event.data.legal_name.trim(),
        display_name: event.data.display_name?.trim() || null,
        notes: event.data.notes?.trim() || null,
        is_active: event.data.is_active,
        inactive_reason: event.data.inactive_reason?.trim() || null,
        legal_nature_code: event.data.legal_nature_code?.trim() || null,
        legal_nature_name: event.data.legal_nature_name?.trim() || null,
        company_size_code: event.data.company_size_code?.trim() || null,
        company_size_name: event.data.company_size_name?.trim() || null,
        tax_regime: (() => {
          const raw = event.data.tax_regime?.trim()
          return !raw || raw === 'none' ? null : raw
        })()
      })
      toast.add({ title: 'Cadastro atualizado.', color: 'success' })
      emit('saved', { id: props.client.id, mode: 'edit' })
      return
    }

    const establishment = preview.value?.establishment
    const clientData = preview.value?.client

    const response = await api.clients.create({
      legal_name: event.data.legal_name.trim(),
      display_name: event.data.display_name?.trim() || null,
      cnpj: normalizeCnpj(event.data.cnpj || ''),
      trade_name: event.data.trade_name?.trim() || null,
      notes: event.data.notes?.trim() || null,
      matrix_client_id: props.matrixClientId || null,
      is_matrix: props.matrixClientId
        ? false
        : (establishment?.is_matrix ?? true),
      registration_status: establishment?.registration_status ?? registrationStatus.value ?? 'UNKNOWN',
      registration_status_at: establishment?.registration_status_at ?? registrationStatusAt.value,
      registration_status_reason: establishment?.registration_status_reason ?? registrationStatusReason.value,
      special_situation: establishment?.special_situation ?? specialSituation.value,
      activity_started_at: establishment?.activity_started_at ?? activityStartedAt.value,
      main_cnae_code: establishment?.main_cnae_code ?? mainCnaeCode.value,
      main_cnae_name: establishment?.main_cnae_name ?? mainCnaeName.value,
      secondary_cnaes: establishment?.secondary_cnaes ?? secondaryCnaes.value,
      state_registrations: establishment?.state_registrations ?? stateRegistrations.value,
      shareholders: establishment?.shareholders ?? shareholders.value,
      public_email: event.data.public_email?.trim() || establishment?.public_email || null,
      public_phone: event.data.public_phone?.trim() || establishment?.public_phone || null,
      public_phone_secondary: event.data.public_phone_secondary?.trim()
        || establishment?.public_phone_secondary
        || null,
      public_fax: establishment?.public_fax ?? null,
      simples_optant: establishment?.simples_optant ?? simplesOptant.value,
      mei_optant: establishment?.mei_optant ?? meiOptant.value,
      capital_social: clientData?.capital_social ?? null,
      responsible_qualification_code: clientData?.responsible_qualification_code ?? null,
      responsible_qualification_name: clientData?.responsible_qualification_name ?? null,
      legal_nature_code: event.data.legal_nature_code?.trim() || clientData?.legal_nature_code || null,
      legal_nature_name: event.data.legal_nature_name?.trim() || clientData?.legal_nature_name || null,
      company_size_code: event.data.company_size_code?.trim() || clientData?.company_size_code || null,
      company_size_name: event.data.company_size_name?.trim() || clientData?.company_size_name || null,
      tax_regime: (() => {
        const raw = event.data.tax_regime?.trim()
        return !raw || raw === 'none' ? null : raw
      })(),
      address: buildAddressPayload(),
      initial_contact: hasContact.value
        ? {
            name: event.data.contact_name?.trim() || '',
            email: event.data.contact_email?.trim() || null,
            phone: event.data.contact_phone?.trim() || null,
            is_whatsapp: event.data.contact_is_whatsapp,
            is_primary: true,
            receives_alerts: false
          }
        : null,
      custom_fields: event.data.custom_fields.map(field => ({
        label: field.label.trim(),
        type: field.type,
        value: field.value || null
      }))
    })

    const clientId = response.data.client.id
    let section: 'resumo' | 'certificado' = 'resumo'

    if (credentialFile.value) {
      try {
        await api.credentials.activate(clientId, credentialFile.value, event.data.credential_password || '')
        toast.add({ title: 'Cliente e certificado A1 cadastrados.', color: 'success' })
      } catch (caught) {
        section = 'certificado'
        toast.add({
          title: 'Cliente criado, mas o certificado não foi ativado.',
          description: apiErrorMessage(caught, 'Revise o PFX e tente novamente na seção Certificado A1.'),
          color: 'warning'
        })
      }
    } else {
      toast.add({ title: 'Cliente cadastrado.', color: 'success' })
    }

    emit('saved', {
      id: clientId,
      mode: 'create',
      section,
      tax_regime: (() => {
        const raw = event.data.tax_regime?.trim()
        return !raw || raw === 'none' ? null : raw
      })()
    })
  } catch (caught) {
    fieldErrors.value = apiFieldErrors(caught)
    existingClientId.value = extractExistingClientId(caught)
    toast.add({
      title: existingClientId.value
        ? 'Este CNPJ já pertence a um cliente deste escritório.'
        : apiErrorMessage(caught, isEdit.value ? 'Falha ao atualizar cadastro.' : 'Falha ao criar cliente.'),
      color: existingClientId.value ? 'warning' : 'error'
    })
  } finally {
    saving.value = false
    if (!isEdit.value) {
      clearSensitive()
    } else {
      state.credential_password = ''
    }
  }
}

watch(
  () => props.client,
  (client) => {
    if (isReview.value) return
    if (client?.id) {
      hydrateFromClient(client)
    } else {
      emptyState()
    }
  },
  { immediate: true, deep: true }
)

watch(
  () => [props.reviewMode, props.reviewLookup] as const,
  ([review, lookup]) => {
    if (!review || !lookup) return
    applyLookupSnapshot(lookup)
    formSectionsOpen.value = ['identificacao', 'qualificacao', 'endereco', 'situacao']
  },
  { immediate: true }
)

defineExpose({ reset, clearSensitive, saving })
</script>

<template>
  <UForm
    :id="formId || 'client-form'"
    :schema="schema"
    :state="state"
    class="flex min-h-0 flex-1 flex-col"
    @submit="onSubmit"
  >
    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-0.5 pr-1">
      <UAlert
        v-if="!isEdit && matrixClientId"
        color="primary"
        variant="subtle"
        icon="i-lucide-link"
        title="Filial vinculada à matriz"
      />

      <ShellPanelAccordion
        v-model="formSectionsOpen"
        :items="formSectionItems"
        type="multiple"
        test-id="client-form-sections"
      >
        <template #identificacao-body>
          <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
            <UFormField
              :label="isEdit ? 'CNPJ' : 'CNPJ completo'"
              name="cnpj"
              :required="!isEdit"
              :help="isEdit ? 'Identificador imutável — não pode ser alterado.' : 'Numérico ou alfanumérico, com ou sem máscara.'"
              :error="fieldErrors.cnpj?.[0]"
            >
              <UInput
                v-model="state.cnpj"
                class="w-full font-mono"
                autocomplete="off"
                :disabled="cnpjLocked"
                :autofocus="!isEdit && !fieldsLocked"
                @blur="lookupCnpj"
                @keydown.enter.prevent="lookupCnpj"
              />
            </UFormField>
            <UButton
              v-if="!isEdit && !fieldsLocked"
              type="button"
              color="neutral"
              variant="subtle"
              label="Buscar"
              icon="i-lucide-search"
              class="self-end"
              :loading="lookingUp"
              :disabled="!canLookup"
              @mousedown.prevent
              @click="lookupCnpj"
            />
          </div>

          <UFormField
            v-if="isEdit && client?.root_cnpj"
            class="mt-4"
            label="Raiz CNPJ"
            name="root_cnpj"
            help="Derivada do CNPJ — somente leitura."
          >
            <UInput
              :model-value="client.root_cnpj"
              class="w-full font-mono"
              disabled
              readonly
            />
          </UFormField>

          <UAlert
            v-if="preview && !isEdit"
            class="mt-4"
            color="success"
            variant="subtle"
            icon="i-lucide-database-zap"
            :title="`Dados sugeridos: ${registrationStatusLabel(preview.establishment.registration_status)}`"
            :description="sourceLabel ? `Fonte: ${sourceLabel}` : undefined"
            data-testid="cnpj-lookup-preview"
          />
          <UAlert
            v-if="lookupWarning && !isEdit"
            class="mt-4"
            color="warning"
            variant="subtle"
            icon="i-lucide-pencil-line"
            :title="lookupWarning"
          />
          <UAlert
            v-if="existingClientId && !isEdit"
            class="mt-4"
            color="warning"
            variant="subtle"
            icon="i-lucide-link"
            title="Cliente já existe"
          >
            <template #actions>
              <UButton
                size="sm"
                label="Abrir cliente"
                @click="emit('openExisting', existingClientId)"
              />
            </template>
          </UAlert>

          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Razão social"
              name="legal_name"
              required
              :error="fieldErrors.legal_name?.[0]"
            >
              <UInput
                v-model="state.legal_name"
                class="w-full"
                autocomplete="organization"
                :disabled="fieldsLocked"
              />
            </UFormField>
            <UFormField
              label="Nome fantasia"
              name="trade_name"
              :error="fieldErrors.trade_name?.[0]"
            >
              <UInput
                v-model="state.trade_name"
                class="w-full"
                :disabled="rfbLocked"
              />
            </UFormField>
          </div>
        </template>

        <template #situacao-body>
          <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <p class="text-muted">
                Situação
              </p>
              <p class="font-medium">
                {{ registrationStatus ? registrationStatusLabel(registrationStatus) : '—' }}
              </p>
            </div>
            <div>
              <p class="text-muted">
                Data da situação
              </p>
              <p class="font-medium">
                {{ registrationStatusAt || '—' }}
              </p>
            </div>
            <div class="sm:col-span-2 lg:col-span-1">
              <p class="text-muted">
                Motivo
              </p>
              <p class="font-medium">
                {{ registrationStatusReason || '—' }}
              </p>
            </div>
            <div>
              <p class="text-muted">
                Situação especial
              </p>
              <p class="font-medium">
                {{ specialSituation || '—' }}
              </p>
            </div>
            <div>
              <p class="text-muted">
                Início das atividades
              </p>
              <p class="font-medium">
                {{ activityStartedAt || '—' }}
              </p>
            </div>
            <div>
              <p class="text-muted">
                Simples Nacional
              </p>
              <p class="font-medium">
                {{ simplesOptant === true ? 'Optante' : simplesOptant === false ? 'Não optante' : '—' }}
              </p>
            </div>
            <div>
              <p class="text-muted">
                MEI
              </p>
              <p class="font-medium">
                {{ meiOptant === true ? 'Optante' : meiOptant === false ? 'Não optante' : '—' }}
              </p>
            </div>
          </div>
        </template>

        <template #qualificacao-body>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Natureza jurídica (cód.)"
              name="legal_nature_code"
              :error="fieldErrors.legal_nature_code?.[0]"
            >
              <UInput v-model="state.legal_nature_code" class="w-full" :disabled="fieldsLocked" />
            </UFormField>
            <UFormField
              label="Natureza jurídica"
              name="legal_nature_name"
              :error="fieldErrors.legal_nature_name?.[0]"
            >
              <UInput v-model="state.legal_nature_name" class="w-full" :disabled="fieldsLocked" />
            </UFormField>
            <UFormField
              label="Porte (cód.)"
              name="company_size_code"
              :error="fieldErrors.company_size_code?.[0]"
            >
              <UInput v-model="state.company_size_code" class="w-full" :disabled="fieldsLocked" />
            </UFormField>
            <UFormField
              label="Porte"
              name="company_size_name"
              :error="fieldErrors.company_size_name?.[0]"
            >
              <UInput v-model="state.company_size_name" class="w-full" :disabled="fieldsLocked" />
            </UFormField>
            <UFormField
              label="Capital social"
              name="capital_social"
              class="sm:col-span-2"
            >
              <UInput v-model="state.capital_social" class="w-full" :disabled="rfbLocked" />
            </UFormField>
          </div>
        </template>

        <template #atividades-body>
          <div class="space-y-3 text-sm">
            <div>
              <p class="text-muted">
                CNAE principal
              </p>
              <p class="font-medium">
                {{ mainCnaeCode ? `${mainCnaeCode} — ${mainCnaeName || ''}` : '—' }}
              </p>
            </div>
            <div v-if="secondaryCnaes.length">
              <p class="mb-1 text-muted">
                CNAEs secundários
              </p>
              <ul class="max-h-40 space-y-1 overflow-y-auto">
                <li
                  v-for="cnae in secondaryCnaes"
                  :key="cnae.code"
                  class="font-medium"
                >
                  {{ cnae.code }} — {{ cnae.name || '' }}
                </li>
              </ul>
            </div>
            <p
              v-else
              class="text-muted"
            >
              Nenhum CNAE secundário informado.
            </p>
          </div>
        </template>

        <template #endereco-body>
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <UFormField label="CEP" name="address_postal_code">
              <UInput v-model="state.address_postal_code" class="w-full font-mono" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Tipo logradouro" name="address_street_type">
              <UInput v-model="state.address_street_type" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Logradouro" name="address_street" class="sm:col-span-2 lg:col-span-1">
              <UInput v-model="state.address_street" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Número" name="address_number">
              <UInput v-model="state.address_number" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Complemento" name="address_complement">
              <UInput v-model="state.address_complement" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Bairro" name="address_district">
              <UInput v-model="state.address_district" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Cidade" name="address_city">
              <UInput v-model="state.address_city" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="UF" name="address_state">
              <UInput
                v-model="state.address_state"
                class="w-full"
                maxlength="2"
                :disabled="rfbLocked"
              />
            </UFormField>
          </div>
        </template>

        <template #contato-rfb-body>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="E-mail público" name="public_email" class="sm:col-span-2">
              <UInput
                v-model="state.public_email"
                type="email"
                class="w-full"
                :disabled="rfbLocked"
              />
            </UFormField>
            <UFormField label="Telefone" name="public_phone">
              <UInput v-model="state.public_phone" class="w-full" :disabled="rfbLocked" />
            </UFormField>
            <UFormField label="Telefone 2" name="public_phone_secondary">
              <UInput v-model="state.public_phone_secondary" class="w-full" :disabled="rfbLocked" />
            </UFormField>
          </div>
        </template>

        <template #inscricoes-body>
          <ul
            v-if="stateRegistrations.length"
            class="space-y-1 text-sm"
          >
            <li
              v-for="(ie, index) in stateRegistrations"
              :key="`${ie.number}-${index}`"
              class="font-medium"
            >
              {{ ie.state || '—' }} · {{ ie.number }}
              <span class="text-muted">{{ ie.active === false ? '(inativa)' : ie.active === true ? '(ativa)' : '' }}</span>
            </li>
          </ul>
          <p
            v-else
            class="text-sm text-muted"
          >
            Nenhuma inscrição estadual na consulta.
          </p>
        </template>

        <template #qsa-body>
          <ul
            v-if="shareholders.length"
            class="grid gap-2 text-sm sm:grid-cols-2"
          >
            <li
              v-for="(socio, index) in shareholders"
              :key="`${socio.name}-${index}`"
              class="rounded-md border border-default px-3 py-2"
            >
              <p class="font-medium">
                {{ socio.name }}
              </p>
              <p class="text-muted">
                {{ socio.qualification_name || socio.type || 'Sócio' }}
                <template v-if="socio.document_masked">
                  · {{ socio.document_masked }}
                </template>
                <template v-if="socio.entered_at">
                  · desde {{ socio.entered_at }}
                </template>
              </p>
            </li>
          </ul>
          <p
            v-else
            class="text-sm text-muted"
          >
            Nenhum sócio retornado pela consulta.
          </p>
        </template>

        <template #escritorio-body>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Nome interno"
              name="display_name"
              help="Opcional. Rótulo curto no painel."
              :error="fieldErrors.display_name?.[0]"
            >
              <UInput v-model="state.display_name" class="w-full" :disabled="fieldsLocked" />
            </UFormField>
            <UFormField
              label="Regime tributário"
              name="tax_regime"
              :error="fieldErrors.tax_regime?.[0]"
            >
              <USelect
                v-model="state.tax_regime"
                :items="[
                  { label: 'Não informado', value: 'none' },
                  ...CLIENT_TAX_REGIME_ITEMS
                ]"
                value-key="value"
                class="w-full"
                :disabled="fieldsLocked"
                placeholder="Selecione o regime"
              />
            </UFormField>
          </div>

          <template v-if="isEdit">
            <USeparator
              class="my-4"
              label="Estado no escritório"
            />
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                name="is_active"
                label="Cliente ativo"
              >
                <USwitch
                  v-model="state.is_active"
                  :disabled="fieldsLocked"
                />
              </UFormField>
              <UFormField
                name="inactive_reason"
                label="Motivo de inativação"
                :error="fieldErrors.inactive_reason?.[0]"
              >
                <UTextarea
                  v-model="state.inactive_reason"
                  class="w-full"
                  :rows="2"
                  :disabled="fieldsLocked"
                />
              </UFormField>
            </div>
          </template>

          <template v-if="!isEdit">
            <USeparator
              class="my-4"
              label="Contato do escritório (opcional)"
            />
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Nome do contato"
                name="contact_name"
                :error="fieldErrors['initial_contact.name']?.[0]"
              >
                <UInput
                  v-model="state.contact_name"
                  class="w-full"
                  autocomplete="name"
                  :disabled="fieldsLocked"
                />
              </UFormField>
              <UFormField
                label="E-mail"
                name="contact_email"
                :error="fieldErrors['initial_contact.email']?.[0]"
              >
                <UInput
                  v-model="state.contact_email"
                  type="email"
                  class="w-full"
                  autocomplete="email"
                  :disabled="fieldsLocked"
                />
              </UFormField>
              <UFormField
                label="Telefone / WhatsApp"
                name="contact_phone"
                :error="fieldErrors['initial_contact.phone']?.[0]"
              >
                <UInput
                  v-model="state.contact_phone"
                  type="tel"
                  class="w-full"
                  autocomplete="tel"
                  :disabled="fieldsLocked"
                />
              </UFormField>
              <UCheckbox
                v-model="state.contact_is_whatsapp"
                label="Este número usa WhatsApp"
                name="contact_is_whatsapp"
                class="self-end pb-2"
                :disabled="fieldsLocked"
              />
            </div>

            <USeparator
              class="my-4"
              label="Informações adicionais"
            />
            <div class="space-y-3">
              <div
                v-for="(field, index) in state.custom_fields"
                :key="index"
                class="grid gap-2 rounded-lg border border-default p-3 sm:grid-cols-[1fr_9rem_1fr_auto]"
              >
                <UFormField
                  :name="`custom_fields.${index}.label`"
                  label="Nome do campo"
                  :error="fieldErrors[`custom_fields.${index}.label`]?.[0] || fieldErrors['custom_fields']?.[0]"
                >
                  <UInput
                    v-model="field.label"
                    class="w-full"
                    placeholder="Ex.: Acesso prefeitura"
                    :disabled="fieldsLocked"
                  />
                </UFormField>
                <UFormField
                  :name="`custom_fields.${index}.type`"
                  label="Tipo"
                  :error="fieldErrors[`custom_fields.${index}.type`]?.[0]"
                >
                  <USelect
                    v-model="field.type"
                    :items="canManageCredentials
                      ? [{ label: 'Texto', value: 'TEXT' }, { label: 'Segredo', value: 'SECRET' }]
                      : [{ label: 'Texto', value: 'TEXT' }]"
                    class="w-full"
                    :disabled="fieldsLocked"
                  />
                </UFormField>
                <UFormField
                  :name="`custom_fields.${index}.value`"
                  label="Valor"
                  :error="fieldErrors[`custom_fields.${index}.value`]?.[0]"
                >
                  <UInput
                    v-model="field.value"
                    :type="field.type === 'SECRET' ? 'password' : 'text'"
                    class="w-full"
                    :autocomplete="field.type === 'SECRET' ? 'new-password' : 'off'"
                    :disabled="fieldsLocked"
                  />
                </UFormField>
                <UButton
                  type="button"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-trash-2"
                  square
                  class="self-end"
                  :aria-label="`Remover campo ${index + 1}`"
                  :disabled="fieldsLocked"
                  @click="removeCustomField(index)"
                />
              </div>
              <UButton
                type="button"
                color="neutral"
                variant="subtle"
                icon="i-lucide-plus"
                label="Adicionar campo"
                :disabled="fieldsLocked || state.custom_fields.length >= 20"
                @click="addCustomField"
              />
            </div>

            <template v-if="canManageCredentials">
              <USeparator
                class="my-4"
                label="Certificado A1 (opcional)"
              />
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField
                  label="Arquivo PFX"
                  name="pfx"
                  help=".pfx ou .p12, máximo de 5 MB."
                >
                  <input
                    :key="fileInputKey"
                    type="file"
                    accept=".pfx,.p12,application/x-pkcs12"
                    class="block w-full rounded-md border border-default bg-default px-3 py-2 text-sm"
                    :disabled="fieldsLocked"
                    @change="selectCredentialFile"
                  >
                </UFormField>
                <UFormField
                  label="Senha do certificado"
                  name="credential_password"
                  :error="fieldErrors.credential_password?.[0]"
                >
                  <UInput
                    v-model="state.credential_password"
                    type="password"
                    class="w-full"
                    autocomplete="new-password"
                    :disabled="fieldsLocked || !credentialFile"
                  />
                </UFormField>
              </div>
            </template>
          </template>

          <USeparator
            class="my-4"
            label="Notas"
          />
          <UFormField
            label="Observações"
            name="notes"
            help="Informações gerais sem senhas, tokens ou material do certificado."
            :error="fieldErrors.notes?.[0]"
          >
            <UTextarea
              v-model="state.notes"
              class="w-full"
              :rows="3"
              :disabled="fieldsLocked"
            />
          </UFormField>
        </template>
      </ShellPanelAccordion>
    </div>

    <div
      v-if="!hideActions && !fieldsLocked"
      class="mt-4 flex shrink-0 justify-end gap-2 border-t border-default pt-4"
    >
      <UButton
        color="neutral"
        variant="subtle"
        type="button"
        label="Cancelar"
        :disabled="saving"
        @click="emit('cancel')"
      />
      <UButton
        v-if="canEdit"
        type="submit"
        color="primary"
        :label="isReview ? 'Aplicar atualização' : (isEdit ? 'Salvar alterações' : 'Salvar cliente')"
        :icon="isReview ? 'i-lucide-check' : 'i-lucide-save'"
        :loading="saving"
      />
    </div>
  </UForm>
</template>
