import { describe, expect, it } from 'vitest'
import {
  accraDateKey,
  formatAccraDate,
  formatAccraDateTime,
  formatAccraTime,
  formatGhs,
} from './format'

describe('formatGhs', () => {
  it('always renders money in Ghana cedis with two decimal places', () => {
    const formatted = formatGhs(1234.5).replaceAll(/\s/g, '')

    expect(formatted).toMatch(/^(GH₵|GHS)1,234\.50$/)
  })
})

describe('formatAccraDateTime', () => {
  it('renders UTC timestamps in the Africa/Accra timezone', () => {
    expect(formatAccraDateTime('2026-08-06T17:30:00.000Z')).toContain('5:30')
  })

  it('renders business dates and times separately for dashboard tables', () => {
    expect(formatAccraDate('2026-08-08')).toContain('8 Aug 2026')
    expect(formatAccraTime('2026-08-08T08:15:00.000Z')).toContain('8:15')
  })

  it('returns a stable Accra business-date key', () => {
    expect(accraDateKey('2026-08-08T23:30:00.000Z')).toBe('2026-08-08')
  })
})
