<script setup lang="ts">
/**
 * Hub de atalhos settings — título + grid de cards com link.
 * Arquétipo: settings/members + cards subtle.
 */
import type { RouteLocationRaw } from 'vue-router'

export type SectionHubCard = {
  to: RouteLocationRaw
  label: string
  icon?: string
  description?: string
  testId?: string
}

withDefaults(defineProps<{
  title: string
  description?: string
  cards: SectionHubCard[]
  testId?: string
  crumbs?: Array<{ label: string, to?: RouteLocationRaw }>
}>(), {
  description: undefined,
  testId: undefined,
  crumbs: undefined
})
</script>

<template>
  <div
    class="min-w-0"
    :data-testid="testId"
  >
    <ShellSectionHeader
      :title="title"
      :description="description"
      :crumbs="crumbs"
    />

    <UEmpty
      v-if="!cards.length"
      icon="i-lucide-layout-grid"
      title="Nenhuma ferramenta para este perfil"
      description="Não há atalhos aplicáveis ao regime ou situação deste cliente."
      data-testid="section-hub-empty"
    />

    <div
      v-else
      class="grid gap-4 sm:grid-cols-2"
    >
      <UPageCard
        v-for="(card, index) in cards"
        :key="`${card.label}-${index}`"
        :to="card.to"
        :title="card.label"
        :description="card.description"
        :icon="card.icon"
        variant="subtle"
        class="cursor-pointer"
        :data-testid="card.testId"
      >
        <template #footer>
          <div class="flex items-center gap-1 text-sm text-primary">
            <span>Abrir</span>
            <UIcon
              name="i-lucide-arrow-right"
              class="size-4"
            />
          </div>
        </template>
      </UPageCard>
    </div>
  </div>
</template>
