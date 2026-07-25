<script setup lang="ts">
/**
 * Captura de saídas MA — formulário simples: XML NFC-e/NF-e + CSC + ID CSC.
 */
import type { Establishment, OutboundCaptureProfile, OutboundSeriesCursor } from '~/types/api'

const props = defineProps<{
  clientId: number
  establishments: Establishment[]
  canManage: boolean
  canAdmin: boolean
}>()

const api = useApi()
const toast = useToast()

const loading = ref(false)
const submitting = ref(false)
const lastError = ref<string | null>(null)

const profiles = ref<OutboundCaptureProfile[]>([])
const seriesByProfile = ref<Record<number, OutboundSeriesCursor[]>>({})

const selectedEstablishmentId = ref<number | undefined>(undefined)
const environment = ref<'homologation' | 'production'>('production')
const seedFile = ref<File | null>(null)
const seedFileInputKey = ref(0)
const cscId = ref('')
const cscToken = ref('')

const maEstablishments = computed(() =>
  props.establishments.filter((e) => {
    const uf = (e.address?.state || '').toUpperCase()
    return uf === 'MA' || uf === ''
  })
)

const establishmentItems = computed(() =>
  maEstablishments.value.map(e => ({
    label: `${e.cnpj}${e.trade_name ? ` · ${e.trade_name}` : ''}`.trim(),
    value: e.id
  }))
)

const selectedEstablishmentIdNum = computed((): number | null => {
  const raw = selectedEstablishmentId.value
  if (raw === undefined || raw === null) return null
  const n = typeof raw === 'number' ? raw : Number(raw)
  return Number.isFinite(n) && n > 0 ? n : null
})

const selectedEstablishment = computed(() => {
  const id = selectedEstablishmentIdNum.value
  if (id === null) return null
  return maEstablishments.value.find(e => e.id === id) || null
})

/** Perfil 65 do estabelecimento/ambiente atual (para CSC e resumo). */
const profile65 = computed(() =>
  profiles.value.find(
    p => p.model === '65'
      && p.establishment_id === selectedEstablishmentIdNum.value
      && p.environment === environment.value
  ) || profiles.value.find(p => p.model === '65') || null
)

const mainSeries = computed(() => {
  const p = profile65.value
  if (!p) return null
  const list = seriesByProfile.value[p.id] || []
  return list[0] || null
})

const canSave = computed(() => {
  if (!props.canManage && !props.canAdmin) return false
  // XML e/ou par completo de CSC (não exige CSC se só quiser reenviar XML)
  const hasCscPair = Boolean(cscId.value.trim()) && Boolean(cscToken.value.trim())
  return Boolean(seedFile.value) || hasCscPair
})

async function loadCscIntoForm(profileId: number) {
  if (!props.canAdmin) return
  try {
    const res = await api.outbound.cscState(profileId)
    if (res.data.csc_id) {
      cscId.value = res.data.csc_id
    }
    if (res.data.csc) {
      cscToken.value = res.data.csc
    }
  } catch {
    // VIEWER/OPERATOR ou falha: só metadados do perfil
  }
}

async function load() {
  loading.value = true
  lastError.value = null
  try {
    const res = await api.outbound.profiles({ client_id: props.clientId })
    profiles.value = res.data || []
    seriesByProfile.value = {}
    for (const p of profiles.value) {
      const s = await api.outbound.series(p.id)
      seriesByProfile.value[p.id] = s.data || []
    }
    const p65 = profile65.value
    if (p65?.csc?.configured && props.canAdmin) {
      await loadCscIntoForm(p65.id)
    } else if (p65?.csc?.csc_id) {
      cscId.value = p65.csc.csc_id
    }
  } catch (caught) {
    lastError.value = apiErrorMessage(caught, 'Falha ao carregar.')
  } finally {
    loading.value = false
  }
}

function onSeedFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] || null
  lastError.value = null
  if (!file) {
    seedFile.value = null
    return
  }
  const lower = file.name.toLowerCase()
  if (lower.endsWith('.pfx') || lower.endsWith('.p12') || lower.endsWith('.pem')) {
    lastError.value = 'Envie o XML da NFC-e/NF-e (procNFe), não o certificado PFX.'
    seedFile.value = null
    seedFileInputKey.value += 1
    return
  }
  if (!lower.endsWith('.xml') && file.type !== 'text/xml' && file.type !== 'application/xml') {
    lastError.value = 'Selecione um arquivo .xml.'
    seedFile.value = null
    seedFileInputKey.value += 1
    return
  }
  seedFile.value = file
}

function clearSeedFile() {
  seedFile.value = null
  seedFileInputKey.value += 1
}

/**
 * Um único botão: XML (semente) e/ou CSC.
 * Se houver XML de modelo 65, tenta gravar CSC no perfil resultante.
 * Ativa perfil com mandato padrão se ainda SEED_READY e for admin.
 */
async function saveAll() {
  lastError.value = null

  if (!props.canManage && !props.canAdmin) {
    lastError.value = 'Sem permissão para salvar.'
    return
  }

  const establishment = selectedEstablishment.value
  if (!establishment) {
    lastError.value = 'Selecione o estabelecimento.'
    toast.add({ title: lastError.value, color: 'error' })
    return
  }

  if (!seedFile.value && !(cscId.value.trim() && cscToken.value.trim())) {
    lastError.value = 'Informe o XML e/ou o CSC + ID CSC.'
    toast.add({ title: lastError.value, color: 'warning' })
    return
  }

  submitting.value = true
  try {
    let profile: OutboundCaptureProfile | null = profile65.value

    // 1) XML semente
    if (seedFile.value && props.canManage) {
      const seedRes = await api.outbound.seed(establishment.id, {
        environment: environment.value,
        file: seedFile.value
      })
      profile = seedRes.data.profile
      seedFile.value = null
      seedFileInputKey.value += 1
      toast.add({ title: 'XML registrado.', color: 'success' })
    }

    await load()
    profile = profile65.value || profile

    // 2) CSC (modelo 65)
    if (cscId.value.trim() && cscToken.value.trim()) {
      if (!props.canAdmin) {
        lastError.value = 'Só ADMIN grava CSC. XML pode ser salvo por OPERATOR.'
        toast.add({ title: lastError.value, color: 'warning' })
      } else if (!profile || profile.model !== '65') {
        lastError.value = 'CSC é da NFC-e (modelo 65). Envie primeiro um XML modelo 65.'
        toast.add({ title: lastError.value, color: 'error' })
      } else {
        const stored = await api.outbound.storeCsc(profile.id, {
          csc: cscToken.value.trim(),
          csc_id: cscId.value.trim()
        })
        // Mantém exibido o que acabou de salvar
        cscId.value = stored.data.csc_id || cscId.value
        cscToken.value = stored.data.csc || cscToken.value
        toast.add({ title: 'CSC salvo.', color: 'success' })

        // Ativa automaticamente se ainda não ativo
        if (profile.status !== 'ACTIVE') {
          await api.outbound.activate(profile.id, {
            mandate_reference: profile.mandate_reference || 'MANDATO-LOCAL',
            allowlisted: true
          })
          toast.add({ title: 'Perfil ativado.', color: 'success' })
        }
      }
    }

    await load()
  } catch (caught) {
    lastError.value = apiErrorMessage(caught, 'Não foi possível salvar.')
    toast.add({ title: lastError.value, color: 'error' })
  } finally {
    submitting.value = false
  }
}

function syncDefaultEstablishment() {
  if (selectedEstablishmentIdNum.value !== null) return
  if (maEstablishments.value[0]) {
    selectedEstablishmentId.value = maEstablishments.value[0].id
  }
}

onMounted(() => {
  syncDefaultEstablishment()
  load()
})

watch(() => props.clientId, () => {
  selectedEstablishmentId.value = undefined
  seedFile.value = null
  cscToken.value = ''
  syncDefaultEstablishment()
  load()
})

watch(() => props.establishments, () => syncDefaultEstablishment(), { deep: true })
</script>

<template>
  <div
    class="space-y-4"
    data-testid="outbound-capture-panel"
  >
    <UPageCard
      title="Captura de saídas (MA)"
      description="XML da NFC-e/NF-e + CSC. Simples."
      variant="subtle"
      :ui="{ body: 'space-y-4' }"
    >
      <UAlert
        v-if="lastError"
        color="error"
        variant="subtle"
        :title="lastError"
        icon="i-lucide-circle-x"
      />

      <div
        v-if="loading"
        class="text-sm text-muted"
      >
        Carregando…
      </div>

      <template v-else>
        <div
          v-if="maEstablishments.length === 0"
          class="text-sm text-muted"
        >
          Nenhum estabelecimento MA. Cadastre UF = MA no cadastro.
        </div>

        <template v-else>
          <!-- Resumo mínimo se já configurado -->
          <div
            v-if="profile65"
            class="flex flex-wrap items-center gap-2 text-sm"
            data-testid="outbound-profile-card"
          >
            <UBadge
              :color="profile65.status === 'ACTIVE' ? 'success' : 'neutral'"
              variant="subtle"
            >
              {{ profile65.status }}
            </UBadge>
            <span class="text-muted">
              Modelo {{ profile65.model }}
              · série {{ mainSeries?.series ?? '—' }}
              · nNF {{ mainSeries?.seed_nnf ?? '—' }}
              · CSC {{ profile65.csc?.configured ? `ok (id ${profile65.csc.csc_id})` : 'não' }}
            </span>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Estabelecimento">
              <USelect
                v-model="selectedEstablishmentId"
                :items="establishmentItems"
                value-key="value"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Ambiente">
              <USelect
                v-model="environment"
                :items="[
                  { label: 'Produção', value: 'production' },
                  { label: 'Homologação', value: 'homologation' }
                ]"
                value-key="value"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="XML da NFC-e / NF-e"
            name="seed_xml"
            required
            help="Arquivo .xml autorizado (procNFe)."
          >
            <input
              id="outbound-seed-xml"
              :key="seedFileInputKey"
              type="file"
              accept=".xml,application/xml,text/xml"
              class="block w-full rounded-md border border-default bg-default px-3 py-2.5 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-elevated file:px-3 file:py-1.5 file:text-sm file:font-medium"
              data-testid="outbound-seed-file"
              @change="onSeedFile"
            >
          </UFormField>
          <div
            v-if="seedFile"
            class="flex items-center gap-2 text-sm"
          >
            <span class="text-muted">{{ seedFile.name }}</span>
            <UButton
              size="xs"
              variant="ghost"
              color="neutral"
              label="Limpar"
              @click="clearSeedFile"
            />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="ID CSC"
              name="csc_id"
              help="Ex.: 1"
            >
              <UInput
                v-model="cscId"
                class="w-full"
                autocomplete="off"
                placeholder="1"
                data-testid="outbound-csc-id"
              />
            </UFormField>
            <UFormField
              label="CSC"
              name="csc"
              help="Valor exibido para ADMIN (salvo no cofre)."
            >
              <UInput
                v-model="cscToken"
                type="text"
                class="w-full font-mono text-sm"
                autocomplete="off"
                placeholder="Cole o CSC"
                data-testid="outbound-csc-token"
              />
            </UFormField>
          </div>

          <div class="flex justify-end">
            <UButton
              label="Salvar"
              icon="i-lucide-save"
              color="primary"
              :loading="submitting"
              :disabled="!canSave"
              data-testid="outbound-seed-submit"
              @click="saveAll"
            />
          </div>
        </template>
      </template>
    </UPageCard>
  </div>
</template>
