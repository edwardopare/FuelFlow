import { expect, type Page } from '@playwright/test'

export const demoPassword = process.env.E2E_DEMO_PASSWORD ?? 'ChangeMeNow1!'

export async function loginTenant(
  page: Page,
  email: string,
  password = demoPassword,
) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill(email)
  await page.locator('#password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).not.toHaveURL(/\/login$/)
}

export async function loginVendor(page: Page) {
  await page.goto('/vendor/login')
  await page.getByLabel('Email address').fill('vendor@fuelflow.local')
  await page.locator('#vendor-password').fill(demoPassword)
  await page.getByRole('button', { name: 'Open vendor dashboard' }).click()
  await expect(page).toHaveURL(/\/vendor$/)
}

export async function logout(page: Page) {
  await page.getByRole('button', { name: 'Log out' }).click()
  await expect(page).toHaveURL(/\/login$/)
}

export function rowWithText(page: Page, text: string) {
  return page.getByRole('row').filter({ hasText: text })
}
