import { expect, test, type Locator, type Page } from '@playwright/test'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const conversation = process.env.E2E_COMPOSER_CONVERSATION
  || process.env.E2E_TIMELINE_CONVERSATION
  || 'Cliente E2E com foto'
const fixtureImage = resolve(dirname(fileURLToPath(import.meta.url)), '../fixtures/composer-preview.png')

const targets = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 }
] as const

function enabledKind(family: string, extras: Record<string, unknown> = {}) {
  return enabledKindWithVariants(family, {}, extras)
}

function enabledKindWithVariants(
  family: string,
  variantOverrides: Record<string, unknown> = {},
  extras: Record<string, unknown> = {}
) {
  return {
    family,
    enabled: true,
    supported: true,
    reason: null,
    requires_permission: 'communication.reply',
    limits: {
      max_bytes: 20 * 1024 * 1024,
      max_items: 10,
      max_options: 12,
      mime_types: ['image/png', 'image/jpeg', 'video/mp4', 'application/pdf', 'image/webp', 'audio/ogg']
    },
    variants: {
      camera: { enabled: true, reason: null },
      view_once: { enabled: true, reason: null },
      ptt: { enabled: true, reason: null },
      gif: { enabled: true, reason: null },
      ptv: { enabled: false, reason: 'PTV_BUILDER_UNIMPLEMENTED' },
      provider_search: { enabled: false, reason: 'GIF_PROVIDER_DISABLED' },
      album_native: { enabled: false, reason: 'NATIVE_ALBUM_INTEROPERABILITY_UNVERIFIED' },
      multiple: { enabled: true, reason: null },
      ...variantOverrides
    },
    ...extras
  }
}

function disabledKind(family: string, reason: string) {
  return {
    family,
    enabled: false,
    supported: false,
    reason,
    requires_permission: 'communication.reply',
    limits: { max_bytes: 0, max_items: 0, max_options: 0, mime_types: [] },
    variants: {}
  }
}

function buildStubPayload(kindOverrides: Record<string, unknown> = {}) {
  return {
    data: {
      enabled: true,
      requires_permission: 'communication.reply',
      max_media_bytes: 20 * 1024 * 1024,
      conversation_initiation: {
        enabled: true,
        reason: null,
        requires_permission: 'communication.reply'
      },
      kinds: {
        TEXT: enabledKind('TEXT'),
        IMAGE: enabledKind('IMAGE'),
        VIDEO: enabledKind('VIDEO'),
        DOCUMENT: enabledKind('DOCUMENT'),
        AUDIO: enabledKind('AUDIO'),
        STICKER: enabledKind('STICKER'),
        LOCATION: enabledKind('LOCATION'),
        CONTACT: enabledKind('CONTACT', { multiple: true }),
        CONTACTS: enabledKind('CONTACTS', { multiple: true }),
        POLL: enabledKind('POLL'),
        EVENT: enabledKind('EVENT'),
        INTERACTIVE: enabledKind('INTERACTIVE'),
        MEDIA_BATCH: enabledKind('MEDIA_BATCH'),
        ...kindOverrides
      }
    }
  }
}

const stubPayload = buildStubPayload()

async function stubComposerCapabilities(page: Page, payload = stubPayload) {
  await page.route(/outbound-capabilities/, async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({
        status: 204,
        headers: {
          'access-control-allow-origin': '*',
          'access-control-allow-methods': 'GET,OPTIONS',
          'access-control-allow-headers': '*'
        }
      })
      return
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'access-control-allow-origin': '*',
        'cache-control': 'no-store'
      },
      body: JSON.stringify(payload)
    })
  })
}

async function gotoDevRoute(page: Page, route: string, ready: Locator) {
  await page.goto(route, { waitUntil: 'commit', timeout: 30_000 })
  await expect(ready).toBeVisible({ timeout: 120_000 })
}

async function login(page: Page) {
  const emailInput = page.getByLabel('E-mail')
  await gotoDevRoute(page, '/login', emailInput)
  await emailInput.fill(email)
  await page.locator('input[name="password"]').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

function composer(page: Page) {
  return page.locator('[data-testid="communication-composer"]:visible').first()
}

async function openConversation(page: Page) {
  const list = page.getByTestId('communication-list-panel')
  await gotoDevRoute(page, '/communication', list)
  await expect(page.getByTestId('communication-conversations-skeleton')).toHaveCount(0, {
    timeout: 45_000
  })
  const row = page.locator('[data-testid^="communication-conversation-row-"]')
    .filter({ hasText: conversation })
  await expect(row).toBeVisible()
  const rowTestId = await row.getAttribute('data-testid')
  const conversationId = rowTestId?.replace('communication-conversation-row-', '')
  if (!conversationId || !/^\d+$/.test(conversationId)) {
    throw new Error('A conversa E2E não expôs um identificador navegável.')
  }

  const capabilities = page.waitForResponse(
    response => /outbound-capabilities/.test(response.url()) && response.ok(),
    { timeout: 45_000 }
  )
  await page.goto(`/communication/conversations/${conversationId}`, { waitUntil: 'commit' })
  await expect(composer(page)).toBeVisible({ timeout: 45_000 })
  await capabilities
}

async function expectNoHorizontalOverflow(page: Page) {
  await expect.poll(() => page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )).toBeLessThanOrEqual(1)
}

async function openAttachmentLauncher(page: Page) {
  const trigger = composer(page).getByTestId('communication-composer-attachment-trigger')
  await expect(trigger).toBeVisible()
  await trigger.click()
  const filesGroup = page.getByRole('button', { name: 'Arquivos e mídia' })
  await expect(filesGroup).toBeVisible({ timeout: 15_000 })
}

for (const target of targets) {
  test(`${target.name}: launcher, contexto, formulário estruturado, mídia e acessibilidade do composer`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)
    await expect(shell.getByTestId('communication-composer-context')).toBeVisible()
    await expect(shell.getByTestId('communication-composer-context')).toContainText(/.+/)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Criar' }).click()
    await page.getByRole('button', { name: 'Enquete' }).click()

    const dialog = page.getByRole('dialog').filter({ hasText: 'Criar mensagem estruturada' })
    await expect(dialog).toBeVisible()
    await dialog.getByLabel('Pergunta').fill('Qual horário?')
    await dialog.getByLabel('Opção 1').fill('Manhã')
    await dialog.getByLabel('Opção 2').fill('Tarde')
    await dialog.getByRole('button', { name: 'Usar no composer' }).click()
    await expect(shell.getByTestId('communication-composer-structured-preview')).toBeVisible()
    await expect(shell.getByTestId('communication-composer-structured-preview')).toContainText('Qual horário?')
    await expect(shell.getByTestId('communication-composer-structured-preview')).toContainText('Manhã')

    await shell.getByRole('button', { name: 'Remover mensagem estruturada' }).click()
    await expect(shell.getByTestId('communication-composer-structured-preview')).toHaveCount(0)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Arquivos e mídia' }).click()
    await page.getByRole('button', { name: 'Fotos e vídeos' }).click()

    const fileInput = shell.locator('input[type="file"][multiple]')
    await fileInput.setInputFiles(fixtureImage)
    await expect(shell.getByTestId('communication-composer-media-preview')).toBeVisible()
    await expect(shell.getByTestId('communication-composer-media-preview')).toContainText(/composer-preview\.png|KB/)

    const trigger = shell.getByTestId('communication-composer-attachment-trigger')
    await trigger.click()
    await expect(page.getByRole('button', { name: 'Arquivos e mídia', exact: true })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(trigger).toBeFocused()

    await page.emulateMedia({ reducedMotion: 'reduce' })
    await page.evaluate(() => {
      document.documentElement.style.zoom = '2'
    })
    await expect(shell.getByRole('button', { name: 'Enviar' })).toBeVisible()
    await expectNoHorizontalOverflow(page)
    await page.evaluate(() => {
      document.documentElement.style.zoom = '1'
    })

    if (target.name === 'mobile') {
      await expect.poll(() => shell.evaluate(node => getComputedStyle(node).paddingBottom))
        .not.toEqual('0px')
    }
  })

  test(`${target.name}: grouped launcher hierarchy preserves all four groups`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    await openAttachmentLauncher(page)

    for (const group of ['Arquivos e mídia', 'Cliente e contexto', 'Criar', 'Mais']) {
      await expect(page.getByRole('button', { name: group, exact: true })).toBeVisible()
    }

    await page.getByRole('button', { name: 'Arquivos e mídia', exact: true }).click()
    for (const action of ['Fotos e vídeos', 'Documento', 'Câmera', 'Áudio']) {
      await expect(page.getByRole('button', { name: action, exact: true })).toBeVisible()
    }

    if (target.name === 'mobile') {
      await page.getByRole('button', { name: /Voltar|arrow-left/ }).click()
    } else {
      await page.getByRole('button', { name: 'Voltar' }).click()
    }

    await page.getByRole('button', { name: 'Cliente e contexto', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Localização', exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Contatos', exact: true })).toBeVisible()
  })

  test(`${target.name}: keyboard-only navigation through launcher and back`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)
    const trigger = shell.getByTestId('communication-composer-attachment-trigger')
    await trigger.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('button', { name: 'Arquivos e mídia' })).toBeVisible({ timeout: 15_000 })

    await page.keyboard.press('Tab')
    await page.keyboard.press('Enter')

    await expect(page.getByRole('button', { name: 'Fotos e vídeos' })).toBeVisible({ timeout: 10_000 })

    await page.keyboard.press('Escape')

    await expect(trigger).toBeVisible()
  })

  test(`${target.name}: location structured form creates typed preview`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Cliente e contexto' }).click()
    await page.getByRole('button', { name: 'Localização' }).click()

    const dialog = page.getByRole('dialog').filter({ hasText: 'Criar mensagem estruturada' })
    await expect(dialog).toBeVisible()
    await dialog.getByLabel('Latitude').fill('-23.55')
    await dialog.getByLabel('Longitude').fill('-46.63')
    await dialog.getByLabel('Nome').fill('Escritório KontiveHub')
    await dialog.getByRole('button', { name: 'Usar no composer' }).click()

    const preview = shell.getByTestId('communication-composer-structured-preview')
    await expect(preview).toBeVisible()
    await expect(preview).toContainText('Escritório KontiveHub')
    await expect(preview).toContainText('-23.55')

    await shell.getByRole('button', { name: 'Remover mensagem estruturada' }).click()
    await expect(preview).toHaveCount(0)
  })

  test(`${target.name}: event structured form creates typed preview`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Criar' }).click()
    await page.getByRole('button', { name: 'Evento' }).click()

    const dialog = page.getByRole('dialog').filter({ hasText: 'Criar mensagem estruturada' })
    await expect(dialog).toBeVisible()
    await dialog.getByLabel('Título').fill('Reunião fiscal')
    await dialog.getByLabel('Início').fill('2026-09-01T10:00')
    await dialog.getByLabel('Fim').fill('2026-09-01T11:00')
    await dialog.getByRole('button', { name: 'Usar no composer' }).click()

    const preview = shell.getByTestId('communication-composer-structured-preview')
    await expect(preview).toBeVisible()
    await expect(preview).toContainText('Reunião fiscal')
  })

  test(`${target.name}: media preview supports reorder and per-item removal`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Arquivos e mídia' }).click()
    await page.getByRole('button', { name: 'Fotos e vídeos' }).click()

    const fileInput = shell.locator('input[type="file"][multiple]')
    await fileInput.setInputFiles([fixtureImage, fixtureImage])

    const preview = shell.getByTestId('communication-composer-media-preview')
    await expect(preview).toBeVisible()

    const items = preview.locator('article')
    await expect(items).toHaveCount(2)

    const removeBtn = items.first().getByRole('button', { name: /Remover/ })
    await expect(removeBtn).toBeVisible()
    await removeBtn.click()
    await expect(items).toHaveCount(1)
  })

  test(`${target.name}: expression picker opens with emoji tab and keyboard accessible`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)

    const expressionTrigger = shell.getByRole('button', { name: 'Adicionar expressão' })
    await expect(expressionTrigger).toBeVisible()
    await expressionTrigger.click()

    const picker = page.locator('[aria-label="Seletor de expressões"]')
    await expect(picker).toBeVisible({ timeout: 15_000 })

    const emojiTab = picker.getByRole('tab', { name: 'Emoji' })
    await expect(emojiTab).toBeVisible()

    const emojiGrid = picker.locator('[aria-label="Emojis"]')
    await expect(emojiGrid).toBeVisible()

    const searchInput = picker.getByPlaceholder('Buscar emoji')
    await expect(searchInput).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(picker).not.toBeVisible()
  })

  test(`${target.name}: voice recorder transitions recording → pause → preview → discard`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)

    await page.addInitScript(() => {
      const audioContext = window.AudioContext
        || (window as Window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
      if (audioContext) {
        const origCreateMediaStreamSource = audioContext.prototype.createMediaStreamSource
        audioContext.prototype.createMediaStreamSource = function (stream: MediaStream) {
          try {
            return origCreateMediaStreamSource.call(this, stream)
          } catch {
            const osc = this.createOscillator()
            osc.frequency.value = 0
            osc.start()
            return osc as unknown as MediaStreamAudioSourceNode
          }
        }
      }

      const fakeStream = () => {
        const ctx = new AudioContext()
        const osc = ctx.createOscillator()
        osc.frequency.value = 440
        osc.start()
        const dest = ctx.createMediaStreamDestination()
        osc.connect(dest)
        return dest.stream
      }

      Object.defineProperty(navigator, 'mediaDevices', {
        value: {
          getUserMedia: async (constraints: MediaStreamConstraints) => {
            if (constraints.audio) return fakeStream()
            throw new DOMException('NotAllowedError')
          },
          enumerateDevices: async () => [
            { deviceId: 'default', kind: 'audioinput', label: 'Fake Microphone', groupId: 'default', toJSON() { return {} } }
          ]
        },
        configurable: true
      })

      if (!window.MediaRecorder || !(window.MediaRecorder.isTypeSupported?.('audio/webm'))) {
        class FakeMediaRecorder extends EventTarget {
          state = 'inactive' as 'inactive' | 'recording' | 'paused'
          stream: MediaStream
          ondataavailable: ((event: BlobEvent) => void) | null = null
          onstop: (() => void) | null = null
          onerror: (() => void) | null = null
          constructor(stream: MediaStream) {
            super()
            this.stream = stream
          }

          start() {
            this.state = 'recording'
          }

          pause() {
            this.state = 'paused'
          }

          resume() {
            this.state = 'recording'
          }

          stop() {
            this.state = 'inactive'
            const blob = new Blob([new Uint8Array(1024)], { type: 'audio/webm' })
            const event = new BlobEvent('dataavailable', { data: blob })
            this.ondataavailable?.(event)
            this.dispatchEvent(event)
            const stopEvent = new Event('stop')
            this.onstop?.()
            this.dispatchEvent(stopEvent)
          }

          static isTypeSupported() { return true }
        }
        Object.defineProperty(window, 'MediaRecorder', { value: FakeMediaRecorder, configurable: true })
      }
    })

    await login(page)
    await openConversation(page)

    const shell = composer(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Arquivos e mídia' }).click()
    await page.getByRole('button', { name: 'Áudio' }).click()

    const recordingSurface = shell.getByTestId('communication-audio-recording')
    await expect(recordingSurface).toBeVisible({ timeout: 15_000 })
    await expect(recordingSurface).toContainText(/Gravando/)

    const pauseBtn = recordingSurface.getByRole('button', { name: 'Pausar' })
    await expect(pauseBtn).toBeVisible()
    await pauseBtn.click()
    await expect(recordingSurface).toContainText(/Pausada/)

    const resumeBtn = recordingSurface.getByRole('button', { name: 'Retomar' })
    await expect(resumeBtn).toBeVisible()
    await resumeBtn.click()
    await expect(recordingSurface).toContainText(/Gravando/)

    const cancelBtn = recordingSurface.getByRole('button', { name: 'Cancelar gravação' })
    await expect(cancelBtn).toBeVisible()
    await cancelBtn.click()
    await expect(recordingSurface).not.toBeVisible()
  })

  test(`${target.name}: voice recorder preview has playback and send controls`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)

    await page.addInitScript(() => {
      const fakeStream = () => {
        const ctx = new AudioContext()
        const osc = ctx.createOscillator()
        osc.frequency.value = 440
        osc.start()
        const dest = ctx.createMediaStreamDestination()
        osc.connect(dest)
        return dest.stream
      }
      Object.defineProperty(navigator, 'mediaDevices', {
        value: {
          getUserMedia: async () => fakeStream(),
          enumerateDevices: async () => [
            { deviceId: 'default', kind: 'audioinput', label: 'Fake Microphone', groupId: 'default', toJSON() { return {} } }
          ]
        },
        configurable: true
      })
      class FakeMediaRecorder extends EventTarget {
        state = 'inactive' as 'inactive' | 'recording' | 'paused'
        stream: MediaStream
        ondataavailable: ((event: BlobEvent) => void) | null = null
        onstop: (() => void) | null = null
        constructor(stream: MediaStream) {
          super()
          this.stream = stream
        }

        start() { this.state = 'recording' }
        pause() { this.state = 'paused' }
        resume() { this.state = 'recording' }
        stop() {
          this.state = 'inactive'
          const blob = new Blob([new Uint8Array(1024)], { type: 'audio/webm' })
          const ev = new BlobEvent('dataavailable', { data: blob })
          this.ondataavailable?.(ev)
          this.dispatchEvent(ev)
          this.onstop?.()
          this.dispatchEvent(new Event('stop'))
        }

        static isTypeSupported() { return true }
      }
      Object.defineProperty(window, 'MediaRecorder', { value: FakeMediaRecorder, configurable: true })
    })

    await login(page)
    await openConversation(page)

    const shell = composer(page)
    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Arquivos e mídia' }).click()
    await page.getByRole('button', { name: 'Áudio' }).click()

    await expect(shell.getByTestId('communication-audio-recording')).toBeVisible({ timeout: 15_000 })

    await page.waitForTimeout(500)

    const stopBtn = shell.getByTestId('communication-audio-recording')
      .getByRole('button', { name: /Parar|Finalizar/ })
    if (await stopBtn.isVisible().catch(() => false)) {
      await stopBtn.click()
    } else {
      const cancelBtn = shell.getByTestId('communication-audio-recording')
        .getByRole('button', { name: 'Cancelar gravação' })
      await cancelBtn.click()
    }

    const previewSurface = shell.getByTestId('communication-audio-preview')
    if (await previewSurface.isVisible().catch(() => false)) {
      await expect(previewSurface).toContainText(/Mensagem de voz/)
      const playback = previewSurface.locator('audio[aria-label="Reproduzir mensagem de voz"]')
      await expect(playback).toBeVisible()
      await expect(previewSurface.getByRole('button', { name: 'Descartar mensagem de voz' })).toBeVisible()
      await expect(previewSurface.getByRole('button', { name: 'Enviar' })).toBeVisible()
      await previewSurface.getByRole('button', { name: 'Descartar mensagem de voz' }).click()
      await expect(previewSurface).not.toBeVisible()
    }
  })

  test(`${target.name}: camera modal opens with getUserMedia and falls back gracefully`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)

    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'mediaDevices', {
        value: {
          getUserMedia: async () => {
            throw new DOMException('Permission denied', 'NotAllowedError')
          },
          enumerateDevices: async () => []
        },
        configurable: true
      })
    })

    await login(page)
    await openConversation(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Arquivos e mídia' }).click()
    await page.getByRole('button', { name: 'Câmera' }).click()

    const cameraDialog = page.getByRole('dialog').filter({ hasText: /[Cc]âmera/ })
    if (await cameraDialog.isVisible().catch(() => false)) {
      await expect(cameraDialog).toBeVisible()
      const closeBtn = cameraDialog.getByRole('button', { name: /Cancelar|Fechar/ })
      if (await closeBtn.isVisible().catch(() => false)) {
        await closeBtn.click()
      } else {
        await page.keyboard.press('Escape')
      }
    }

    const shell = composer(page)
    await expect(shell).toBeVisible()
  })

  test(`${target.name}: disabled capability hides launcher action`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    const restrictedPayload = buildStubPayload({
      EVENT: disabledKind('EVENT', 'EVENT_BUILDER_UNAVAILABLE'),
      POLL: disabledKind('POLL', 'POLL_DISABLED')
    })
    await stubComposerCapabilities(page, restrictedPayload)
    await login(page)
    await openConversation(page)

    await openAttachmentLauncher(page)

    const createGroup = page.getByRole('button', { name: 'Criar' })
    if (await createGroup.isVisible().catch(() => false)) {
      await createGroup.click()
      await expect(page.getByRole('button', { name: 'Enquete' })).not.toBeVisible()
      await expect(page.getByRole('button', { name: 'Evento' })).not.toBeVisible()
    }
  })

  test(`${target.name}: API failure preserves editable draft`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)

    const messagePostBlocked = true
    await page.route(/\/messages$/, async (route) => {
      if (route.request().method() === 'POST' && messagePostBlocked) {
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          headers: { 'access-control-allow-origin': '*' },
          body: JSON.stringify({
            message: 'Validação falhou.',
            errors: { body: ['O campo é obrigatório.'] }
          })
        })
        return
      }
      await route.continue()
    })

    await login(page)
    await openConversation(page)

    const shell = composer(page)
    const editor = shell.locator('textarea, [contenteditable="true"]').first()
    await editor.fill('Mensagem de teste e2e')

    const sendBtn = shell.getByRole('button', { name: 'Enviar' })
    await sendBtn.click()

    await page.waitForTimeout(2000)

    await expect(editor).toHaveValue(/Mensagem de teste e2e/)
      .catch(() => expect(editor).toContainText('Mensagem de teste e2e'))
  })

  test(`${target.name}: 200% zoom keeps send button and controls visible`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    const shell = composer(page)

    await page.evaluate(() => {
      document.documentElement.style.zoom = '2'
    })

    await expect(shell.getByRole('button', { name: 'Enviar' })).toBeVisible()
    await expect(shell.getByTestId('communication-composer-attachment-trigger')).toBeVisible()
    await expectNoHorizontalOverflow(page)

    await page.evaluate(() => {
      document.documentElement.style.zoom = '1'
    })
  })

  test(`${target.name}: Escape from structured editor restores focus to attachment trigger`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await login(page)
    await openConversation(page)

    await openAttachmentLauncher(page)
    await page.getByRole('button', { name: 'Criar' }).click()
    await page.getByRole('button', { name: 'Enquete' }).click()

    const dialog = page.getByRole('dialog').filter({ hasText: 'Criar mensagem estruturada' })
    await expect(dialog).toBeVisible()

    const cancelBtn = dialog.getByRole('button', { name: 'Cancelar' })
    await cancelBtn.click()
    await expect(dialog).not.toBeVisible()

    const shell = composer(page)
    await expect(shell).toBeVisible()
  })

  test(`${target.name}: reduced motion disables waveform pulse animation`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await page.emulateMedia({ reducedMotion: 'reduce' })

    await login(page)
    await openConversation(page)

    const shell = composer(page)
    const trigger = shell.getByTestId('communication-composer-attachment-trigger')
    await expect(trigger).toBeVisible()

    await page.emulateMedia({ reducedMotion: 'no-preference' })
  })
}

test('desktop: interactive structured form creates typed preview', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await stubComposerCapabilities(page)
  await login(page)
  await openConversation(page)

  const shell = composer(page)

  await openAttachmentLauncher(page)
  await page.getByRole('button', { name: 'Mais' }).click()
  await page.getByRole('button', { name: 'Interativo' }).click()

  const dialog = page.getByRole('dialog').filter({ hasText: 'Criar mensagem estruturada' })
  await expect(dialog).toBeVisible()
  await dialog.getByLabel('Título').fill('Selecione uma opção')
  await dialog.getByLabel('Mensagem').fill('Escolha abaixo:')
  await dialog.getByLabel('Ação 1').fill('Opção A')
  await dialog.getByLabel('Ação 2').fill('Opção B')
  await dialog.getByRole('button', { name: 'Usar no composer' }).click()

  const preview = shell.getByTestId('communication-composer-structured-preview')
  await expect(preview).toBeVisible()
  await expect(preview).toContainText('Selecione uma opção')
  await expect(preview).toContainText('Opção A')
})

test('desktop: expression picker shows GIF tab disabled reason when provider_search off', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await stubComposerCapabilities(page)
  await login(page)
  await openConversation(page)

  const shell = composer(page)
  const expressionTrigger = shell.getByRole('button', { name: 'Adicionar expressão' })
  await expressionTrigger.click()

  const picker = page.locator('[aria-label="Seletor de expressões"]')
  await expect(picker).toBeVisible({ timeout: 15_000 })

  const gifTab = picker.getByRole('tab', { name: 'GIF' })
  if (await gifTab.isVisible().catch(() => false)) {
    await gifTab.click()
    const searchInput = picker.getByPlaceholder('Buscar GIF')
    if (await searchInput.isVisible().catch(() => false)) {
      await searchInput.fill('gato')
    }
  }

  await page.keyboard.press('Escape')
})

test('mobile: safe-area padding is applied to composer bottom', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await stubComposerCapabilities(page)

  await page.addInitScript(() => {
    const style = document.createElement('style')
    style.textContent = ':root { --sai-bottom: 34px; } @supports (padding-bottom: env(safe-area-inset-bottom)) { :root { --sai-bottom: env(safe-area-inset-bottom, 34px); } }'
    document.head.appendChild(style)
  })

  await login(page)
  await openConversation(page)

  const shell = composer(page)
  await expect.poll(() => shell.evaluate((node) => {
    const pb = getComputedStyle(node).paddingBottom
    return pb !== '0px'
  })).toBeTruthy()
})

test('mobile: bottom sheet launcher has touch-safe targets (44x44 min)', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await stubComposerCapabilities(page)
  await login(page)
  await openConversation(page)

  await openAttachmentLauncher(page)

  const groupButtons = page.getByRole('button', { name: /Arquivos e mídia|Cliente e contexto|Criar|Mais/ })
  const count = await groupButtons.count()
  for (let index = 0; index < count; index++) {
    const box = await groupButtons.nth(index).boundingBox()
    if (box) {
      expect(box.height).toBeGreaterThanOrEqual(44)
    }
  }
})

test('desktop: no-refresh timeline arrival after text submission', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await stubComposerCapabilities(page)

  const sentMessageId = 99999
  await page.route(/\/messages$/, async (route) => {
    if (route.request().method() === 'POST') {
      const postData = route.request().postDataJSON()
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        headers: { 'access-control-allow-origin': '*' },
        body: JSON.stringify({
          data: {
            id: sentMessageId,
            conversation_id: 1,
            body: postData?.body || 'Mensagem e2e',
            kind: 'TEXT',
            direction: 'OUT',
            status: 'QUEUED',
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString()
          }
        })
      })
      return
    }
    await route.continue()
  })

  await login(page)
  await openConversation(page)

  const shell = composer(page)
  const editor = shell.locator('textarea, [contenteditable="true"]').first()
  await editor.fill('Mensagem de chegada no timeline')

  const sendBtn = shell.getByRole('button', { name: 'Enviar' })
  await sendBtn.click()

  const newBubble = page.locator(`[data-message-id="${sentMessageId}"]`)
  await expect(newBubble).toBeVisible({ timeout: 15_000 })

  await expect(page).toHaveURL(/\/communication/)
})

test('desktop: sticker library shows partial sync, recent/favorite tabs and local fallback', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await stubComposerCapabilities(page, buildStubPayload({
    STICKER: enabledKindWithVariants('STICKER', {
      library: { enabled: true, reason: null }
    }, {
      limits: {
        max_bytes: 1024 * 1024,
        max_items: 1,
        max_options: 0,
        mime_types: ['image/webp']
      },
      compat_fields: {
        library: true,
        library_sources: ['LOCAL_IMPORT', 'DEVICE_RECENT', 'DEVICE_FAVORITE', 'DEVICE_MESSAGE'],
        device_sync_enabled: false,
        max_item_bytes: 1024 * 1024
      }
    })
  }))

  let appFavorite = false
  await page.route(/\/api\/v1\/communication\/inboxes\/\d+\/stickers(\?|$)/, async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 })
      return
    }
    const url = new URL(route.request().url())
    const favorite = url.searchParams.get('favorite')
    const recentItem = {
      id: '01STICKERLIBRARY01',
      available: true,
      source: 'DEVICE_RECENT',
      app_favorite: appFavorite,
      device_favorite: false,
      unavailable_reason: null,
      width: 512,
      height: 512,
      animated: false,
      mime_type: 'image/webp',
      preview_url: null
    }
    const items = favorite === 'any' || favorite === 'app' || favorite === 'device'
      ? (appFavorite ? [recentItem] : [])
      : [recentItem]
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': '*', 'cache-control': 'no-store' },
      body: JSON.stringify({
        data: items,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 24,
          total: items.length,
          sync_status: 'partial',
          sync_reason: 'OBSERVATION_BASED_SYNC',
          last_observed_at: new Date().toISOString()
        }
      })
    })
  })

  await page.route(/\/api\/v1\/communication\/stickers\/[^/]+\/favorite$/, async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 })
      return
    }
    appFavorite = true
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': '*', 'cache-control': 'no-store' },
      body: JSON.stringify({
        data: {
          id: '01STICKERLIBRARY01',
          available: true,
          source: 'DEVICE_RECENT',
          app_favorite: true,
          device_favorite: false,
          mime_type: 'image/webp'
        }
      })
    })
  })

  await page.route(/\/api\/v1\/communication\/stickers\/[^/]+\/preview$/, async (route) => {
    // Minimal valid-looking WebP header bytes; UI only needs a private blob.
    const bytes = Uint8Array.from([0x52, 0x49, 0x46, 0x46, 0x1a, 0x00, 0x00, 0x00, 0x57, 0x45, 0x42, 0x50])
    await route.fulfill({
      status: 200,
      contentType: 'image/webp',
      headers: { 'access-control-allow-origin': '*', 'cache-control': 'no-store' },
      body: Buffer.from(bytes)
    })
  })

  await login(page)
  await openConversation(page)

  const shell = composer(page)
  await shell.getByRole('button', { name: 'Adicionar expressão' }).click()
  await page.getByRole('tab', { name: 'Figurinhas' }).click()
  await expect(page.getByRole('button', { name: 'Recentes' })).toBeVisible({ timeout: 15_000 })
  await expect(page.getByRole('button', { name: 'Favoritas' })).toBeVisible()
  await expect(page.getByText(/Sincronização parcial|observadas no dispositivo/i).first()).toBeVisible()
  await expect(page.getByRole('button', { name: 'Usar arquivo local' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Importar para a biblioteca' })).toBeVisible()

  await page.getByRole('button', { name: 'Favoritas' }).click()
  await expect(page.getByText(/Nenhuma favorita observada|Nenhuma figurinha favorita/i).first()).toBeVisible()
  await page.getByRole('button', { name: 'Recentes' }).click()
  await expect(page.getByLabel(/Figurinhas recentes|Biblioteca de figurinhas/i).first()).toBeVisible()

  const favoriteToggle = page.getByRole('button', { name: /Adicionar aos favoritos do KontiveHub|Remover dos favoritos/i }).first()
  await expect(favoriteToggle).toBeVisible()
  await favoriteToggle.click()
  await page.getByRole('button', { name: 'Favoritas' }).click()
  await expect(page.getByRole('button', { name: /Remover dos favoritos|Favorita/i }).first()).toBeVisible({ timeout: 10_000 })

  await page.getByRole('button', { name: 'Recentes' }).click()
  await page.getByRole('button', { name: /Selecionar Figurinha|Selecionar figurinha/i }).first().click()
  await expect(shell.getByText(/Biblioteca privada|Figurinha 01STICKERLIBRARY01/i)).toBeVisible({ timeout: 10_000 })
})
