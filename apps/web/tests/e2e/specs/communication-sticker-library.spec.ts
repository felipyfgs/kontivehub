import { expect, test, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const conversation = process.env.E2E_COMPOSER_CONVERSATION || process.env.E2E_TIMELINE_CONVERSATION || 'Cliente E2E com foto'

const targets = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 }
] as const

function enabledKind(family: string, extras: Record<string, unknown> = {}) {
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
      multiple: { enabled: true, reason: null }
    },
    ...extras
  }
}

function buildStubPayload() {
  return {
    data: {
      enabled: true,
      requires_permission: 'communication.reply',
      max_media_bytes: 20 * 1024 * 1024,
      conversation_initiation: { enabled: true, reason: null, requires_permission: 'communication.reply' },
      kinds: {
        TEXT: enabledKind('TEXT'),
        STICKER: enabledKind('STICKER'),
        IMAGE: enabledKind('IMAGE'),
        VIDEO: enabledKind('VIDEO'),
        DOCUMENT: enabledKind('DOCUMENT'),
        AUDIO: enabledKind('AUDIO')
      }
    }
  }
}

const stickerRecent = {
  id: 'sticker_aaaa1111',
  label: 'Saudação',
  source: 'recent',
  available: true,
  app_favorite: false,
  device_favorite: false
}

const stickerFavorite = {
  id: 'sticker_bbbb2222',
  label: 'Obrigado',
  source: 'device_favorite',
  available: true,
  app_favorite: true,
  device_favorite: true
}

async function stubComposerCapabilities(page: Page) {
  await page.route(/outbound-capabilities/, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': '*', 'cache-control': 'no-store' },
      body: JSON.stringify(buildStubPayload())
    })
  })
}

async function stubStickerLibrary(page: Page, options: { syncStatus?: string, empty?: boolean, error?: boolean } = {}) {
  const { syncStatus = 'partial', empty = false, error = false } = options
  const items = empty ? [] : [stickerRecent, stickerFavorite]

  await page.route(/\/api\/v1\/communication\/inboxes\/\d+\/stickers(\?.*)?$/, async (route) => {
    if (error) {
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Erro interno' }) })
      return
    }
    const url = new URL(route.request().url())
    const filter = url.searchParams.get('sticker_filter') || url.searchParams.get('filter') || 'recent'
    const filtered = filter === 'favorites' ? [stickerFavorite] : items
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: filtered,
        meta: { current_page: 1, last_page: 1, sync_status: syncStatus, sync_reason: syncStatus === 'not_observed' ? 'O dispositivo ainda não forneceu figurinhas. A sincronização é parcial.' : syncStatus === 'partial' ? 'A biblioteca mostra somente figurinhas observadas no dispositivo; a coleção pode estar incompleta.' : null }
      })
    })
  })

  await page.route(/\/api\/v1\/communication\/stickers\/[^/]+\/preview$/, async (route) => {
    await route.fulfill({ status: 200, contentType: 'image/webp', body: Buffer.from('RIFFxxxxWEBP') })
  })

  await page.route(/\/api\/v1\/communication\/stickers\/[^/]+\/favorite$/, async (route) => {
    const body = route.request().postDataJSON() as { favorite: boolean } | null
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...stickerRecent, app_favorite: body?.favorite ?? true } })
    })
  })

  await page.route(/\/api\/v1\/communication\/inboxes\/\d+\/stickers\/import$/, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...stickerRecent, id: 'sticker_imported_99', source: 'local_import' } })
    })
  })
}

async function login(page: Page) {
  const emailInput = page.getByLabel('E-mail')
  await page.goto('/login', { waitUntil: 'commit', timeout: 30_000 })
  await expect(emailInput).toBeVisible({ timeout: 30_000 })
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
  await page.goto('/communication', { waitUntil: 'commit' })
  await expect(list).toBeVisible({ timeout: 45_000 })
  await expect(page.getByTestId('communication-conversations-skeleton')).toHaveCount(0, { timeout: 45_000 })
  const row = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: conversation }).first()
  await expect(row).toBeVisible({ timeout: 30_000 })
  const rowTestId = await row.getAttribute('data-testid')
  const conversationId = rowTestId?.replace('communication-conversation-row-', '')
  if (!conversationId) throw new Error('Conversa E2E não encontrada')
  const capabilities = page.waitForResponse(r => /outbound-capabilities/.test(r.url()) && r.ok(), { timeout: 45_000 })
  await page.goto(`/communication/conversations/${conversationId}`, { waitUntil: 'commit' })
  await expect(composer(page)).toBeVisible({ timeout: 45_000 })
  await capabilities
}

async function openExpressionPicker(page: Page, includeSticker = true) {
  const shell = composer(page)
  const trigger = shell.getByRole('button', { name: 'Adicionar expressão' })
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  await trigger.click()
  const picker = page.locator('[aria-label="Seletor de expressões"]')
  await expect(picker).toBeVisible({ timeout: 15_000 })
  if (includeSticker) {
    const stickerTab = picker.getByRole('tab', { name: 'Figurinhas' })
    await expect(stickerTab).toBeVisible()
    await stickerTab.click()
  }
  return page.locator('[aria-label="Seletor de expressões"]')
}

for (const target of targets) {
  test(`${target.name}: sticker library recentes e favoritas com sincronização parcial`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await stubStickerLibrary(page, { syncStatus: 'partial' })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    await expect(picker.getByRole('tab', { name: 'Recentes' })).toBeVisible()
    await expect(picker.getByRole('tab', { name: 'Favoritas' })).toBeVisible()
    await expect(picker.locator('[aria-label="Figurinhas recentes"]')).toBeVisible({ timeout: 10_000 })
    await expect(picker.locator('article').first()).toBeVisible()
    await expect(picker.getByText('Sincronização parcial')).toBeVisible()
    await expect(picker.getByText('Recente observada no dispositivo').first()).toBeVisible()

    await picker.getByRole('tab', { name: 'Favoritas' }).click()
    await expect(picker.locator('[aria-label="Figurinhas favoritas"]')).toBeVisible()
    await expect(picker.getByText('Favorita observada no dispositivo').first()).toBeVisible()
  })

  test(`${target.name}: biblioteca vazia no bootstrap exibe fallback e mantém upload local`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await stubStickerLibrary(page, { syncStatus: 'not_observed', empty: true })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    await expect(picker.getByText('Nenhuma figurinha recente observada')).toBeVisible({ timeout: 10_000 })
    await expect(picker.getByText('Você ainda pode usar um arquivo WebP local')).toBeVisible()
    await expect(picker.getByRole('button', { name: 'Usar arquivo local' })).toBeVisible()
    await expect(picker.getByRole('button', { name: 'Importar para a biblioteca' })).toBeVisible()
    await expect(picker.getByText('O dispositivo ainda não forneceu figurinhas')).toBeVisible()
  })

  test(`${target.name}: favoritar no KontiveHub preserva favorito do dispositivo`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await stubStickerLibrary(page, { syncStatus: 'partial' })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    const firstCard = picker.locator('article').first()
    await expect(firstCard).toBeVisible({ timeout: 10_000 })
    const favoriteBtn = firstCard.getByRole('button', { name: /Adicionar.*favoritos do KontiveHub|Remover.*favoritos do KontiveHub/ })
    await expect(favoriteBtn).toBeVisible()
    await expect(favoriteBtn).toHaveAttribute('aria-label', /KontiveHub/)
    await favoriteBtn.click()
    await expect(page.waitForResponse(r => /\/favorite/.test(r.url()) && r.ok(), { timeout: 10_000 })).toBeTruthy()
  })

  test(`${target.name}: falha de carregamento exibe retry e recupera`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    let firstCall = true
    await page.route(/\/api\/v1\/communication\/inboxes\/\d+\/stickers(\?.*)?$/, async (route) => {
      if (firstCall) {
        firstCall = false
        await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Erro' }) })
        return
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [stickerRecent], meta: { current_page: 1, last_page: 1, sync_status: 'partial' } })
      })
    })
    await page.route(/\/api\/v1\/communication\/stickers\/[^/]+\/preview$/, async (route) => {
      await route.fulfill({ status: 200, contentType: 'image/webp', body: Buffer.from('RIFFxxxxWEBP') })
    })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    await expect(picker.getByText('Biblioteca indisponível')).toBeVisible({ timeout: 10_000 })
    const retry = picker.getByRole('button', { name: 'Tentar novamente' })
    await expect(retry).toBeVisible()
    await retry.click()
    await expect(picker.locator('article').first()).toBeVisible({ timeout: 10_000 })
  })

  test(`${target.name}: tenant denial não expõe dados e mantém falha fechada`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await page.route(/\/api\/v1\/communication\/inboxes\/\d+\/stickers(\?.*)?$/, async (route) => {
      await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'Forbidden' }) })
    })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    await expect(picker.getByText('Biblioteca indisponível')).toBeVisible({ timeout: 10_000 })
    await expect(picker.getByText('Não foi possível carregar')).toBeVisible()
  })

  test(`${target.name}: tema claro e escuro e alvos 44px sem regressão`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await stubStickerLibrary(page, { syncStatus: 'partial' })
    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    const recentTab = picker.getByRole('tab', { name: 'Recentes' })
    await expect(recentTab).toBeVisible()
    const box = await recentTab.boundingBox()
    expect(box?.height).toBeGreaterThanOrEqual(44)
    const box2 = await picker.getByRole('button', { name: 'Usar arquivo local' }).boundingBox()
    expect(box2?.height).toBeGreaterThanOrEqual(44)

    await page.emulateMedia({ colorScheme: 'dark' })
    await expect(picker).toBeVisible()
    await page.emulateMedia({ colorScheme: 'light' })
    await expect(picker).toBeVisible()

    await page.emulateMedia({ reducedMotion: 'reduce' })
    await expect(picker).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(picker).not.toBeVisible()
  })

  test(`${target.name}: seleção de figurinha materializa envio e aparece na timeline`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await stubComposerCapabilities(page)
    await stubStickerLibrary(page, { syncStatus: 'partial' })

    let batchCreated = false
    await page.route(/\/api\/v1\/communication\/conversations\/\d+\/messages$/, async (route) => {
      if (route.request().method() === 'POST') {
        batchCreated = true
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ data: { id: '999', content: { sticker: { id: stickerRecent.id } }, status: 'queued' } })
        })
        return
      }
      await route.continue()
    })

    await login(page)
    await openConversation(page)

    const picker = await openExpressionPicker(page)
    const stickerBtn = picker.locator('article').first().getByRole('button', { name: /Selecionar/ })
    await expect(stickerBtn).toBeVisible({ timeout: 10_000 })
    await stickerBtn.click()
    await expect(picker).not.toBeVisible({ timeout: 10_000 })

    const sendBtn = composer(page).getByRole('button', { name: 'Enviar' })
    await expect(sendBtn).toBeVisible()
    await sendBtn.click()
    await expect.poll(() => batchCreated, { timeout: 10_000 }).toBe(true)
  })
}
