<script setup lang="ts">
/**
 * Célula de identidade do cliente na carteira: razão social + CNPJ.
 * Com `cnpj` normalizado: máscara BR na tela e cópia dos dígitos no clique.
 * Sem `cnpj`: fallback ao masked legado (sem cópia de dígitos).
 */
import { formatCnpj, normalizeCnpj } from '~/utils/format'

const props = defineProps<{
  name?: string | null
  legalName?: string | null
  displayName?: string | null
  /** CNPJ normalizado (14 chars) — preferido para exibição/cópia. */
  cnpj?: string | null
  cnpjMasked?: string | null
  rootCnpjMasked?: string | null
  clientId?: number | null
  to?: string | null
}>()

const toast = useToast()

const primaryName = computed(() => {
  const n = props.displayName || props.name || props.legalName || ''
  return String(n).trim() || (props.clientId ? `Cliente #${props.clientId}` : '—')
})

const secondaryName = computed(() => {
  const legal = String(props.legalName || '').trim()
  if (!legal) return null
  if (legal === primaryName.value) return null
  return legal
})

const copyableDigits = computed(() => {
  const clean = normalizeCnpj(props.cnpj)
  return clean.length === 14 ? clean : null
})

const displayCnpj = computed(() => {
  if (copyableDigits.value) return formatCnpj(copyableDigits.value)
  return props.cnpjMasked || props.rootCnpjMasked || null
})

const href = computed(() => {
  if (props.to) return props.to
  if (props.clientId) return `/monitoring/clients/${props.clientId}`
  return null
})

async function onCopyCnpj(event: MouseEvent) {
  event.preventDefault()
  event.stopPropagation()
  const clean = copyableDigits.value
  if (!clean) return
  try {
    await navigator.clipboard.writeText(clean)
    toast.add({
      title: 'CNPJ copiado',
      description: clean,
      color: 'success'
    })
  } catch {
    toast.add({ title: 'Não foi possível copiar o CNPJ', color: 'error' })
  }
}
</script>

<template>
  <div
    class="w-full min-w-0 max-w-full overflow-hidden"
    data-testid="fiscal-client-cell"
  >
    <NuxtLink
      v-if="href"
      :to="href"
      class="block min-w-0 truncate font-medium text-highlighted hover:underline focus-visible:underline"
      :title="primaryName"
    >
      {{ primaryName }}
    </NuxtLink>
    <span
      v-else
      class="block min-w-0 truncate font-medium text-highlighted"
      :title="primaryName"
    >
      {{ primaryName }}
    </span>
    <span
      v-if="secondaryName"
      class="block min-w-0 truncate text-xs text-muted"
      :title="secondaryName"
    >
      {{ secondaryName }}
    </span>
    <button
      v-if="copyableDigits && displayCnpj"
      type="button"
      class="mt-0.5 block min-w-0 max-w-full truncate text-left font-mono text-xs tabular-nums text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      :title="`Copiar ${copyableDigits}`"
      data-testid="fiscal-client-cnpj"
      @click="onCopyCnpj"
    >
      {{ displayCnpj }}
    </button>
    <span
      v-else-if="displayCnpj"
      class="mt-0.5 block min-w-0 truncate font-mono text-xs tabular-nums text-muted"
      :title="displayCnpj"
      data-testid="fiscal-client-cnpj"
    >
      {{ displayCnpj }}
    </span>
  </div>
</template>
