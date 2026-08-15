const ghsFormatter = new Intl.NumberFormat('en-GH', {
  style: 'currency',
  currency: 'GHS',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const dateTimeFormatter = new Intl.DateTimeFormat('en-GH', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: 'Africa/Accra',
})

const dateFormatter = new Intl.DateTimeFormat('en-GH', {
  dateStyle: 'medium',
  timeZone: 'Africa/Accra',
})

const timeFormatter = new Intl.DateTimeFormat('en-GH', {
  timeStyle: 'short',
  timeZone: 'Africa/Accra',
})

const dateKeyFormatter = new Intl.DateTimeFormat('en-GB', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  timeZone: 'Africa/Accra',
})

export function formatGhs(value: number | string): string {
  return ghsFormatter.format(Number(value))
}

export function formatAccraDateTime(value: string): string {
  return dateTimeFormatter.format(new Date(value))
}

export function formatAccraDate(value: string): string {
  return dateFormatter.format(new Date(`${value}T00:00:00Z`))
}

export function formatAccraTime(value: string): string {
  return timeFormatter.format(new Date(value))
}

export function accraDateKey(value: string | number | Date): string {
  const parts = dateKeyFormatter.formatToParts(new Date(value))
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((item) => item.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')}`
}
