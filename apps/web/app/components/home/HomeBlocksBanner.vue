<script setup lang="ts">
import type { OperationsSummary } from '~/types/api'
import { homeBlocksBanner } from '~/utils/home-cockpit'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

const banner = computed(() => homeBlocksBanner(props.summary))
</script>

<template>
  <UAlert
    v-if="banner?.show"
    data-testid="home-blocks-banner"
    :color="banner.tone"
    variant="subtle"
    icon="i-lucide-shield-alert"
    :title="banner.title"
    :description="banner.description"
    :actions="[{
      label: 'Ver detalhes',
      to: banner.to,
      color: 'neutral',
      variant: 'ghost'
    }]"
  />
  <div
    v-else-if="loading && !summary"
    data-testid="home-blocks-banner-loading"
    class="h-16"
  >
    <USkeleton class="h-full w-full" />
  </div>
</template>
