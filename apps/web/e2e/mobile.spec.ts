import { expect, test } from '@playwright/test'
import { loginTenant } from './helpers'

test('mobile navigation routes an authorized station manager', async ({ page }) => {
  await loginTenant(page, 'manager@fuelflow.local')
  await page.getByRole('button', { name: 'Open primary navigation' }).click()
  const navigation = page.getByRole('navigation', { name: 'Mobile primary navigation' })
  await expect(navigation).toBeVisible()
  await navigation.getByRole('link', { name: 'Procurement' }).click()
  await expect(page).toHaveURL(/\/modules\/procurement$/)
  await expect(page.getByRole('heading', { name: 'Procurement' })).toBeVisible()
})
