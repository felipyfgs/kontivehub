<script setup lang="ts">
/**
 * Alias de migração: CT-e não é Configurações.
 * Redirect com replace para o catálogo filtrado, preservando só params aceitos.
 */
const ACCEPTED = new Set([
  'kind',
  'direction',
  'q',
  'client_id',
  'establishment_id',
  'fiscal_role',
  'acquisition_source',
  'artifact_quality',
  'coverage_status',
  'status',
  'competence',
  'issued_from',
  'issued_to',
  'missing_party_name',
  'issuer_cnpj',
  'taker_cnpj'
])

const route = useRoute()

const query: Record<string, string> = { kind: 'CTE' }
for (const [key, raw] of Object.entries(route.query)) {
  if (!ACCEPTED.has(key) || key === 'kind') continue
  const value = Array.isArray(raw) ? raw[0] : raw
  if (typeof value === 'string' && value) {
    query[key] = value
  }
}

await navigateTo({ path: '/docs/catalog', query }, { replace: true })
</script>

<template>
  <div class="sr-only">
    Redirecionando para o catálogo de documentos CT-e…
  </div>
</template>
