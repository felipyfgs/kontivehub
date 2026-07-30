import { expect, test, type Locator, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const readyConversation = 'Cliente E2E com foto'
const unavailableConversation = 'Cliente E2E sem foto'

async function gotoDevRoute(page: Page, path: string, ready: Locator) {
  await page.goto(path, { waitUntil: 'commit', timeout: 30_000 })
  await expect(ready).toBeVisible({ timeout: 45_000 })
}

async function login(page: Page) {
  const emailInput = page.getByLabel('E-mail')
  await gotoDevRoute(page, '/login', emailInput)
  await emailInput.fill(email)
  await page.locator('input[name="password"]').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
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

async function expectSelectionControlKeepsAvatarVisible(row: Locator) {
  const avatar = row.locator('[data-testid^="communication-conversation-avatar-"]')
  const checkbox = row.locator('[data-testid^="communication-conversation-check-"]')
  const avatarBox = await avatar.boundingBox()
  const checkboxBox = await checkbox.boundingBox()

  expect(avatarBox).not.toBeNull()
  expect(checkboxBox).not.toBeNull()
  expect(checkboxBox!.width).toBeLessThan(avatarBox!.width)
  expect(checkboxBox!.height).toBeLessThan(avatarBox!.height)
}

test('desktop: lista, timeline e contexto consomem a foto real', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await login(page)
  await openWorkspace(page)

  const readyRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: readyConversation })
  const unavailableRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: unavailableConversation })
  // Test ids carregam IDs opacos da API: os dados são selecionados pelos nomes estáveis do seed.
  await expect(readyRow).toBeVisible()
  await expect(unavailableRow).toBeVisible()

  const readyAvatar = readyRow.locator('[data-testid^="communication-conversation-avatar-"]')
  await expect(readyAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expect.poll(() => readyAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0)
  await expect(unavailableRow.locator('[data-testid^="communication-conversation-avatar-"]')).toContainText('CS')

  await readyRow.locator('[id^="communication-conversation-"]').click()
  await expectSelectionControlKeepsAvatarVisible(readyRow)
  await expect(page.getByTestId('communication-timeline-panel')).toBeVisible({ timeout: 45_000 })
  const timelineAvatar = page.getByTestId('communication-timeline-avatar')
  await expect(timelineAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expect.poll(() => timelineAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0)

  await page.getByTestId('communication-context-toggle').click()
  const contextAvatar = page.locator('[data-testid="communication-context-avatar"]:visible')
  await expect(contextAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expectNoHorizontalOverflow(page)
})

test('mobile: abre a timeline real e preserva o fallback', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await login(page)
  await openWorkspace(page)

  const readyRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: readyConversation })
  const unavailableRow = page.locator('[data-testid^="communication-conversation-row-"]').filter({ hasText: unavailableConversation })
  await expect(unavailableRow.locator('[data-testid^="communication-conversation-avatar-"]')).toContainText('CS')
  await expectSelectionControlKeepsAvatarVisible(readyRow)
  await readyRow.locator('[id^="communication-conversation-"]').click()
  await expect(page.getByRole('button', { name: 'Voltar à lista' })).toBeVisible({ timeout: 45_000 })
  await expect(page.locator('[data-testid="communication-timeline-avatar"]:visible'))
    .toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
  await expectNoHorizontalOverflow(page)
})
