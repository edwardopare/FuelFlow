import { expect, test } from '@playwright/test'
import { loginTenant, logout, rowWithText } from './helpers'

test('purchase order proceeds through approval, payment, dispatch, and receiving', async ({ page }) => {
  test.slow()
  await loginTenant(page, 'manager@fuelflow.local')
  await page.getByRole('link', { name: 'Procurement' }).click()
  await page.getByRole('button', { name: 'Create PO request' }).click()
  const requestDialog = page.getByRole('dialog', { name: 'Create purchase order request' })
  await requestDialog.getByLabel(/Receiving station/).selectOption({ index: 1 })
  await requestDialog.getByLabel(/^Supplier \*/).selectOption({ index: 1 })
  await requestDialog.getByLabel(/Fuel product/).selectOption({ index: 1 })
  await requestDialog.getByLabel(/Target tank/).selectOption({ index: 1 })
  await requestDialog.getByLabel(/Quantity/).fill('100')
  await requestDialog.getByLabel(/Supplier price/).fill('13.90')
  await requestDialog.getByLabel(/Request notes/).fill('Playwright order-to-reconciliation verification')

  const requested = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/purchase-orders')
      && response.request().method() === 'POST',
  )
  await requestDialog.getByRole('button', { name: 'Submit to Administrator' }).click()
  const requestedResponse = await requested
  expect(requestedResponse.ok()).toBeTruthy()
  const order = (await requestedResponse.json()) as { data: { po_number: string } }
  const poNumber = order.data.po_number
  await expect(rowWithText(page, poNumber)).toContainText('Pending')

  await logout(page)
  await loginTenant(page, 'admin@fuelflow.local')
  await page.getByRole('link', { name: 'PO Approvals' }).click()
  const approvalRow = rowWithText(page, poNumber)
  await approvalRow.getByRole('button', { name: 'Approve' }).click()
  await expect(approvalRow).toContainText('Approved - payment due')

  await logout(page)
  await loginTenant(page, 'accountant@fuelflow.local')
  await page.getByRole('link', { name: 'PO Requests' }).click()
  const paymentRow = rowWithText(page, poNumber)
  await paymentRow.getByRole('button', { name: 'Mark paid' }).click()
  const paymentDialog = page.getByRole('dialog', { name: new RegExp(`Record payment for ${poNumber}`) })
  await paymentDialog.getByLabel('Bank / transaction reference').fill(`E2E-${Date.now()}`)
  await paymentDialog.getByLabel(/Payment receipt/).setInputFiles({
    name: 'payment-receipt.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    ),
  })
  const paid = page.waitForResponse((response) =>
    response.url().endsWith('/pay') && response.request().method() === 'POST',
  )
  await paymentDialog.getByRole('button', { name: 'Mark as paid' }).click()
  expect((await paid).ok()).toBeTruthy()
  await expect(paymentRow).toContainText('Paid')
  await expect(paymentRow.getByRole('link', { name: 'Receipt' })).toBeVisible()

  await logout(page)
  await loginTenant(page, 'manager@fuelflow.local')
  await page.getByRole('link', { name: 'Procurement' }).click()
  const dispatchRow = rowWithText(page, poNumber)
  await dispatchRow.getByRole('button', { name: 'Send to supplier' }).click()
  await expect(dispatchRow).toContainText('sent', { ignoreCase: true })

  await page.getByRole('link', { name: 'Fuel Receiving' }).click()
  await page.getByRole('button', { name: 'Record delivery' }).click()
  const deliveryDialog = page.getByRole('dialog')
  const poLine = deliveryDialog.getByLabel(/Sent PO line/)
  const poLineValue = await poLine.locator('option').filter({ hasText: poNumber }).getAttribute('value')
  expect(poLineValue).toBeTruthy()
  await poLine.selectOption(poLineValue as string)
  await deliveryDialog.getByLabel(/Truck registration/).fill('GT-1000-26')
  await deliveryDialog.getByLabel(/Driver name/).fill('E2E Driver')
  await deliveryDialog.getByLabel(/Waybill number/).fill(`WB-${Date.now()}`)
  await deliveryDialog.getByLabel(/Invoiced quantity/).fill('100')
  await deliveryDialog.getByLabel(/Pre-delivery dip/).fill('1000')
  await deliveryDialog.getByLabel(/Post-delivery dip/).fill('1100')
  await deliveryDialog.getByRole('button', { name: 'Record delivery' }).click()

  const deliveryRow = rowWithText(page, poNumber)
  await expect(deliveryRow).toContainText('pending confirmation', { ignoreCase: true })
  await deliveryRow.getByRole('button', { name: 'Confirm stock' }).click()
  await expect(deliveryRow).toContainText('confirmed', { ignoreCase: true })
})

test('attendant controls the timed shift and records a nozzle-scoped sale', async ({ page }) => {
  test.slow()
  const today = new Date().toISOString().slice(0, 10)
  await loginTenant(page, 'attendant@fuelflow.local')
  await expect(page.getByRole('heading', { name: 'Assigned shift' })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: /SH-/ }).first()).toBeVisible()
  let startButton = page.getByRole('button', { name: 'Start shift', exact: true })

  if (await startButton.count() === 0) {
    await logout(page)
    await loginTenant(page, 'manager@fuelflow.local')
    await page.getByRole('link', { name: 'Shift Management' }).click()
    await page.getByRole('button', { name: 'Schedule shift' }).click()
    const scheduleDialog = page.getByRole('dialog')
    await scheduleDialog.getByLabel(/^Station/).selectOption({ index: 1 })
    await scheduleDialog.getByLabel(/Attendant/).selectOption({ label: 'Kwame Asante' })
    await scheduleDialog.getByLabel(/^Pump/).selectOption({ index: 1 })
    await scheduleDialog.getByLabel(/Start date/).fill(today)
    await scheduleDialog.getByLabel(/End date/).fill(today)
    await scheduleDialog.getByLabel(/Daily start time/).fill('00:00')
    await scheduleDialog.getByLabel(/Daily end time/).fill('23:59')
    const scheduled = page.waitForResponse((response) =>
      response.url().endsWith('/api/v1/shifts')
        && response.request().method() === 'POST',
    )
    await scheduleDialog.getByRole('button', { name: 'Schedule shift' }).click()
    expect((await scheduled).ok()).toBeTruthy()
    await logout(page)
    await loginTenant(page, 'attendant@fuelflow.local')
    await expect(page.getByRole('row').filter({ hasText: /SH-/ }).first()).toBeVisible()
    startButton = page.getByRole('button', { name: 'Start shift', exact: true })
  }

  const shiftCode = (await page.getByText(/^SH-/).first().textContent())?.trim()
  expect(shiftCode).toBeTruthy()
  await startButton.click()
  const startDialog = page.getByRole('dialog', { name: 'Start shift' })
  await startDialog.getByRole('button', { name: 'Start shift now' }).click()
  await expect(page.getByText('Shift in progress')).toBeVisible()
  await expect(page.getByRole('timer')).toBeVisible()

  await page.getByRole('link', { name: 'Sale Entry' }).click()
  await page.getByRole('button', { name: 'Record sale' }).click()
  const saleDialog = page.getByRole('dialog')
  await saleDialog.getByLabel(/Open assigned shift/).selectOption({ index: 1 })
  await saleDialog.getByLabel(/Pump nozzle/).selectOption({ index: 1 })
  await saleDialog.getByLabel(/Litres sold/).fill('5')
  const saleResponse = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/sales')
      && response.request().method() === 'POST',
  )
  await saleDialog.getByRole('button', { name: 'Record sale' }).click()
  const recordedSale = await saleResponse
  expect(recordedSale.ok()).toBeTruthy()
  const sale = (await recordedSale.json()) as { data: { receipt_number: string } }
  await expect(rowWithText(page, sale.data.receipt_number)).toBeVisible()

  await page.getByRole('link', { name: 'Assigned Shift' }).click()
  await page.getByRole('button', { name: 'End shift', exact: true }).click()
  const endDialog = page.getByRole('dialog', { name: 'End shift' })
  await endDialog.getByLabel(/Counted cash/).fill('77.45')
  await endDialog.getByRole('button', { name: 'End shift now' }).click()
  await expect(page.getByText('Shift in progress')).toHaveCount(0)
  const historyRow = rowWithText(page, shiftCode as string)
  await expect(historyRow.getByRole('cell').nth(3)).not.toHaveText('—')
  await expect(historyRow).toContainText(/Late|On time/)
})

test('manager generates and signs off the daily reconciliation', async ({ page }) => {
  const today = new Date().toISOString().slice(0, 10)
  await loginTenant(page, 'manager@fuelflow.local')
  await page.getByRole('link', { name: 'Daily Reconciliation' }).click()
  await page.getByRole('button', { name: 'Generate reconciliation' }).click()
  const reconciliationDialog = page.getByRole('dialog')
  await reconciliationDialog.getByLabel(/Station/).selectOption({ index: 1 })
  const generated = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/reconciliations/generate')
      && response.request().method() === 'POST',
  )
  await reconciliationDialog.getByRole('button', { name: 'Generate reconciliation' }).click()
  const generatedResponse = await generated
  if (!generatedResponse.ok()) {
    const existing = rowWithText(page, today).filter({ hasText: 'reconciled' }).first()
    await expect(existing).toBeVisible()
    return
  }
  const reviewRow = rowWithText(page, today).filter({ hasText: 'in review' }).first()
  await expect(reviewRow).toBeVisible()
  await reviewRow.getByRole('button', { name: 'Sign off & lock' }).click()
  await expect(
    rowWithText(page, today).filter({ hasText: 'reconciled' }).first(),
  ).toBeVisible()
})
