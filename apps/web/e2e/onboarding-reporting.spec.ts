import { expect, test } from '@playwright/test'
import { loginTenant, loginVendor, logout, rowWithText } from './helpers'

const unique = `${Date.now()}-${Math.floor(Math.random() * 10_000)}`

test('vendor onboards a licensed company with its initial administrator', async ({ page }) => {
  const companyName = `E2E Fuel Company ${unique}`
  const email = `e2e-vendor-admin-${unique}@example.test`

  await loginVendor(page)
  await page.getByRole('link', { name: 'Companies' }).click()
  await page.getByRole('button', { name: 'Onboard company' }).click()
  const dialog = page.getByRole('dialog', { name: 'Onboard a company' })

  await dialog.getByLabel(/Registered company name/).fill(companyName)
  await dialog.getByLabel(/Registration number/).first().fill(`REG-${unique}`)
  await dialog.getByLabel(/Contact email/).fill(`contact-${unique}@example.test`)
  await dialog.getByLabel(/Contact phone/).fill('+233200000001')
  await dialog.getByLabel(/Registered address/).fill('Accra, Ghana')
  await dialog.getByLabel(/Number of months or years/).fill('18')
  await dialog.getByLabel(/Tenure unit/).selectOption('months')

  await dialog.getByLabel(/Station name/).fill(`E2E Station ${unique}`)
  await dialog.getByLabel(/Station code/).fill(`E2E-${unique.slice(-8)}`)
  await dialog.getByLabel(/Station number/).fill(`STN-${unique.slice(-8)}`)
  await dialog.getByLabel(/Station address/).fill('Airport Road, Accra')

  await dialog.getByLabel('Full name').fill('E2E Company Administrator')
  await dialog.getByLabel('Email address').fill(email)
  await dialog.getByLabel(/Temporary password/).fill('CompanyTemp1!')
  await dialog.getByLabel(/Confirm password/).fill('CompanyTemp1!')

  const created = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/vendor/organizations')
      && response.request().method() === 'POST',
  )
  await dialog.getByRole('button', { name: 'Create company and accounts' }).click()
  await expect((await created).ok()).toBeTruthy()

  await expect(page.getByRole('heading', { name: companyName })).toBeVisible()
  await expect(page.getByText('License: 18 months')).toBeVisible()
  await expect(page.getByText(email)).toBeVisible()
})

test('administrator creates a station and user, then the user completes first login', async ({ page }) => {
  const stationName = `E2E User Station ${unique}`
  const stationCode = `USR-${unique.slice(-8)}`
  const userEmail = `e2e-attendant-${unique}@example.test`
  const temporaryPassword = 'TemporaryPass1!'
  const permanentPassword = 'PermanentPass2!'

  await loginTenant(page, 'admin@fuelflow.local')
  await page.getByRole('link', { name: 'Stations' }).click()
  await page.getByRole('button', { name: 'Add station' }).click()
  const stationDialog = page.getByRole('dialog')
  await stationDialog.getByLabel(/Station code/).fill(stationCode)
  await stationDialog.getByLabel(/Station number/).fill(`STN-${unique.slice(-8)}`)
  await stationDialog.getByLabel(/Station name/).fill(stationName)
  await stationDialog.getByLabel(/Address/).fill('Tema Motorway, Ghana')
  await stationDialog.getByRole('button', { name: 'Create station' }).click()
  await expect(page.getByRole('heading', { name: stationName })).toBeVisible()

  await page.getByRole('link', { name: 'User Management' }).click()
  await page.getByRole('button', { name: 'Add user' }).click()
  const userDialog = page.getByRole('dialog', { name: 'Add a new user' })
  await userDialog.getByLabel('Full name').fill('E2E Pump Attendant')
  await userDialog.getByLabel('Email address / login username').fill(userEmail)
  await userDialog.getByLabel('FRD role').selectOption({ label: 'Cashier / Pump Attendant' })
  await userDialog.getByLabel('Station assignment').selectOption({ label: `${stationName} (${stationCode})` })
  await userDialog.getByLabel('Temporary password').fill(temporaryPassword)
  await userDialog.getByLabel('Confirm password').fill(temporaryPassword)
  await userDialog.getByRole('button', { name: 'Create user' }).click()
  await expect(rowWithText(page, userEmail)).toBeVisible()

  await logout(page)
  await loginTenant(page, userEmail, temporaryPassword)
  await expect(page).toHaveURL(/\/change-password$/)
  await page.getByLabel('New password', { exact: true }).fill(permanentPassword)
  await page.getByLabel('Confirm new password', { exact: true }).fill(permanentPassword)
  await page.getByLabel('Six-digit terminal PIN').fill('246810')
  await page.getByRole('button', { name: 'Save and continue' }).click()
  await expect(page.getByRole('heading', { name: 'Assigned shift' })).toBeVisible()
})

test('reporting renders visual filters, exports CSV, and persists a delivery schedule', async ({ page }) => {
  const scheduleName = `E2E daily sales ${unique}`

  await loginTenant(page, 'admin@fuelflow.local')
  await page.getByRole('link', { name: 'Reporting' }).click()
  await expect(page.getByRole('heading', { name: 'Reporting dashboard' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Report filters' })).toBeVisible()
  await page.getByRole('button', { name: '7 days' }).click()
  await expect(page.getByText(/Showing .* filtered rows/)).toBeVisible()
  const download = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export filtered CSV' }).click()
  await expect((await download).suggestedFilename()).toMatch(/^fuelflow-daily-sales-.*\.csv$/)

  await page.getByRole('link', { name: 'Report Schedules' }).click()
  await page.getByLabel(/Schedule name/).fill(scheduleName)
  await page.getByLabel(/Recipients/).fill('qa@example.test')
  await page.getByRole('button', { name: 'Save schedule' }).click()
  await expect(rowWithText(page, scheduleName)).toBeVisible()
})
