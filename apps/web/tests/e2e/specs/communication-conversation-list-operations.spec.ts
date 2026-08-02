import { expect, test, type Locator, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const readyConversation = 'Cliente E2E com foto'
const unavailableConversation = 'Cliente E2E sem foto'

async function gotoDevRoute(page: Page, path: string, ready: Locator) {
  await page.goto(path, { waitUntil: 'commit', timeout: 120_000 })
  await expect(ready).toBeVisible({ timeout: 120_000 })
}

async function login(page: Page) {
  const emailInput = page.getByRole('textbox', { name: /E-mail/ })
  await gotoDevRoute(page, '/login', emailInput)
  await emailInput.fill(email)
  await page.locator('input[name="password"]').fill(password)
  await Promise.all([
    page.waitForURL(url => url.pathname !== '/login', {
      waitUntil: 'domcontentloaded',
      timeout: 120_000
    }),
    page.getByRole('button', { name: 'Entrar' }).click()
  ])
}

async function openWorkspace(page: Page) {
  const list = page.getByTestId('communication-list-panel')
  await gotoDevRoute(page, '/communication', list)
  await expect(page.getByTestId('communication-conversations-skeleton')).toHaveCount(0, {
    timeout: 45_000
  })
  return list
}

async function expectNoHorizontalOverflow(page: Page) {
  await expect.poll(() => page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )).toBeLessThanOrEqual(1)
}

async function expectNoElementHorizontalOverflow(locator: Locator) {
  await expect.poll(() => locator.evaluate(
    element => element.scrollWidth - element.clientWidth
  )).toBeLessThanOrEqual(1)
}

async function expectSquareControl(locator: Locator) {
  await expect.poll(async () => {
    const box = await locator.boundingBox()
    return box ? Math.abs(box.width - box.height) : Number.POSITIVE_INFINITY
  }).toBeLessThanOrEqual(1)
}

function conversationAvatar(row: Locator) {
  return row.getByTestId(/^communication-conversation-avatar-(?!select-).+$/)
}

async function dismissOpenMenu(page: Page) {
  await page.keyboard.press('Escape')
  if (await page.locator('[role="menu"]:visible').count()) {
    const viewport = page.viewportSize()
    expect(viewport).not.toBeNull()
    await page.mouse.click(viewport!.width - 8, viewport!.height - 8)
  }
}

async function expectDropdownAlignment(
  page: Page,
  trigger: Locator,
  itemName: string | RegExp,
  expectedAlign: 'end' | 'start'
) {
  await trigger.click()
  const item = page.getByRole('menuitem', { name: itemName })
  await expect(item).toBeVisible()
  const menu = page.locator('[role="menu"]').filter({ has: item })
  await expect(menu).toHaveAttribute('data-align', expectedAlign)

  const viewport = page.viewportSize()
  const menuBox = await menu.boundingBox()
  expect(viewport).not.toBeNull()
  expect(menuBox).not.toBeNull()
  expect(menuBox!.x).toBeGreaterThanOrEqual(7)
  expect(menuBox!.x + menuBox!.width).toBeLessThanOrEqual(viewport!.width - 7)

  await dismissOpenMenu(page)
  await expect(item).toHaveCount(0)
}

async function expectSelectionControlCentered(row: Locator) {
  const avatar = conversationAvatar(row)
  const checkbox = row.locator('[data-testid^="communication-conversation-check-"]')
  const avatarBox = await avatar.boundingBox()
  const checkboxBox = await checkbox.boundingBox()

  expect(avatarBox).not.toBeNull()
  expect(checkboxBox).not.toBeNull()
  expect(checkboxBox!.width).toBeLessThan(avatarBox!.width)
  expect(checkboxBox!.height).toBeLessThan(avatarBox!.height)
  const avatarCenter = {
    x: avatarBox!.x + avatarBox!.width / 2,
    y: avatarBox!.y + avatarBox!.height / 2
  }
  const checkboxCenter = {
    x: checkboxBox!.x + checkboxBox!.width / 2,
    y: checkboxBox!.y + checkboxBox!.height / 2
  }
  expect(Math.abs(checkboxCenter.x - avatarCenter.x)).toBeLessThanOrEqual(1)
  expect(Math.abs(checkboxCenter.y - avatarCenter.y)).toBeLessThanOrEqual(1)
}

function conversationRow(page: Page, name: string) {
  return page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: name })
}

test('Não lidas mantém a foto ao ler e só recompõe ao reativar a tab', async ({ page }) => {
  test.setTimeout(300_000)
  await page.setViewportSize({ width: 1366, height: 800 })
  const snapshotCreations: string[] = []
  page.on('response', (response) => {
    const url = new URL(response.url())
    const snapshot = url.searchParams.get('snapshot')
    if (response.ok()
      && url.pathname.endsWith('/api/v1/communication/conversations')
      && (snapshot === 'true' || snapshot === '1')) {
      snapshotCreations.push(response.url())
    }
  })
  await login(page)
  await openWorkspace(page)

  const tabs = page.getByRole('tablist')
  const unreadTab = tabs.getByRole('tab', { name: 'Não lidas' })
  await unreadTab.click()
  await expect.poll(() => snapshotCreations.length).toBe(1)
  await expect(conversationRow(page, readyConversation)).toBeVisible()
  await expect(conversationRow(page, unavailableConversation)).toBeVisible()

  await unreadTab.focus()
  await unreadTab.press('Enter')
  await expect.poll(() => snapshotCreations.length).toBe(2)
  await unreadTab.press('Space')
  await expect.poll(() => snapshotCreations.length).toBe(3)

  const readyRow = conversationRow(page, readyConversation)
  const unavailableRow = conversationRow(page, unavailableConversation)
  await readyRow.locator('[id^="communication-conversation-"]').click()
  await expect(page.locator('[data-testid="communication-timeline-panel"]:visible')).toBeVisible()
  await expect(readyRow).toBeVisible()
  await expect(readyRow.getByTestId('communication-conversation-unread')).toHaveCount(0)

  const rows = page.locator('[data-testid^="communication-conversation-row-"]')
  const rowNames = await rows.allTextContents()
  const readyIndex = rowNames.findIndex(text => text.includes(readyConversation))
  expect(readyIndex).toBeGreaterThanOrEqual(0)
  const direction = readyIndex > 0 ? 'ArrowUp' : 'ArrowDown'
  const targetRow = readyIndex > 0 ? rows.nth(readyIndex - 1) : rows.nth(readyIndex + 1)
  await readyRow.locator('[id^="communication-conversation-"]').focus()
  await page.keyboard.press(direction)
  await expect(targetRow.locator('[id^="communication-conversation-"]')).toHaveAttribute('aria-current', 'true')
  await expect(unavailableRow).toBeVisible()
  await expect(unavailableRow.getByTestId('communication-conversation-unread')).toHaveCount(0)
  await expect(readyRow).toBeVisible()

  const detailUrl = page.url()
  await unreadTab.click()
  await expect.poll(() => snapshotCreations.length).toBe(4)
  await expect(readyRow).toHaveCount(0)
  await expect(unavailableRow).toHaveCount(0)
  await expect(page).toHaveURL(detailUrl)
  await expect(page.locator('[data-testid="communication-timeline-panel"]:visible')).toBeVisible()

  await tabs.getByRole('tab', { name: 'Em aberto' }).click()
  for (const name of [readyConversation, unavailableConversation]) {
    const row = conversationRow(page, name)
    await row.getByTestId(/^communication-conversation-menu-/).click()
    await page.getByRole('menuitem', { name: 'Marcar como não lida' }).click()
    await expect(row.getByTestId('communication-conversation-unread')).toBeVisible()
  }

  await page.setViewportSize({ width: 390, height: 844 })
  const back = page.getByRole('button', { name: 'Voltar à lista' })
  await expect(back).toBeVisible()
  await back.click()
  await unreadTab.click()
  const mobileReadyRow = conversationRow(page, readyConversation)
  await mobileReadyRow.locator('[id^="communication-conversation-"]').click()
  await expect(page.locator('[data-testid="communication-timeline-panel"]:visible')).toBeVisible()
  await expect(mobileReadyRow.getByTestId('communication-conversation-unread')).toHaveCount(0)
  await expect(back).toBeVisible()
  await back.click()
  await expect(mobileReadyRow).toBeVisible()

  const mobileUnavailableRow = conversationRow(page, unavailableConversation)
  await mobileUnavailableRow.locator('[id^="communication-conversation-"]').click()
  await expect(page.locator('[data-testid="communication-timeline-panel"]:visible')).toBeVisible()
  await expect(mobileUnavailableRow.getByTestId('communication-conversation-unread')).toHaveCount(0)
  await expect(back).toBeVisible()
  await back.click()
  await expect(mobileUnavailableRow).toBeVisible()
  await unreadTab.focus()
  await unreadTab.press('Space')
  await expect(mobileReadyRow).toHaveCount(0)
  await expect(mobileUnavailableRow).toHaveCount(0)
  await expectNoHorizontalOverflow(page)
})

test('desktop: lista, timeline e contexto consomem a foto real', async ({ page }) => {
  await page.setViewportSize({ width: 1366, height: 639 })
  await login(page)
  await openWorkspace(page)

  const readyRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: readyConversation })
  const unavailableRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: unavailableConversation })
  // Test ids carregam IDs opacos da API: os dados são selecionados pelos nomes estáveis do seed.
  await expect(readyRow).toBeVisible()
  await expect(unavailableRow).toBeVisible()

  const readyAvatar = conversationAvatar(readyRow)
  await expect(readyAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expect.poll(() => readyAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0)
  await expect(conversationAvatar(unavailableRow)).toContainText('CE')

  await readyRow.locator('[id^="communication-conversation-"]').click()
  await expectSelectionControlCentered(readyRow)
  await expect(page.getByTestId('communication-timeline-panel')).toBeVisible({ timeout: 45_000 })
  const timelineAvatar = page.getByTestId('communication-timeline-avatar')
  await expect(timelineAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expect.poll(() => timelineAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0)

  await page.getByTestId('communication-context-toggle').click()
  const timeline = page.getByTestId('communication-timeline-panel')
  const context = page.getByTestId('communication-context-panel')
  const composer = page.getByTestId('communication-composer')
  const contextAvatar = page.locator('[data-testid="communication-context-avatar"]:visible')
  await expect(timeline).toBeVisible()
  await expect(context).toBeVisible()
  await expect(composer).toBeVisible()
  await expect(page.getByTestId('communication-context-slideover')).toHaveCount(0)
  await expect(contextAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expect.poll(async () => {
    const composerBox = await composer.boundingBox()
    const contextBox = await context.boundingBox()
    if (!composerBox || !contextBox) return Number.POSITIVE_INFINITY
    return Math.abs(composerBox.x + composerBox.width - contextBox.x)
  }).toBeLessThanOrEqual(1)

  const messageInput = composer.getByRole('combobox')
  await messageInput.fill('Rascunho com o contexto aberto')
  await expect(messageInput).toHaveValue('Rascunho com o contexto aberto')
  await expectNoHorizontalOverflow(page)
})

test('mobile: abre a timeline real e preserva o fallback', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await login(page)
  await openWorkspace(page)

  const readyRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: readyConversation })
  const unavailableRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: unavailableConversation })
  await expect(conversationAvatar(unavailableRow)).toContainText('CE')
  await expectSelectionControlCentered(readyRow)
  await readyRow.locator('[id^="communication-conversation-"]').click()
  await expect(page.getByRole('button', { name: 'Voltar à lista' })).toBeVisible({ timeout: 45_000 })
  await expect(page.locator('[data-testid="communication-timeline-avatar"]:visible'))
    .toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expectNoHorizontalOverflow(page)
})

test('filtros usam tabs pill compactas em largura total e dois popovers ao lado da busca', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await login(page)
  const listPanel = await openWorkspace(page)

  for (const width of [320, 390, 1024, 1440]) {
    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 })

    const filters = page.getByTestId('communication-list-filters')
    const firstRow = page.locator('[data-testid^="communication-conversation-row-"]').first()
    await expect(filters).toBeVisible()
    await expect(firstRow).toBeVisible()

    const filtersBox = await filters.boundingBox()
    const rowBox = await firstRow.boundingBox()
    expect(filtersBox).not.toBeNull()
    expect(rowBox).not.toBeNull()
    expect(Math.abs(filtersBox!.x - rowBox!.x)).toBeLessThanOrEqual(1)
    expect(Math.abs(filtersBox!.width - rowBox!.width)).toBeLessThanOrEqual(1)

    const tabs = page.getByRole('tablist')
    await expect(tabs.locator('..')).toHaveAttribute(
      'aria-label',
      'Visões rápidas das conversas'
    )
    await expect(tabs.getByRole('tab')).toHaveCount(3)
    await expect(tabs.getByRole('tab').nth(0)).toHaveAccessibleName('Em aberto')
    await expect(tabs.getByRole('tab').nth(1)).toHaveAccessibleName('Não lidas')
    await expect(tabs.getByRole('tab').nth(2)).toHaveAccessibleName('Não atribuídas')

    const statusOptions = page.getByTestId('communication-filter-status-options')
    const advanced = page.getByTestId('communication-filter-advanced-trigger')
    await expectSquareControl(statusOptions)
    await expectSquareControl(advanced)

    await tabs.getByRole('tab').nth(1).click()
    await expect(advanced).toHaveAttribute('aria-label', 'Filtros avançados')
    await expect(page.getByTestId('communication-filter-active-summary')).toHaveCount(0)
    await tabs.getByRole('tab').nth(0).click()

    const searchBox = await page.getByTestId('communication-search').boundingBox()
    const tabsBox = await page.getByTestId('communication-filter-views').boundingBox()
    const tabsListBox = await tabs.boundingBox()
    const statusBox = await statusOptions.boundingBox()
    const advancedBox = await advanced.boundingBox()
    expect(searchBox).not.toBeNull()
    expect(tabsBox).not.toBeNull()
    expect(tabsListBox).not.toBeNull()
    expect(statusBox).not.toBeNull()
    expect(advancedBox).not.toBeNull()
    expect(searchBox!.x).toBeGreaterThan(filtersBox!.x)
    expect(tabsBox!.x).toBeGreaterThan(filtersBox!.x)
    expect(tabsBox!.width).toBeGreaterThan(searchBox!.width)
    expect(Math.abs(tabsListBox!.width - tabsBox!.width)).toBeLessThanOrEqual(1)
    expect(Math.abs(statusBox!.y - searchBox!.y)).toBeLessThanOrEqual(6)
    expect(Math.abs(advancedBox!.y - searchBox!.y)).toBeLessThanOrEqual(6)
    expect(tabsBox!.y).toBeGreaterThan(searchBox!.y + searchBox!.height)

    await statusOptions.click()
    const statusPanel = page.getByTestId('communication-filter-status-panel')
    await expect(statusPanel).toBeVisible()
    await expect(statusPanel.locator('..')).toHaveAttribute('data-align', 'end')
    await expect(page.getByTestId('communication-filter-status')).toBeVisible()
    await expect(page.getByTestId('communication-filter-sort')).toBeVisible()
    const statusPopoverBox = await statusPanel.boundingBox()
    expect(statusPopoverBox).not.toBeNull()
    expect(statusPopoverBox!.x).toBeGreaterThanOrEqual(0)
    expect(statusPopoverBox!.x + statusPopoverBox!.width).toBeLessThanOrEqual(width)
    await statusOptions.click()
    await expect(statusPanel).toHaveCount(0)

    await advanced.click()
    const advancedPanel = page.getByTestId('communication-filter-advanced-panel')
    await expect(advancedPanel).toBeVisible()
    await expect(advancedPanel.locator('..')).toHaveAttribute('data-align', 'start')
    await expect(advancedPanel).toContainText('Todas as regras são combinadas com “E”.')
    const popoverBox = await advancedPanel.boundingBox()
    expect(popoverBox).not.toBeNull()
    expect(popoverBox!.x).toBeGreaterThanOrEqual(0)
    expect(popoverBox!.x + popoverBox!.width).toBeLessThanOrEqual(width)
    await expectNoHorizontalOverflow(page)
    await expectNoElementHorizontalOverflow(listPanel)
    await advanced.click()
    await expect(advancedPanel).toHaveCount(0)
  }
})

test('seleção contextual cobre teclado, todas carregadas, conteúdo e foco', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 })
  await login(page)
  await openWorkspace(page)

  const rows = page.locator('[data-testid^="communication-conversation-row-"]')
  await expect(rows).not.toHaveCount(0)
  const firstRow = rows.first()
  const secondRow = rows.nth(1)
  const firstAvatar = firstRow.locator('[data-testid^="communication-conversation-avatar-select-"]')
  const firstCheckbox = firstRow.locator('[data-testid^="communication-conversation-check-"]')
  const secondCheckbox = secondRow.locator('[data-testid^="communication-conversation-check-"]')

  await firstAvatar.hover()
  await firstCheckbox.click()
  await expect(page.getByTestId('communication-bulk-bar')).toBeVisible()
  await expect(page.getByTestId('communication-timeline-panel')).toHaveCount(0)

  await secondCheckbox.focus()
  await secondCheckbox.press('Enter')
  await expect(secondCheckbox).toHaveAttribute('aria-checked', 'true')
  await secondCheckbox.press('Space')
  await expect(secondCheckbox).toHaveAttribute('aria-checked', 'false')
  await expect(page.getByTestId('communication-timeline-panel')).toHaveCount(0)

  const bulkToggle = page.getByTestId('communication-bulk-select-all')
  await expect(bulkToggle).toHaveAttribute('aria-checked', 'mixed')
  await bulkToggle.click()
  await expect(bulkToggle).toHaveAttribute('aria-checked', 'true')

  const list = page.getByTestId('communication-conversation-list')
  for (const width of [320, 390, 1024, 1440]) {
    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 })
    await list.evaluate((element) => {
      element.scrollTop = element.scrollHeight
      element.dispatchEvent(new Event('scroll'))
    })
    await expect.poll(() => list.evaluate(
      element => element.scrollTop + element.clientHeight >= element.scrollHeight - 1
    )).toBe(true)

    const lastVisibleRow = rows.last()
    const lastVisibleCheckbox = lastVisibleRow.locator('[data-testid^="communication-conversation-check-"]')
    await expect(lastVisibleCheckbox).toHaveAttribute('aria-checked', 'true')
    const lastRowBox = await lastVisibleRow.boundingBox()
    const bulkBar = page.getByTestId('communication-bulk-bar')
    const bulkBarBox = await bulkBar.boundingBox()
    const listBox = await list.boundingBox()
    expect(lastRowBox).not.toBeNull()
    expect(bulkBarBox).not.toBeNull()
    expect(listBox).not.toBeNull()
    expect(bulkBarBox!.y + bulkBarBox!.height).toBeLessThanOrEqual(listBox!.y + 1)
    expect(lastRowBox!.y).toBeGreaterThanOrEqual(listBox!.y - 1)
    expect(lastRowBox!.y + lastRowBox!.height)
      .toBeLessThanOrEqual(listBox!.y + listBox!.height + 1)
    await expect.poll(() => page.locator('[data-testid^="communication-bulk-menu-"]').count())
      .toBeGreaterThanOrEqual(3)
    await expectNoHorizontalOverflow(page)
    await expectNoElementHorizontalOverflow(bulkBar)
    await expectNoElementHorizontalOverflow(page.getByTestId('communication-list-panel'))

    if (width === 320) {
      await expectDropdownAlignment(
        page,
        page.getByTestId('communication-bulk-menu-read'),
        /Marcar como (?:não )?lidas/,
        'start'
      )
    }
  }

  const bulkMenus = [
    ['read', /Marcar como (?:não )?lidas/, 'start'],
    ['status', 'Resolver', 'start'],
    ['more', 'Responsável', 'end']
  ] as const
  for (const [key, itemName, align] of bulkMenus) {
    const trigger = page.getByTestId(`communication-bulk-menu-${key}`)
    if (await trigger.count()) {
      await expectDropdownAlignment(page, trigger, itemName, align)
    }
  }

  await page.getByTestId('communication-bulk-clear').click()
  await expect(page.getByTestId('communication-bulk-bar')).toHaveCount(0)
  await expect.poll(() => page.evaluate(() => {
    const active = document.activeElement as HTMLElement | null
    return Boolean(active?.dataset.conversationId)
      || active?.dataset.testid === 'communication-conversation-list'
  })).toBe(true)

  const fallbackRowTestId = await page
    .locator('[data-testid^="communication-conversation-row-"]:visible')
    .first()
    .getAttribute('data-testid')
  expect(fallbackRowTestId).not.toBeNull()
  const fallbackRow = page.getByTestId(fallbackRowTestId!)
  const checkbox = fallbackRow.locator('[data-testid^="communication-conversation-check-"]')
  await fallbackRow.locator('[data-testid^="communication-conversation-avatar-select-"]').hover()
  await checkbox.click()
  await page.getByTestId('communication-bulk-select-all').click()
  await expect(page.getByTestId('communication-bulk-select-all')).toHaveAttribute('aria-checked', 'true')
  await page.getByTestId('communication-bulk-select-all').click()
  await expect(page.getByTestId('communication-bulk-bar')).toHaveCount(0)
})

test.describe('painel mestre com toque', () => {
  test.use({
    hasTouch: true,
    viewport: { width: 1024, height: 900 }
  })

  test('mantém todas as ações bulk acessíveis na largura mínima', async ({ page }) => {
    await login(page)
    const listPanel = await openWorkspace(page)
    await expect.poll(() => page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true)

    await listPanel.evaluate((element) => {
      const panel = element as HTMLElement
      panel.style.setProperty('flex', '0 0 320px', 'important')
      panel.style.setProperty('width', '320px', 'important')
      panel.style.setProperty('max-width', '320px', 'important')
    })
    await expect.poll(async () => (await listPanel.boundingBox())?.width ?? 0).toBeLessThanOrEqual(321)

    const firstRow = page.locator('[data-testid^="communication-conversation-row-"]').first()
    await firstRow.locator('[data-testid^="communication-conversation-check-"]').click()

    const bulkBar = page.getByTestId('communication-bulk-bar')
    await expect(bulkBar).toBeVisible()
    await expectNoElementHorizontalOverflow(bulkBar)

    const controls = bulkBar.locator(
      '[data-testid^="communication-bulk-menu-"], [data-testid="communication-bulk-clear"]'
    )
    await expect.poll(() => controls.count()).toBeGreaterThanOrEqual(4)
    const barBox = await bulkBar.boundingBox()
    expect(barBox).not.toBeNull()
    const controlsCount = await controls.count()
    for (let index = 0; index < controlsCount; index += 1) {
      const control = controls.nth(index)
      await expect(control).toBeVisible()
      const controlBox = await control.boundingBox()
      expect(controlBox).not.toBeNull()
      expect(controlBox!.x).toBeGreaterThanOrEqual(barBox!.x - 1)
      expect(controlBox!.x + controlBox!.width).toBeLessThanOrEqual(barBox!.x + barBox!.width + 1)
    }
  })
})
