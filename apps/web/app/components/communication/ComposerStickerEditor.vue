<script setup lang="ts">
import type { CommunicationStickerCrop } from '~/utils/communication-sticker'
import { createCommunicationSticker } from '~/utils/communication-sticker'

const props = defineProps<{ maxBytes: number, maxDimension: number }>()
const emit = defineEmits<{ confirm: [file: File], cancel: [] }>()
const input = ref<HTMLInputElement | null>(null)
const source = ref<File | null>(null)
const previewUrl = ref<string | null>(null)
const imageWidth = ref(0)
const imageHeight = ref(0)
const cropSize = ref(0)
const cropX = ref(0)
const cropY = ref(0)
const error = ref<string | null>(null)
const loading = ref(false)

const maxCrop = computed(() => Math.max(1, Math.min(imageWidth.value, imageHeight.value) || 1))
const crop = computed((): CommunicationStickerCrop => ({
  x: cropX.value,
  y: cropY.value,
  width: cropSize.value,
  height: cropSize.value
}))

function clear() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = null
  source.value = null
  imageWidth.value = 0
  imageHeight.value = 0
  cropSize.value = 0
  cropX.value = 0
  cropY.value = 0
  error.value = null
  if (input.value) input.value.value = ''
}

function clampCrop() {
  const size = Math.min(Math.max(1, cropSize.value || maxCrop.value), maxCrop.value)
  cropSize.value = size
  cropX.value = Math.min(Math.max(0, cropX.value), Math.max(0, imageWidth.value - size))
  cropY.value = Math.min(Math.max(0, cropY.value), Math.max(0, imageHeight.value - size))
}

function onPreviewLoad(event: Event) {
  const image = event.target
  if (!(image instanceof HTMLImageElement)) return
  imageWidth.value = image.naturalWidth
  imageHeight.value = image.naturalHeight
  const size = Math.min(image.naturalWidth, image.naturalHeight)
  cropSize.value = size
  cropX.value = Math.max(0, Math.floor((image.naturalWidth - size) / 2))
  cropY.value = Math.max(0, Math.floor((image.naturalHeight - size) / 2))
}

function select(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  clear()
  if (!file) return
  if (!file.type.startsWith('image/')) {
    error.value = 'Selecione uma imagem para criar a figurinha.'
    return
  }
  source.value = file
  previewUrl.value = URL.createObjectURL(file)
}

async function confirm() {
  if (!source.value || !cropSize.value) return
  clampCrop()
  loading.value = true
  error.value = null
  try {
    const file = await createCommunicationSticker(source.value, {
      crop: crop.value,
      maxBytes: props.maxBytes,
      maxDimension: props.maxDimension
    })
    clear()
    emit('confirm', file)
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Não foi possível criar a figurinha.'
  } finally {
    loading.value = false
  }
}

function cancel() {
  clear()
  emit('cancel')
}

watch([cropSize, cropX, cropY], clampCrop)
onBeforeUnmount(clear)
defineExpose({ clear })
</script>

<template>
  <section aria-label="Criar figurinha" class="space-y-3">
    <UInput
      ref="input"
      type="file"
      accept="image/*"
      aria-label="Imagem da figurinha"
      @change="select"
    />
    <div
      v-if="previewUrl"
      class="relative mx-auto max-w-sm overflow-hidden rounded-lg bg-elevated"
    >
      <img
        :src="previewUrl"
        alt="Prévia da figurinha"
        class="mx-auto max-h-56 w-full object-contain"
        @load="onPreviewLoad"
      >
      <div
        v-if="imageWidth && imageHeight && cropSize"
        class="pointer-events-none absolute inset-0"
        aria-hidden="true"
      >
        <div
          class="absolute border-2 border-primary bg-primary/10"
          :style="{
            left: `${(cropX / imageWidth) * 100}%`,
            top: `${(cropY / imageHeight) * 100}%`,
            width: `${(cropSize / imageWidth) * 100}%`,
            height: `${(cropSize / imageHeight) * 100}%`
          }"
        />
      </div>
    </div>
    <div
      v-if="source && imageWidth"
      class="grid gap-3 sm:grid-cols-3"
    >
      <UFormField label="Tamanho do recorte">
        <input
          v-model.number="cropSize"
          type="range"
          class="min-h-11 w-full accent-primary"
          :min="Math.max(1, Math.floor(maxCrop * 0.25))"
          :max="maxCrop"
          :aria-valuemin="Math.max(1, Math.floor(maxCrop * 0.25))"
          :aria-valuemax="maxCrop"
          :aria-valuenow="cropSize"
          aria-label="Tamanho do recorte da figurinha"
        >
      </UFormField>
      <UFormField label="Deslocamento horizontal">
        <input
          v-model.number="cropX"
          type="range"
          class="min-h-11 w-full accent-primary"
          :min="0"
          :max="Math.max(0, imageWidth - cropSize)"
          aria-label="Deslocamento horizontal do recorte"
        >
      </UFormField>
      <UFormField label="Deslocamento vertical">
        <input
          v-model.number="cropY"
          type="range"
          class="min-h-11 w-full accent-primary"
          :min="0"
          :max="Math.max(0, imageHeight - cropSize)"
          aria-label="Deslocamento vertical do recorte"
        >
      </UFormField>
    </div>
    <p v-if="error" role="alert" class="text-sm text-error">
      {{ error }}
    </p>
    <div class="flex justify-end gap-2">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="ghost"
        class="min-h-11"
        @click="cancel"
      />
      <UButton
        label="Criar figurinha"
        color="primary"
        :loading="loading"
        :disabled="!source || !cropSize"
        class="min-h-11"
        @click="confirm"
      />
    </div>
  </section>
</template>
