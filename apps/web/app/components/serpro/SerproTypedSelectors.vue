<script setup lang="ts">
/**
 * Seletores tipados: ambiente, cliente, serviço e poder.
 */
import type { SerproSelectOption } from '~/utils/serpro-selectors'
import {
  SERPRO_ENVIRONMENT_OPTIONS,
  SERPRO_POWER_OPTIONS,
  SERPRO_SERVICE_OPTIONS
} from '~/utils/serpro-selectors'

const environment = defineModel<string | undefined>('environment', { default: undefined })
const clientId = defineModel<number | undefined>('clientId', { default: undefined })
const serviceCode = defineModel<string | undefined>('serviceCode', { default: undefined })
const powerCode = defineModel<string | undefined>('powerCode', { default: undefined })

const props = withDefaults(defineProps<{
  clientOptions?: SerproSelectOption<number>[]
  showEnvironment?: boolean
  showClient?: boolean
  showService?: boolean
  showPower?: boolean
  disabled?: boolean
}>(), {
  clientOptions: () => [],
  showEnvironment: true,
  showClient: true,
  showService: true,
  showPower: true,
  disabled: false
})

const envItems = computed(() => SERPRO_ENVIRONMENT_OPTIONS)
const serviceItems = computed(() => SERPRO_SERVICE_OPTIONS)
const powerItems = computed(() => SERPRO_POWER_OPTIONS)
const clientItems = computed(() => props.clientOptions)
</script>

<template>
  <div
    class="grid gap-3 sm:grid-cols-2"
    data-testid="serpro-typed-selectors"
  >
    <UFormField
      v-if="showEnvironment"
      label="Ambiente"
    >
      <USelect
        v-model="environment"
        :items="envItems"
        value-key="value"
        placeholder="Selecione o ambiente"
        class="w-full"
        :disabled="disabled"
        data-testid="serpro-select-environment"
      />
    </UFormField>

    <UFormField
      v-if="showClient"
      label="Cliente"
    >
      <USelect
        v-model="clientId"
        :items="clientItems"
        value-key="value"
        placeholder="Selecione o cliente"
        class="w-full"
        :disabled="disabled || !clientItems.length"
        data-testid="serpro-select-client"
      />
    </UFormField>

    <UFormField
      v-if="showService"
      label="Serviço"
    >
      <USelect
        v-model="serviceCode"
        :items="serviceItems"
        value-key="value"
        placeholder="Serviço Integra"
        class="w-full"
        :disabled="disabled"
        data-testid="serpro-select-service"
      />
    </UFormField>

    <UFormField
      v-if="showPower"
      label="Poder"
    >
      <USelect
        v-model="powerCode"
        :items="powerItems"
        value-key="value"
        placeholder="Poder / procuração"
        class="w-full"
        :disabled="disabled"
        data-testid="serpro-select-power"
      />
    </UFormField>
  </div>
</template>
