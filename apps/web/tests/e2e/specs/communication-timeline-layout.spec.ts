import { expect, test, type Locator, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const shortConversation = process.env.E2E_TIMELINE_CONVERSATION || 'Cliente E2E com foto'

async function gotoDevRoute(page: Page, path: string, ready: Locator) {
  await page.goto(path, { waitUntil: 'commit', timeout: 30_000 })
  // O primeiro carregamento isolado precisa compilar o manifesto completo do Nuxt.
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

async function openShortConversation(page: Page) {
  const list = page.getByTestId('communication-list-panel')
  await gotoDevRoute(page, '/communication', list)
  await expect(page.getByTestId('communication-conversations-skeleton')).toHaveCount(0, {
    timeout: 45_000
  })

  const row = page.locator('[data-testid^="communication-conversation-row-"]')
    .filter({ hasText: shortConversation })
  await expect(row).toBeVisible()
  await row.locator('[id^="communication-conversation-"]').click()
  await expect(page.locator('[data-testid="communication-message-stack"]:visible'))
    .toBeVisible({ timeout: 45_000 })
}

async function timelineMetrics(page: Page) {
  return page.locator('[data-testid="communication-messages-viewport"]:visible')
    .evaluate((viewport) => {
      const stack = viewport.querySelector<HTMLElement>('[data-testid="communication-message-stack"]')
      if (!stack) throw new Error('Agrupamento de mensagens não renderizado')

      const viewportRect = viewport.getBoundingClientRect()
      const stackRect = stack.getBoundingClientRect()
      const style = getComputedStyle(viewport)

      return {
        bottomGap: viewportRect.bottom - Number.parseFloat(style.paddingBottom) - stackRect.bottom,
        clientHeight: viewport.clientHeight,
        scrollHeight: viewport.scrollHeight,
        scrollTop: viewport.scrollTop,
        stackTop: stackRect.top,
        viewportContentTop: viewportRect.top + Number.parseFloat(style.paddingTop)
      }
    })
}

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 }
]) {
  test(`${viewport.name}: conversa curta fica junto ao compositor e histórico longo continua acessível`, async ({ page }) => {
    await page.setViewportSize(viewport)
    await login(page)
    await openShortConversation(page)

    const short = await timelineMetrics(page)
    expect(Math.abs(short.bottomGap)).toBeLessThanOrEqual(1)
    expect(short.scrollHeight - short.clientHeight).toBeLessThanOrEqual(1)
    expect(short.stackTop).toBeGreaterThan(short.viewportContentTop)

    const stack = page.locator('[data-testid="communication-message-stack"]:visible')
    await stack.evaluate((element) => {
      const message = element.querySelector('article')
      if (!message) throw new Error('Mensagem base não renderizada')
      for (let index = 0; index < 24; index++) {
        element.append(message.cloneNode(true))
      }
    })

    const messagesViewport = page.locator('[data-testid="communication-messages-viewport"]:visible')
    await expect.poll(async () => {
      const metrics = await timelineMetrics(page)
      return metrics.scrollHeight - metrics.clientHeight
    }).toBeGreaterThan(0)

    await messagesViewport.evaluate((element) => {
      element.scrollTop = 0
    })
    const long = await timelineMetrics(page)
    expect(long.scrollTop).toBe(0)
    expect(Math.abs(long.stackTop - long.viewportContentTop)).toBeLessThanOrEqual(1)
  })
}
