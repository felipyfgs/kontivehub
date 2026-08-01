<script setup lang="ts">
import type { FlowDetail } from '~/composables/useCommunicationFlowDetail'

defineProps<{
  detail: FlowDetail
}>()
</script>

<template>
  <section>
    <ShellSectionHeader
      title="Versões publicadas"
      description="Histórico imutável com digest e autoria quando disponível. Publicar não habilita bindings."
    />
    <ShellSectionCard>
      <UEmpty
        v-if="!detail.versions.value.length"
        icon="i-lucide-history"
        title="Nenhuma versão"
        description="Valide o draft e publique para criar a primeira versão."
        class="py-6"
      />
      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
        data-testid="communication-flow-versions"
      >
        <li
          v-for="version in detail.versions.value"
          :key="version.id"
          class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              v{{ version.version }}
            </p>
            <p class="break-all font-mono text-xs text-muted">
              {{ version.graph_digest }}
            </p>
            <p
              v-if="version.published_by_membership_id"
              class="mt-1 text-xs text-muted"
            >
              Publicada pelo membro #{{ version.published_by_membership_id }}
            </p>
          </div>
          <time
            class="text-xs text-muted"
            :datetime="version.published_at || undefined"
          >
            {{ detail.formatPublishedAt(version.published_at) }}
          </time>
        </li>
      </ul>
    </ShellSectionCard>
  </section>
</template>
