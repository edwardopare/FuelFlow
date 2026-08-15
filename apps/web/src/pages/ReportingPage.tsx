import {
  Activity,
  Banknote,
  BarChart3,
  Calendar,
  ClipboardCheck,
  Clock3,
  Download,
  Droplets,
  FileText,
  Filter,
  PackageCheck,
  RefreshCw,
  Search,
  Timer,
} from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { KpiCard } from '../components/KpiCard'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import { formatAccraDateTime, formatGhs } from '../lib/format'
import type { ResourceResponse, Station } from '../types/api'
import type { ReportResult } from '../types/operations'

type ReportType =
  | 'daily-sales'
  | 'stock-movements'
  | 'procurement'
  | 'reconciliation'
  | 'supplier-performance'
  | 'shift-attendance'
  | 'user-activity'

type ReportRow = ReportResult['rows'][number]

type FilterDefinition = {
  key: string
  label: string
}

type ReportConfiguration = {
  label: string
  description: string
  filters: FilterDefinition[]
}

type Kpi = {
  label: string
  value: string
  detail: string
  tone: 'blue' | 'green' | 'amber' | 'slate'
  icon: ReactNode
}

type ChartDatum = {
  label: string
  value: number
}

type ChartModel = {
  title: string
  description: string
  format: 'count' | 'ghs' | 'litres' | 'minutes' | 'percent'
  data: ChartDatum[]
}

const reportConfigurations: Record<ReportType, ReportConfiguration> = {
  'daily-sales': {
    label: 'Daily sales',
    description: 'Revenue, fuel volume, transactions, products, and payment methods.',
    filters: [
      { key: 'product', label: 'Product' },
      { key: 'payment', label: 'Payment method' },
      { key: 'status', label: 'Status' },
    ],
  },
  'stock-movements': {
    label: 'Stock movements',
    description: 'Tank receipts, sales issues, transfers, adjustments, and balances.',
    filters: [
      { key: 'product', label: 'Product' },
      { key: 'type', label: 'Movement type' },
      { key: 'tank', label: 'Tank' },
    ],
  },
  procurement: {
    label: 'Procurement',
    description: 'Purchase order value, suppliers, status, and expected deliveries.',
    filters: [
      { key: 'supplier', label: 'Supplier' },
      { key: 'status', label: 'PO status' },
    ],
  },
  reconciliation: {
    label: 'Reconciliation',
    description: 'Sales, tank variance, cash variance, and sign-off status.',
    filters: [{ key: 'status', label: 'Status' }],
  },
  'supplier-performance': {
    label: 'Supplier performance',
    description: 'On-time delivery, quantity accuracy, quality, and activity.',
    filters: [{ key: 'status', label: 'Supplier status' }],
  },
  'shift-attendance': {
    label: 'Shift attendance',
    description: 'Scheduled and actual shift times, lateness, worked time, and overtime.',
    filters: [
      { key: 'attendant', label: 'Pump Attendant' },
      { key: 'attendance', label: 'Attendance' },
      { key: 'status', label: 'Shift status' },
    ],
  },
  'user-activity': {
    label: 'User activity',
    description: 'Audited user actions, affected stations, reasons, and request IDs.',
    filters: [
      { key: 'user', label: 'User' },
      { key: 'action', label: 'Action' },
    ],
  },
}

const reportTypes = Object.keys(reportConfigurations) as ReportType[]
const chartColors = ['#2563eb', '#0d9488', '#d97706', '#7c3aed', '#e11d48', '#0891b2']
const emptyReportRows: ReportRow[] = []
const today = inputDate(new Date())

function inputDate(date: Date): string {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 10)
}

function numberValue(value: unknown): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

function average(values: number[]): number {
  if (values.length === 0) return 0
  return values.reduce((sum, value) => sum + value, 0) / values.length
}

function titleCase(value: string): string {
  return value
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function statusTone(value: unknown): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  const status = String(value)
  if (['active', 'approved', 'closed', 'confirmed', 'on_time', 'paid', 'received', 'reconciled', 'sent'].includes(status)) return 'success'
  if (['cancelled', 'inactive', 'late', 'rejected', 'reversed'].includes(status)) return 'danger'
  if (status.includes('pending') || ['draft', 'not_started', 'scheduled'].includes(status)) return 'warning'
  return 'info'
}

function formatMetric(value: number, format: ChartModel['format']): string {
  if (format === 'ghs') return formatGhs(value)
  if (format === 'litres') return `${value.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`
  if (format === 'minutes') return `${value.toLocaleString('en-GH', { maximumFractionDigits: 1 })} min`
  if (format === 'percent') return `${value.toLocaleString('en-GH', { maximumFractionDigits: 1 })}%`
  return value.toLocaleString('en-GH', { maximumFractionDigits: 0 })
}

function uniqueOptions(rows: ReportRow[], key: string): string[] {
  return Array.from(new Set(rows
    .map((row) => row[key])
    .filter((value): value is string | number => value !== null && value !== undefined && value !== '')
    .map(String)))
    .toSorted((left, right) => left.localeCompare(right))
}

function groupValues(
  rows: ReportRow[],
  labelFor: (row: ReportRow) => string,
  valueFor: (row: ReportRow) => number,
): ChartDatum[] {
  const grouped = new Map<string, number>()
  for (const row of rows) {
    const label = labelFor(row) || 'Unknown'
    grouped.set(label, (grouped.get(label) ?? 0) + valueFor(row))
  }
  return Array.from(grouped, ([label, value]) => ({ label, value }))
}

function buildChart(type: ReportType, rows: ReportRow[]): ChartModel {
  if (type === 'daily-sales') {
    return {
      title: 'Revenue trend',
      description: 'Gross sales value by business date.',
      format: 'ghs',
      data: groupValues(rows, (row) => String(row.date ?? '').slice(0, 10), (row) => numberValue(row.amount_ghs))
        .toSorted((left, right) => left.label.localeCompare(right.label))
        .slice(-10),
    }
  }
  if (type === 'stock-movements') {
    return {
      title: 'Volume by movement type',
      description: 'Absolute fuel volume moved in the selected period.',
      format: 'litres',
      data: groupValues(rows, (row) => titleCase(String(row.type ?? 'Unknown')), (row) => Math.abs(numberValue(row.quantity_litres)))
        .toSorted((left, right) => right.value - left.value),
    }
  }
  if (type === 'procurement') {
    return {
      title: 'Purchase order value by status',
      description: 'GHS value currently represented by each PO state.',
      format: 'ghs',
      data: groupValues(rows, (row) => titleCase(String(row.status ?? 'Unknown')), (row) => numberValue(row.total_ghs))
        .toSorted((left, right) => right.value - left.value),
    }
  }
  if (type === 'reconciliation') {
    return {
      title: 'Reconciled sales trend',
      description: 'Sales value recorded for each reconciliation date.',
      format: 'ghs',
      data: groupValues(rows, (row) => String(row.date ?? ''), (row) => numberValue(row.sales_value_ghs))
        .toSorted((left, right) => left.label.localeCompare(right.label))
        .slice(-10),
    }
  }
  if (type === 'supplier-performance') {
    return {
      title: 'Supplier on-time performance',
      description: 'On-time delivery percentage by supplier.',
      format: 'percent',
      data: rows
        .map((row) => ({
          label: String(row.supplier ?? 'Unknown'),
          value: numberValue(row.on_time_percent),
        }))
        .toSorted((left, right) => right.value - left.value)
      .slice(0, 10),
    }
  }
  if (type === 'shift-attendance') {
    return {
      title: 'Late minutes by Pump Attendant',
      description: 'Total late arrival time in the selected report period.',
      format: 'minutes',
      data: groupValues(
        rows,
        (row) => String(row.attendant ?? 'Unknown'),
        (row) => numberValue(row.late_minutes),
      )
        .toSorted((left, right) => right.value - left.value)
        .slice(0, 10),
    }
  }
  return {
    title: 'Most frequent audited actions',
    description: 'Top system activities in the selected period.',
    format: 'count',
    data: groupValues(rows, (row) => titleCase(String(row.action ?? 'Unknown')), () => 1)
      .toSorted((left, right) => right.value - left.value)
      .slice(0, 10),
  }
}

function breakdownKey(type: ReportType): string {
  if (type === 'daily-sales') return 'payment'
  if (type === 'stock-movements') return 'type'
  if (type === 'procurement' || type === 'reconciliation' || type === 'supplier-performance') return 'status'
  if (type === 'shift-attendance') return 'attendance'
  return 'user'
}

function buildKpis(type: ReportType, rows: ReportRow[]): Kpi[] {
  if (type === 'daily-sales') {
    const revenue = rows.reduce((sum, row) => sum + numberValue(row.amount_ghs), 0)
    const litres = rows.reduce((sum, row) => sum + numberValue(row.litres), 0)
    return [
      { label: 'Gross sales', value: formatGhs(revenue), detail: 'Revenue in the current report view', tone: 'blue', icon: <Banknote className="h-5 w-5" /> },
      { label: 'Fuel sold', value: `${litres.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`, detail: 'Confirmed transaction volume', tone: 'green', icon: <Droplets className="h-5 w-5" /> },
      { label: 'Transactions', value: rows.length.toLocaleString('en-GH'), detail: 'Receipts matching the filters', tone: 'slate', icon: <FileText className="h-5 w-5" /> },
      { label: 'Average sale', value: formatGhs(rows.length ? revenue / rows.length : 0), detail: 'Average value per transaction', tone: 'amber', icon: <BarChart3 className="h-5 w-5" /> },
    ]
  }
  if (type === 'stock-movements') {
    const totalVolume = rows.reduce((sum, row) => sum + Math.abs(numberValue(row.quantity_litres)), 0)
    const latestBalance = rows.length ? numberValue(rows[0]?.balance_litres) : 0
    return [
      { label: 'Movements', value: rows.length.toLocaleString('en-GH'), detail: 'Stock events matching the filters', tone: 'blue', icon: <Activity className="h-5 w-5" /> },
      { label: 'Volume moved', value: `${totalVolume.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`, detail: 'Absolute received and issued volume', tone: 'green', icon: <Droplets className="h-5 w-5" /> },
      { label: 'Movement types', value: uniqueOptions(rows, 'type').length.toLocaleString('en-GH'), detail: 'Distinct operational movement types', tone: 'slate', icon: <Filter className="h-5 w-5" /> },
      { label: 'Latest balance', value: `${latestBalance.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`, detail: 'Balance on the newest filtered row', tone: 'amber', icon: <BarChart3 className="h-5 w-5" /> },
    ]
  }
  if (type === 'procurement') {
    const total = rows.reduce((sum, row) => sum + numberValue(row.total_ghs), 0)
    const awaiting = rows.filter((row) => ['draft', 'pending_approval', 'approved'].includes(String(row.status))).length
    const completed = rows.filter((row) => ['paid', 'sent', 'received', 'closed'].includes(String(row.status))).length
    return [
      { label: 'PO value', value: formatGhs(total), detail: 'Total value in the selected scope', tone: 'blue', icon: <Banknote className="h-5 w-5" /> },
      { label: 'Purchase orders', value: rows.length.toLocaleString('en-GH'), detail: 'Orders matching the filters', tone: 'slate', icon: <PackageCheck className="h-5 w-5" /> },
      { label: 'Awaiting action', value: awaiting.toLocaleString('en-GH'), detail: 'Draft, pending, or awaiting payment', tone: 'amber', icon: <Calendar className="h-5 w-5" /> },
      { label: 'Progressed', value: completed.toLocaleString('en-GH'), detail: 'Paid, sent, received, or closed', tone: 'green', icon: <ClipboardCheck className="h-5 w-5" /> },
    ]
  }
  if (type === 'reconciliation') {
    const salesValue = rows.reduce((sum, row) => sum + numberValue(row.sales_value_ghs), 0)
    const salesLitres = rows.reduce((sum, row) => sum + numberValue(row.sales_litres), 0)
    const tankVariance = rows.reduce((sum, row) => sum + numberValue(row.tank_variance_litres), 0)
    const cashVariance = rows.reduce((sum, row) => sum + numberValue(row.cash_variance_ghs), 0)
    return [
      { label: 'Sales value', value: formatGhs(salesValue), detail: 'Value covered by reconciliations', tone: 'blue', icon: <Banknote className="h-5 w-5" /> },
      { label: 'Sales volume', value: `${salesLitres.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`, detail: 'Fuel covered by reconciliations', tone: 'green', icon: <Droplets className="h-5 w-5" /> },
      { label: 'Tank variance', value: `${tankVariance.toLocaleString('en-GH', { maximumFractionDigits: 2 })} L`, detail: 'Net physical-versus-book variance', tone: 'amber', icon: <BarChart3 className="h-5 w-5" /> },
      { label: 'Cash variance', value: formatGhs(cashVariance), detail: 'Net expected-versus-counted cash', tone: 'slate', icon: <ClipboardCheck className="h-5 w-5" /> },
    ]
  }
  if (type === 'supplier-performance') {
    const onTime = rows.map((row) => numberValue(row.on_time_percent))
    const accuracy = rows.map((row) => numberValue(row.quantity_accuracy_percent))
    const quality = rows.map((row) => numberValue(row.quality_rating))
    return [
      { label: 'Suppliers', value: rows.length.toLocaleString('en-GH'), detail: 'Suppliers matching the filters', tone: 'blue', icon: <PackageCheck className="h-5 w-5" /> },
      { label: 'Average on-time', value: `${average(onTime).toFixed(1)}%`, detail: 'Average delivery punctuality', tone: 'green', icon: <Calendar className="h-5 w-5" /> },
      { label: 'Quantity accuracy', value: `${average(accuracy).toFixed(1)}%`, detail: 'Average delivered quantity accuracy', tone: 'amber', icon: <BarChart3 className="h-5 w-5" /> },
      { label: 'Quality rating', value: average(quality).toFixed(1), detail: 'Average supplier quality score', tone: 'slate', icon: <ClipboardCheck className="h-5 w-5" /> },
    ]
  }
  if (type === 'shift-attendance') {
    const onTime = rows.filter((row) => row.attendance === 'on_time').length
    const late = rows.filter((row) => row.attendance === 'late').length
    const overtime = rows.filter((row) => numberValue(row.overtime_minutes) > 0).length
    const workedMinutes = rows.reduce((sum, row) => sum + numberValue(row.worked_minutes), 0)
    return [
      { label: 'Assigned shifts', value: rows.length.toLocaleString('en-GH'), detail: 'Shifts in the selected date and station scope', tone: 'blue', icon: <Calendar className="h-5 w-5" /> },
      { label: 'On-time starts', value: onTime.toLocaleString('en-GH'), detail: 'Attendants who started at or before schedule', tone: 'green', icon: <ClipboardCheck className="h-5 w-5" /> },
      { label: 'Late starts', value: late.toLocaleString('en-GH'), detail: 'Actual starts after the manager schedule', tone: 'amber', icon: <Clock3 className="h-5 w-5" /> },
      { label: 'Overtime shifts', value: overtime.toLocaleString('en-GH'), detail: `${workedMinutes.toLocaleString('en-GH', { maximumFractionDigits: 1 })} total worked minutes`, tone: 'slate', icon: <Timer className="h-5 w-5" /> },
    ]
  }
  const users = uniqueOptions(rows, 'user')
  const actions = uniqueOptions(rows, 'action')
  const withReasons = rows.filter((row) => row.reason).length
  return [
    { label: 'Audit events', value: rows.length.toLocaleString('en-GH'), detail: 'Events matching the filters', tone: 'blue', icon: <Activity className="h-5 w-5" /> },
    { label: 'Active users', value: users.length.toLocaleString('en-GH'), detail: 'Distinct actors in this view', tone: 'green', icon: <FileText className="h-5 w-5" /> },
    { label: 'Action types', value: actions.length.toLocaleString('en-GH'), detail: 'Distinct audited activities', tone: 'slate', icon: <Filter className="h-5 w-5" /> },
    { label: 'Reasons recorded', value: withReasons.toLocaleString('en-GH'), detail: 'Events with supporting reasons', tone: 'amber', icon: <ClipboardCheck className="h-5 w-5" /> },
  ]
}

function HorizontalBarChart({ model }: { model: ChartModel }) {
  const maximum = Math.max(...model.data.map((datum) => datum.value), 0)
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <header>
        <h2 className="font-bold">{model.title}</h2>
        <p className="mt-1 text-sm text-slate-500">{model.description}</p>
      </header>
      {model.data.length === 0 ? (
        <div className="grid min-h-64 place-items-center text-sm text-slate-500">No data is available for this visualization.</div>
      ) : (
        <div aria-label={model.title} className="mt-6 space-y-4" role="img">
          {model.data.map((datum) => (
            <div className="grid grid-cols-[minmax(100px,1fr)_minmax(150px,3fr)_auto] items-center gap-3" key={datum.label}>
              <span className="truncate text-xs font-semibold text-slate-600" title={datum.label}>{datum.label}</span>
              <span className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                <span className="block h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-500" style={{ width: `${maximum ? Math.max((datum.value / maximum) * 100, 2) : 0}%` }} />
              </span>
              <span className="min-w-16 text-right text-xs font-bold text-slate-700">{formatMetric(datum.value, model.format)}</span>
            </div>
          ))}
        </div>
      )}
    </article>
  )
}

function BreakdownChart({
  title,
  rows,
  groupKey,
}: {
  title: string
  rows: ReportRow[]
  groupKey: string
}) {
  const data = groupValues(rows, (row) => titleCase(String(row[groupKey] ?? 'Unknown')), () => 1)
    .toSorted((left, right) => right.value - left.value)
    .slice(0, 6)
  const total = data.reduce((sum, item) => sum + item.value, 0)
  let cursor = 0
  const gradientStops = data.map((item, index) => {
    const start = cursor
    cursor += total ? (item.value / total) * 100 : 0
    return `${chartColors[index % chartColors.length]} ${start}% ${cursor}%`
  })
  const background = total
    ? `conic-gradient(${gradientStops.join(', ')})`
    : '#e2e8f0'

  return (
    <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <header>
        <h2 className="font-bold">{title}</h2>
        <p className="mt-1 text-sm text-slate-500">Distribution across the current filtered view.</p>
      </header>
      <div className="mt-6 flex min-h-64 flex-col items-center justify-center gap-6 sm:flex-row">
        <div aria-label={`${title}: ${total} total records`} className="relative h-36 w-36 shrink-0 rounded-full" role="img" style={{ background }}>
          <div className="absolute inset-5 grid place-items-center rounded-full bg-white text-center">
            <div><p className="text-2xl font-bold">{total.toLocaleString('en-GH')}</p><p className="text-xs text-slate-500">records</p></div>
          </div>
        </div>
        <div className="w-full space-y-3">
          {data.length === 0 ? <p className="text-center text-sm text-slate-500">No breakdown data.</p> : data.map((item, index) => (
            <div className="flex items-center gap-3 text-sm" key={item.label}>
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: chartColors[index % chartColors.length] }} />
              <span className="min-w-0 flex-1 truncate text-slate-600" title={item.label}>{item.label}</span>
              <span className="font-bold">{item.value.toLocaleString('en-GH')}</span>
              <span className="w-12 text-right text-xs text-slate-500">{total ? Math.round((item.value / total) * 100) : 0}%</span>
            </div>
          ))}
        </div>
      </div>
    </article>
  )
}

function ReportCell({
  column,
  value,
  stationNames,
}: {
  column: string
  value: string | number | null
  stationNames: Map<string, string>
}) {
  if (value === null || value === '') return <>-</>
  if (column === 'status') return <StatusBadge label={titleCase(String(value))} tone={statusTone(value)} />
  if (column === 'attendance') return <StatusBadge label={titleCase(String(value))} tone={statusTone(value)} />
  if (column === 'station_id') return <>{stationNames.get(String(value)) ?? String(value)}</>
  if (['actual_end', 'actual_start', 'scheduled_end', 'scheduled_start'].includes(column)) return <>{formatAccraDateTime(String(value))}</>
  if (column.endsWith('_ghs')) return <>{formatGhs(numberValue(value))}</>
  if (column.includes('litres')) return <>{`${numberValue(value).toLocaleString('en-GH', { maximumFractionDigits: 3 })} L`}</>
  if (column.endsWith('_minutes')) return <>{`${numberValue(value).toLocaleString('en-GH', { maximumFractionDigits: 1 })} min`}</>
  if (column.endsWith('_percent')) return <>{`${numberValue(value).toLocaleString('en-GH', { maximumFractionDigits: 1 })}%`}</>
  return <>{titleCase(String(value))}</>
}

export default function ReportingPage() {
  const [type, setType] = useState<ReportType>('daily-sales')
  const [from, setFrom] = useState(inputDate(new Date(Date.now() - 30 * 86_400_000)))
  const [to, setTo] = useState(today)
  const [stationId, setStationId] = useState('')
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const configuration = reportConfigurations[type]
  const hasDateRange = type !== 'supplier-performance'
  const reportParams = new URLSearchParams({
    type,
    ...(hasDateRange ? { from, to } : {}),
    ...(stationId ? { station_id: stationId } : {}),
  })
  const report = useQuery({
    queryKey: ['reports', type, from, to, stationId],
    queryFn: () => apiFetch<ResourceResponse<ReportResult>>(`/api/v1/reports?${reportParams.toString()}`),
  })
  const stations = useQuery({
    queryKey: ['stations'],
    queryFn: () => apiFetch<ResourceResponse<Station[]>>('/api/v1/stations'),
  })
  const result = report.data?.data
  const rows = result?.rows ?? emptyReportRows
  const stationNames = useMemo(
    () => new Map((stations.data?.data ?? []).map((station) => [station.id, station.name])),
    [stations.data?.data],
  )
  const visibleRows = useMemo(() => {
    const normalizedSearch = search.trim().toLowerCase()
    return rows.filter((row) => {
      for (const [key, selected] of Object.entries(filters)) {
        if (selected && String(row[key] ?? '') !== selected) return false
      }
      if (!normalizedSearch) return true
      return Object.values(row).some((value) =>
        String(value ?? '').toLowerCase().includes(normalizedSearch))
    })
  }, [filters, rows, search])
  const kpis = useMemo(() => buildKpis(type, visibleRows), [type, visibleRows])
  const chart = useMemo(() => buildChart(type, visibleRows), [type, visibleRows])
  const reportError = report.error instanceof ApiError
    ? report.error.message
    : report.error instanceof Error
      ? report.error.message
      : null

  const changeType = (nextType: ReportType) => {
    setType(nextType)
    setFilters({})
    setSearch('')
  }
  const applyDatePreset = (days: number | 'ytd') => {
    const end = new Date()
    const start = days === 'ytd'
      ? new Date(end.getFullYear(), 0, 1)
      : new Date(end.getTime() - (days - 1) * 86_400_000)
    setFrom(inputDate(start))
    setTo(inputDate(end))
  }
  const resetFilters = () => {
    setStationId('')
    setSearch('')
    setFilters({})
    setFrom(inputDate(new Date(Date.now() - 30 * 86_400_000)))
    setTo(today)
  }
  const exportCsv = () => {
    if (!result) return
    const csv = [
      result.columns.join(','),
      ...visibleRows.map((row) =>
        result.columns.map((column) => `"${String(row[column] ?? '').replaceAll('"', '""')}"`).join(',')),
    ]
    const url = URL.createObjectURL(new Blob([csv.join('\n')], { type: 'text/csv' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `fuelflow-${type}-${today}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600"><BarChart3 className="h-5 w-5" /></span>
          <div><p className="text-sm font-medium text-blue-600">FuelFlow FSMS</p><h1 className="mt-0.5 text-2xl font-bold tracking-tight">Reporting dashboard</h1><p className="mt-2 max-w-3xl text-sm text-slate-500">Explore live operational and financial performance with visual summaries, flexible filters, and GHS exports.</p></div>
        </div>
        <button className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50" disabled={!result || visibleRows.length === 0} onClick={exportCsv} type="button"><Download className="h-4 w-4" />Export filtered CSV</button>
      </header>

      <nav aria-label="Report views" className="mt-6 flex gap-2 overflow-x-auto rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
        {reportTypes.map((reportType) => (
          <button aria-current={type === reportType ? 'page' : undefined} className={`whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors ${type === reportType ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'}`} key={reportType} onClick={() => changeType(reportType)} type="button">
            {reportConfigurations[reportType].label}
          </button>
        ))}
      </nav>

      <section aria-label="Report filters" className="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
          <div><div className="flex items-center gap-2"><Filter className="h-4 w-4 text-blue-600" /><h2 className="font-bold">{configuration.label} filters</h2></div><p className="mt-1 text-sm text-slate-500">{configuration.description}</p></div>
          {hasDateRange ? <div className="flex flex-wrap gap-2" aria-label="Quick date ranges">
            <button className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50" onClick={() => applyDatePreset(7)} type="button">7 days</button>
            <button className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50" onClick={() => applyDatePreset(30)} type="button">30 days</button>
            <button className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50" onClick={() => applyDatePreset(90)} type="button">90 days</button>
            <button className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50" onClick={() => applyDatePreset('ytd')} type="button">Year to date</button>
          </div> : null}
        </div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label><span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Station</span><select className="form-input" onChange={(event) => setStationId(event.target.value)} value={stationId}><option value="">All authorized stations</option>{(stations.data?.data ?? []).map((station) => <option key={station.id} value={station.id}>{station.code} - {station.name}</option>)}</select></label>
          {hasDateRange ? <><label><span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">From</span><input className="form-input" max={to} onChange={(event) => setFrom(event.target.value)} type="date" value={from} /></label><label><span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">To</span><input className="form-input" min={from} onChange={(event) => setTo(event.target.value)} type="date" value={to} /></label></> : null}
          <label><span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search results</span><span className="relative block"><Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" /><input className="form-input pl-9" onChange={(event) => setSearch(event.target.value)} placeholder="Search any report field" type="search" value={search} /></span></label>
          {configuration.filters.map((filter) => <label key={filter.key}><span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">{filter.label}</span><select className="form-input" onChange={(event) => setFilters((current) => ({ ...current, [filter.key]: event.target.value }))} value={filters[filter.key] ?? ''}><option value="">All {filter.label.toLowerCase()} values</option>{uniqueOptions(rows, filter.key).map((option) => <option key={option} value={option}>{titleCase(option)}</option>)}</select></label>)}
          <div className="flex items-end"><button className="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50" onClick={resetFilters} type="button"><RefreshCw className="h-4 w-4" />Reset filters</button></div>
        </div>
      </section>

      {reportError ? <p className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{reportError}</p> : null}

      <section aria-label="Report summary" className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map((kpi) => <KpiCard detail={kpi.detail} icon={kpi.icon} key={kpi.label} label={kpi.label} tone={kpi.tone} value={kpi.value} />)}
      </section>

      <section className="mt-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
        <HorizontalBarChart model={chart} />
        <BreakdownChart groupKey={breakdownKey(type)} rows={visibleRows} title={`${configuration.label} breakdown`} />
      </section>

      <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header className="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 className="font-bold">Report details</h2><p aria-live="polite" className="mt-1 text-sm text-slate-500">Showing {Math.min(visibleRows.length, 100).toLocaleString('en-GH')} of {visibleRows.length.toLocaleString('en-GH')} filtered rows</p></div>
          {result ? <p className="text-xs text-slate-500">Generated {formatAccraDateTime(result.generated_at)}</p> : null}
        </header>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr>{(result?.columns ?? []).map((column) => <th className="px-5 py-3" key={column}>{titleCase(column)}</th>)}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {report.isLoading ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={result?.columns.length ?? 1}>Loading report dashboard...</td></tr> : visibleRows.length === 0 ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={result?.columns.length ?? 1}>No report rows match the selected filters.</td></tr> : visibleRows.slice(0, 100).map((row, index) => <tr className="hover:bg-slate-50/70" key={`${String(row.request_id ?? row.receipt ?? row.po_number ?? row.date ?? 'row')}-${index}`}>{(result?.columns ?? []).map((column) => <td className="whitespace-nowrap px-5 py-4" key={column}><ReportCell column={column} stationNames={stationNames} value={row[column]} /></td>)}</tr>)}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
