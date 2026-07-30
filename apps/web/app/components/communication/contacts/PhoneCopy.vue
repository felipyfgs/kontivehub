<script setup lang="ts">
const props = withDefaults(defineProps<{
  phone: string
  contactName?: string
  size?: 'xs' | 'sm'
}>(), {
  contactName: 'contato',
  size: 'xs'
})

const toast = useToast()

async function copy() {
  if (!navigator.clipboard) {
    toast.add({ title: 'Não foi possível copiar o telefone.', color: 'error' })
    return
  }

  try {
    await navigator.clipboard.writeText(props.phone)
    toast.add({ title: 'Telefone copiado.', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar o telefone.', color: 'error' })
  }
}
</script>

<template>
  <UButton
    color="neutral"
    variant="ghost"
    :size="size"
    square
    icon="i-lucide-copy"
    :aria-label="`Copiar telefone de ${contactName}`"
    @click="copy"
  />
</template>
