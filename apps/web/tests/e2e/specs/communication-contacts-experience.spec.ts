import { expect, test, type Locator, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const readyContact = 'Cliente E2E com foto'
const unavailableContact = 'Cliente E2E sem foto'

const targets = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 }
]

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

async function openContacts(page: Page) {
  const panel = page.getByTestId('communication-contacts-panel')
  await gotoDevRoute(page, '/communication/contacts', panel)
  await expect(page.getByRole('status', { name: 'Carregando contatos' })).toHaveCount(0, {
    timeout: 45_000
  })
  return panel
}

async function expectNoHorizontalOverflow(page: Page) {
  await expect.poll(() => page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )).toBeLessThanOrEqual(1)
}

for (const target of targets) {
  test(`${target.name}: catálogo e detalhe usam fotos reais e fallback`, async ({ page }) => {
    await page.setViewportSize({ width: target.width, height: target.height })
    await login(page)
    const panel = await openContacts(page)

    const readyCard = page.getByTestId('communication-contact-card').filter({ hasText: readyContact })
    const unavailableCard = page.getByTestId('communication-contact-card').filter({ hasText: unavailableContact })
    await expect(readyCard).toBeVisible()
    await expect(unavailableCard).toBeVisible()

    const readyAvatar = readyCard.locator('[data-testid^="communication-contact-avatar-"]')
    await expect(readyAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
    await expect.poll(() => readyAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
      .toBeGreaterThan(0)
    await expect(unavailableCard.locator('[data-testid^="communication-contact-avatar-"]')).toContainText('CS')
    await expectNoHorizontalOverflow(page)

    await readyCard.getByRole('button', { name: `Ver detalhes de ${readyContact}` }).click()
    await expect(page.getByTestId('communication-contact-detail-panel')).toBeVisible({ timeout: 45_000 })
    const profileAvatar = page.getByTestId('communication-contact-profile-avatar')
    await expect(profileAvatar).toHaveAttribute('src', /\/api\/v1\/communication\/profile-pictures\/\d+\/\d+$/)
    await expect.poll(() => profileAvatar.evaluate((image: HTMLImageElement) => image.naturalWidth))
      .toBeGreaterThan(0)

    if (target.name === 'mobile') {
      const trigger = page.getByTestId('communication-contact-context-trigger')
      await trigger.click()
      const dialog = page.getByRole('dialog', { name: 'Contexto do contato' })
      await expect(dialog).toBeVisible()
      await page.keyboard.press('Escape')
      await expect(dialog).toBeHidden()
    } else {
      await expect(page.getByRole('complementary', { name: 'Contexto do contato' })).toBeVisible()
    }

    await expect(panel).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })
}
