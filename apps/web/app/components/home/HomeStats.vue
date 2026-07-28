<script setup lang="ts">
import type { OperationsSummary } from '~/types/api'
import type { DashboardKpiItem } from '~/utils/kpi-ui'
import { homeDisplayValue } from '~/utils/home-cockpit'

const props = defineProps<{
  summary: OperationsSummary | null
  loading?: boolean
}>()

const items = computed((): DashboardKpiItem[] => {
  const loading = Boolean(props.loading)
  const hasSummary = !!props.summary
  const n = (v: number | undefined) => homeDisplayValue(loading, hasSummary, v)
  return [
    {
      key: 'sync_blocked',
      title: 'Cursores bloqueados',
      icon: 'i-lucide-triangle-alert',
      value: n(props.summary?.sync_blocked),
      to: '/syncs',
      critical: hasSummary && (props.summary?.sync_blocked ?? 0) > 0
    },
    {
      key: 'sync_failures_24h',
      title: 'Falhas (24h)',
      icon: 'i-lucide-circle-x',
      value: n(props.summary?.sync_failures_24h),
      to: '/syncs',
      critical: hasSummary && (props.summary?.sync_failures_24h ?? 0) > 0
    },
    {
      key: 'sync_due',
      title: 'Sincronizações vencidas',
      icon: 'i-lucide-clock-alert',
      value: n(props.summary?.sync_due),
      to: '/syncs'
    },
    {
      key: 'credentials_expiring',
      title: 'Certificados a vencer',
      icon: 'i-lucide-badge-alert',
      value: n(props.summary?.credentials_expiring_30d),
      to: '/clients'
    }
  ]
})
</script>

<template>
  <ShellKpiStrip
    test-id="home-stats"
    :items="items"
    :loading="loading"
    :columns="4"
  />
</template>
