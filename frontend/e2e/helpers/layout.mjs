import { expect } from '@playwright/test'

export async function expectNoDocumentOverflow(page, tolerance = 2) {
  await page.waitForLoadState('networkidle')
  const dimensions = await page.evaluate(() => {
    const viewport = document.documentElement.clientWidth
    const offenders = [...document.querySelectorAll('body *')]
      .map((element) => ({ element, rect: element.getBoundingClientRect() }))
      .filter(({ rect }) => rect.right > viewport + 2 || rect.left < -2)
      .sort((left, right) => right.rect.right - left.rect.right)
      .slice(0, 5)
      .map(({ element, rect }) => `${element.tagName.toLowerCase()}.${[...element.classList].join('.')}[${Math.round(rect.left)},${Math.round(rect.right)}]`)
    return { viewport, document: document.documentElement.scrollWidth, offenders }
  })
  expect(dimensions.document, `${page.url()}: document width ${dimensions.document}px exceeds viewport ${dimensions.viewport}px; ${dimensions.offenders.join(', ')}`).toBeLessThanOrEqual(dimensions.viewport + tolerance)
}

export async function openCommandCenter(page) {
  const trigger = page.getByRole('button', { name: /Search AIMS/i })
  await expect(trigger).toBeVisible()
  await trigger.focus()
  await trigger.press('Control+k')
  const dialog = page.getByRole('dialog', { name: /AIMS search and commands/i })
  await expect(dialog).toBeVisible()
  return dialog
}
