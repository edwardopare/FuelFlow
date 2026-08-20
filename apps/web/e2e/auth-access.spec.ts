import { expect, test } from '@playwright/test'
import { demoPassword, loginTenant, loginVendor, logout } from './helpers'

test('tenant authentication, error handling, navigation, and logout', async ({ page }) => {
  await page.goto('/modules/settings')
  await expect(page).toHaveURL(/\/login$/)

  await page.getByLabel('Email address').fill('admin@fuelflow.local')
  await page.locator('#password').fill('not-the-password')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('alert')).toBeVisible()

  await page.locator('#password').fill(demoPassword)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('heading', { name: 'FuelFlow dashboard' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Audit Log' })).toBeVisible()
  await expect(page.getByText('GHS', { exact: true }).first()).toBeVisible()

  await logout(page)
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
})

test('vendor authentication remains isolated from the tenant portal', async ({ page }) => {
  await page.goto('/vendor/companies')
  await expect(page).toHaveURL(/\/vendor\/login$/)

  await loginVendor(page)
  await expect(page.getByRole('heading', { name: 'Super User dashboard' })).toBeVisible()
  await page.getByRole('link', { name: 'Companies' }).click()
  await expect(page.getByRole('heading', { name: 'Companies and licenses' })).toBeVisible()

  await page.getByRole('button', { name: 'Log out' }).click()
  await expect(page).toHaveURL(/\/vendor\/login$/)
})

test('role navigation hides administrator functions and guards direct routes', async ({ page }) => {
  await loginTenant(page, 'manager@fuelflow.local')
  await expect(page.getByRole('link', { name: 'Procurement' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Audit Log' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Sale Entry' })).toHaveCount(0)

  await page.goto('/audit')
  await expect(page).toHaveURL(/\/$/, { timeout: 60_000 })
  await expect(page.getByRole('heading', { name: 'FuelFlow dashboard' })).toBeVisible()
})
