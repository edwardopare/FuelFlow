import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import {
  Banknote,
  Building2,
  CalendarClock,
  ClipboardCheck,
  Droplets,
  Fuel,
  Gauge,
  Plus,
  ReceiptText,
  Settings,
  ShoppingCart,
  Truck,
  Warehouse,
  X,
} from 'lucide-react'
import {
  useMemo,
  useState,
  type ComponentType,
  type FormEvent,
} from 'react'
import { useOutletContext, useParams } from 'react-router-dom'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import { formatAccraDateTime, formatGhs } from '../lib/format'
import type {
  ListResponse,
  OperationalRecord,
} from '../types/operations'
import type { ResourceResponse, Station, User } from '../types/api'
import DashboardPage from './DashboardPage'
import PurchaseOrdersPage from './PurchaseOrdersPage'
import ReportingPage from './ReportingPage'

type ModuleDefinition = {
  title: string
  description: string
  endpoint: string
  actionLabel?: string
  icon: ComponentType<{ className?: string }>
  columns: Array<{ key: string; label: string; format?: 'ghs' | 'litres' | 'date' | 'status' }>
  fields?: FormField[]
}

type FormField = {
  key: string
  label: string
  type?: 'text' | 'email' | 'number' | 'date' | 'time' | 'datetime-local' | 'textarea' | 'select'
  required?: boolean
  placeholder?: string
  source?: 'stations' | 'products' | 'suppliers' | 'tanks' | 'pumps' | 'nozzles' | 'po-lines' | 'attendants' | 'open-shifts'
  options?: Array<{ value: string; label: string }>
}

type FormValues = Record<string, string>

const today = new Date().toISOString().slice(0, 10)
const nowLocal = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  .toISOString()
  .slice(0, 16)

const definitions: Record<string, ModuleDefinition> = {
  suppliers: {
    title: 'Supplier Management',
    description: 'Maintain supplier identity, contacts, tax and payment terms, pricing, and performance.',
    endpoint: '/api/v1/suppliers',
    actionLabel: 'Add supplier',
    icon: Building2,
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Supplier' },
      { key: 'contact_name', label: 'Contact' },
      { key: 'phone', label: 'Phone' },
      { key: 'payment_terms', label: 'Payment terms' },
      { key: 'on_time_percentage', label: 'On-time %' },
      { key: 'is_active', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'code', label: 'Supplier code', required: true, placeholder: 'e.g. BDC-002' },
      { key: 'name', label: 'Registered supplier name', required: true },
      { key: 'contact_name', label: 'Primary contact' },
      { key: 'email', label: 'Email address', type: 'email' },
      { key: 'phone', label: 'Phone number' },
      { key: 'tax_registration_number', label: 'Tax registration number' },
      { key: 'payment_terms', label: 'Payment terms', placeholder: 'e.g. Net 14 days' },
      { key: 'address', label: 'Registered address', type: 'textarea' },
      { key: 'bank_details', label: 'Bank details (encrypted)', type: 'textarea' },
    ],
  },
  products: {
    title: 'Fuel Product Management',
    description: 'Control fuel grades and effective-dated selling prices in Ghana cedis.',
    endpoint: '/api/v1/products',
    actionLabel: 'Add fuel product',
    icon: Droplets,
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Product' },
      { key: 'tank_grade', label: 'Tank grade' },
      { key: 'current_price', label: 'Current price / litre', format: 'ghs' },
      { key: 'price_effective_from', label: 'Effective from', format: 'date' },
      { key: 'is_active', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'code', label: 'Product code', required: true, placeholder: 'e.g. LPG' },
      { key: 'name', label: 'Product name', required: true },
      { key: 'tank_grade', label: 'Tank grade', required: true },
      { key: 'initial_price', label: 'Initial selling price (GHS/litre)', type: 'number', required: true },
      { key: 'effective_from', label: 'Price effective from', type: 'datetime-local', required: true },
      { key: 'reason', label: 'Price reason', type: 'textarea', required: true },
    ],
  },
  tanks: {
    title: 'Tank Management',
    description: 'Monitor book stock, physical dips, safe capacity thresholds, alerts, and transfers.',
    endpoint: '/api/v1/tanks',
    actionLabel: 'Add tank',
    icon: Warehouse,
    columns: [
      { key: 'code', label: 'Tank' },
      { key: 'station_name', label: 'Station' },
      { key: 'product_name', label: 'Product' },
      { key: 'book_stock_litres', label: 'Book stock', format: 'litres' },
      { key: 'capacity_litres', label: 'Capacity', format: 'litres' },
      { key: 'stock_percentage', label: 'Fill level' },
      { key: 'level_status', label: 'Level', format: 'status' },
    ],
    fields: [
      { key: 'station_id', label: 'Station', type: 'select', source: 'stations', required: true },
      { key: 'product_id', label: 'Fuel product', type: 'select', source: 'products', required: true },
      { key: 'code', label: 'Tank code', required: true },
      { key: 'name', label: 'Tank name', required: true },
      { key: 'tank_grade', label: 'Tank grade' },
      { key: 'capacity_litres', label: 'Capacity (litres)', type: 'number', required: true },
      { key: 'book_stock_litres', label: 'Opening book stock (litres)', type: 'number', required: true },
      { key: 'minimum_safe_litres', label: 'Minimum safe level', type: 'number', required: true },
      { key: 'maximum_safe_litres', label: 'Maximum safe level', type: 'number', required: true },
      {
        key: 'atg_enabled',
        label: 'Automatic tank gauge',
        type: 'select',
        options: [
          { value: 'true', label: 'Enabled' },
          { value: 'false', label: 'Manual dips only' },
        ],
      },
    ],
  },
  pumps: {
    title: 'Pump Management',
    description: 'Configure pumps and nozzle-to-tank links, meter readings, status, and maintenance.',
    endpoint: '/api/v1/pumps',
    actionLabel: 'Add pump',
    icon: Fuel,
    columns: [
      { key: 'code', label: 'Pump code' },
      { key: 'name', label: 'Pump' },
      { key: 'station_name', label: 'Station' },
      { key: 'nozzle_summary', label: 'Nozzles' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'station_id', label: 'Station', type: 'select', source: 'stations', required: true },
      { key: 'code', label: 'Pump code', required: true },
      { key: 'name', label: 'Pump name', required: true },
      { key: 'nozzle_code', label: 'First nozzle code', required: true },
      { key: 'tank_id', label: 'Connected tank', type: 'select', source: 'tanks', required: true },
      { key: 'current_meter_reading', label: 'Opening meter reading', type: 'number', required: true },
      { key: 'meter_maximum', label: 'Meter rollover maximum', type: 'number' },
    ],
  },
  procurement: {
    title: 'Procurement',
    description: 'Create purchase orders, submit for approval, approve, send, and track receipt status.',
    endpoint: '/api/v1/purchase-orders',
    actionLabel: 'Create purchase order',
    icon: ShoppingCart,
    columns: [
      { key: 'po_number', label: 'PO number' },
      { key: 'station_name', label: 'Station' },
      { key: 'supplier_name', label: 'Supplier' },
      { key: 'expected_delivery_date', label: 'Expected delivery' },
      { key: 'total', label: 'Total', format: 'ghs' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'station_id', label: 'Receiving station', type: 'select', source: 'stations', required: true },
      { key: 'supplier_id', label: 'Supplier', type: 'select', source: 'suppliers', required: true },
      { key: 'expected_delivery_date', label: 'Expected delivery date', type: 'date', required: true },
      { key: 'product_id', label: 'Fuel product', type: 'select', source: 'products', required: true },
      { key: 'target_tank_id', label: 'Target tank', type: 'select', source: 'tanks', required: true },
      { key: 'quantity_litres', label: 'Quantity (litres)', type: 'number', required: true },
      { key: 'unit_price', label: 'Supplier price (GHS/litre)', type: 'number', required: true },
      { key: 'notes', label: 'Purchase notes', type: 'textarea' },
    ],
  },
  receiving: {
    title: 'Fuel Receiving',
    description: 'Record waybills and vehicle details, capture pre/post dips, calculate variance, and confirm stock.',
    endpoint: '/api/v1/deliveries',
    actionLabel: 'Record delivery',
    icon: Truck,
    columns: [
      { key: 'po_number', label: 'Purchase order' },
      { key: 'supplier_name', label: 'Supplier' },
      { key: 'product_name', label: 'Product' },
      { key: 'tank_name', label: 'Tank' },
      { key: 'received_quantity_litres', label: 'Received', format: 'litres' },
      { key: 'variance_percentage', label: 'Variance %' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'po_line', label: 'Sent PO line', type: 'select', source: 'po-lines', required: true },
      { key: 'truck_number', label: 'Truck registration', required: true },
      { key: 'driver_name', label: 'Driver name', required: true },
      { key: 'waybill_number', label: 'Waybill number', required: true },
      { key: 'arrival_time', label: 'Arrival time', type: 'datetime-local', required: true },
      { key: 'invoiced_quantity_litres', label: 'Invoiced quantity (litres)', type: 'number', required: true },
      { key: 'pre_dip_litres', label: 'Pre-delivery dip (litres)', type: 'number', required: true },
      { key: 'post_dip_litres', label: 'Post-delivery dip (litres)', type: 'number', required: true },
      { key: 'variance_comment', label: 'Variance / receiving notes', type: 'textarea' },
    ],
  },
  sales: {
    title: 'Sale Management',
    description: 'Record fuel sales at the effective GHS price, payment method, stock issue, and receipt.',
    endpoint: '/api/v1/sales',
    actionLabel: 'Record sale',
    icon: ReceiptText,
    columns: [
      { key: 'receipt_number', label: 'Receipt' },
      { key: 'sold_at', label: 'Time', format: 'date' },
      { key: 'pump_name', label: 'Pump' },
      { key: 'product_name', label: 'Product' },
      { key: 'litres', label: 'Litres', format: 'litres' },
      { key: 'amount', label: 'Amount', format: 'ghs' },
      { key: 'payment_method', label: 'Payment' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'shift_id', label: 'Open assigned shift', type: 'select', source: 'open-shifts', required: true },
      { key: 'nozzle_id', label: 'Pump nozzle', type: 'select', source: 'nozzles', required: true },
      { key: 'litres', label: 'Litres sold', type: 'number', required: true },
      {
        key: 'payment_method',
        label: 'Payment method',
        type: 'select',
        required: true,
        options: [
          { value: 'cash', label: 'Cash' },
          { value: 'mobile_money', label: 'Mobile money' },
          { value: 'card', label: 'Card' },
          { value: 'credit', label: 'Credit account' },
          { value: 'fleet', label: 'Fleet account' },
        ],
      },
      { key: 'payment_reference', label: 'Payment reference' },
    ],
  },
  reconciliation: {
    title: 'Daily Reconciliation',
    description: 'Compare book stock, physical dips, sales, expected cash, counted cash, variances, and sign-off.',
    endpoint: '/api/v1/reconciliations',
    actionLabel: 'Generate reconciliation',
    icon: ClipboardCheck,
    columns: [
      { key: 'business_date', label: 'Business date' },
      { key: 'station_name', label: 'Station' },
      { key: 'sales_litres', label: 'Sales', format: 'litres' },
      { key: 'sales_value', label: 'Sales value', format: 'ghs' },
      { key: 'tank_variance_litres', label: 'Tank variance', format: 'litres' },
      { key: 'cash_variance', label: 'Cash variance', format: 'ghs' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'station_id', label: 'Station', type: 'select', source: 'stations', required: true },
      { key: 'business_date', label: 'Business date', type: 'date', required: true },
    ],
  },
  shifts: {
    title: 'Shift Management',
    description: 'Schedule recurring daily shifts across a date range with the same start and end time.',
    endpoint: '/api/v1/shifts',
    actionLabel: 'Schedule shift',
    icon: CalendarClock,
    columns: [
      { key: 'code', label: 'Shift' },
      { key: 'attendant_name', label: 'Attendant' },
      { key: 'pump_name', label: 'Pump' },
      { key: 'scheduled_start', label: 'Starts', format: 'date' },
      { key: 'scheduled_end', label: 'Ends', format: 'date' },
      { key: 'expected_cash', label: 'Expected cash', format: 'ghs' },
      { key: 'status', label: 'Status', format: 'status' },
    ],
    fields: [
      { key: 'station_id', label: 'Station', type: 'select', source: 'stations', required: true },
      { key: 'attendant_id', label: 'Attendant', type: 'select', source: 'attendants', required: true },
      { key: 'pump_id', label: 'Pump', type: 'select', source: 'pumps', required: true },
      { key: 'date_from', label: 'Start date', type: 'date', required: true },
      { key: 'date_to', label: 'End date', type: 'date', required: true },
      { key: 'start_time', label: 'Daily start time', type: 'time', required: true },
      { key: 'end_time', label: 'Daily end time', type: 'time', required: true },
      { key: 'notes', label: 'Shift notes', type: 'textarea' },
    ],
  },
}

function asText(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Active' : 'Inactive'
  return String(value)
}

function asArray(value: unknown): OperationalRecord[] {
  return Array.isArray(value) ? (value as OperationalRecord[]) : []
}

function statusTone(value: unknown): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  const status = String(value)
  if (['active', 'operational', 'confirmed', 'received', 'reconciled', 'true', 'open'].includes(status)) return 'success'
  if (['critical', 'inactive', 'rejected', 'cancelled', 'out_of_service', 'reversed'].includes(status)) return 'danger'
  if (['low', 'pending_approval', 'pending_confirmation', 'pending_signoff', 'under_maintenance'].includes(status)) return 'warning'
  return 'info'
}

function initialFormValues(slug: string): FormValues {
  return {
    effective_from: nowLocal,
    expected_delivery_date: new Date(Date.now() + 2 * 86_400_000).toISOString().slice(0, 10),
    arrival_time: nowLocal,
    business_date: today,
    atg_enabled: 'true',
    payment_method: 'cash',
    date_from: today,
    date_to: new Date(Date.now() + 6 * 86_400_000).toISOString().slice(0, 10),
    start_time: nowLocal.slice(11, 16),
    end_time: new Date(Date.now() + 8 * 3_600_000 - new Date().getTimezoneOffset() * 60_000)
      .toISOString()
      .slice(11, 16),
    scheduled_start: nowLocal,
    scheduled_end: new Date(Date.now() + 8 * 3_600_000 - new Date().getTimezoneOffset() * 60_000)
      .toISOString()
      .slice(0, 16),
    unit: slug === 'products' ? 'litres' : '',
  }
}

function TableCell({
  value,
  format,
}: {
  value: unknown
  format?: 'ghs' | 'litres' | 'date' | 'status'
}) {
  if (format === 'ghs') return <>{value === null || value === undefined ? '—' : formatGhs(asText(value))}</>
  if (format === 'litres') return <>{value === null || value === undefined ? '—' : `${Number(value).toLocaleString('en-GH', { maximumFractionDigits: 3 })} L`}</>
  if (format === 'date') return <>{value ? formatAccraDateTime(asText(value)) : '—'}</>
  if (format === 'status') {
    const label = typeof value === 'boolean'
      ? (value ? 'Active' : 'Inactive')
      : asText(value).replaceAll('_', ' ')
    return <StatusBadge label={label} tone={statusTone(value)} />
  }
  return <>{asText(value)}</>
}

function FormModal({
  definition,
  values,
  setValues,
  optionsFor,
  error,
  saving,
  onClose,
  onSubmit,
}: {
  definition: ModuleDefinition
  values: FormValues
  setValues: (values: FormValues) => void
  optionsFor: (field: FormField) => Array<{ value: string; label: string }>
  error: string | null
  saving: boolean
  onClose: () => void
  onSubmit: (event: FormEvent<HTMLFormElement>) => void
}) {
  const Icon = definition.icon
  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-modal="true" className="my-6 w-full max-w-2xl rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div className="flex gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
              <Icon className="h-5 w-5" />
            </span>
            <div>
              <h2 className="text-lg font-bold">{definition.actionLabel}</h2>
              <p className="mt-1 text-sm text-slate-500">Complete the operational record below.</p>
            </div>
          </div>
          <button aria-label="Close form" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" onClick={onClose} type="button">
            <X className="h-5 w-5" />
          </button>
        </header>
        <form onSubmit={onSubmit}>
          <div className="grid gap-5 p-6 sm:grid-cols-2">
            {definition.fields?.map((field) => {
              const options = optionsFor(field)
              const common = {
                className: 'form-input',
                id: field.key,
                name: field.key,
                required: field.required,
                value: values[field.key] ?? '',
                onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
                  setValues({ ...values, [field.key]: event.target.value }),
              }
              return (
                <label className={field.type === 'textarea' ? 'sm:col-span-2' : ''} htmlFor={field.key} key={field.key}>
                  <span className="mb-2 block text-sm font-semibold text-slate-700">
                    {field.label}{field.required ? ' *' : ''}
                  </span>
                  {field.type === 'select' ? (
                    <select {...common}>
                      <option value="">Select {field.label.toLowerCase()}</option>
                      {options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  ) : field.type === 'textarea' ? (
                    <textarea {...common} placeholder={field.placeholder} rows={3} />
                  ) : (
                    <input {...common} min={field.type === 'number' ? '0' : undefined} placeholder={field.placeholder} step={field.type === 'number' ? '0.001' : undefined} type={field.type ?? 'text'} />
                  )}
                </label>
              )
            })}
            {error ? (
              <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 sm:col-span-2">{error}</p>
            ) : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
            <button className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50" onClick={onClose} type="button">Cancel</button>
            <button className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" disabled={saving} type="submit">
              {saving ? 'Saving…' : definition.actionLabel}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function buildPayload(slug: string, values: FormValues): Record<string, unknown> {
  if (slug === 'products') return { ...values, unit: 'litres' }
  if (slug === 'tanks') return {
    ...values,
    atg_enabled: values.atg_enabled === 'true',
  }
  if (slug === 'pumps') return {
    station_id: values.station_id,
    code: values.code,
    name: values.name,
    nozzles: [{
      code: values.nozzle_code,
      tank_id: values.tank_id,
      current_meter_reading: Number(values.current_meter_reading),
      meter_maximum: values.meter_maximum ? Number(values.meter_maximum) : null,
    }],
  }
  if (slug === 'procurement') return {
    station_id: values.station_id,
    supplier_id: values.supplier_id,
    expected_delivery_date: values.expected_delivery_date,
    notes: values.notes || null,
    lines: [{
      product_id: values.product_id,
      target_tank_id: values.target_tank_id,
      quantity_litres: Number(values.quantity_litres),
      unit_price: Number(values.unit_price),
    }],
  }
  if (slug === 'receiving') {
    const [purchaseOrderId, lineId, tankId] = values.po_line.split('|')
    return {
      purchase_order_id: purchaseOrderId,
      purchase_order_line_id: lineId,
      tank_id: tankId,
      truck_number: values.truck_number,
      driver_name: values.driver_name,
      waybill_number: values.waybill_number,
      arrival_time: values.arrival_time,
      invoiced_quantity_litres: Number(values.invoiced_quantity_litres),
      pre_dip_litres: Number(values.pre_dip_litres),
      post_dip_litres: Number(values.post_dip_litres),
      variance_comment: values.variance_comment || null,
    }
  }
  if (slug === 'sales') return {
    shift_id: values.shift_id,
    nozzle_id: values.nozzle_id,
    litres: Number(values.litres),
    payment_method: values.payment_method,
    payment_reference: values.payment_reference || null,
  }
  if (slug === 'shifts') return {
    station_id: values.station_id,
    attendant_id: values.attendant_id,
    pump_id: values.pump_id,
    date_from: values.date_from,
    date_to: values.date_to,
    start_time: values.start_time,
    end_time: values.end_time,
    notes: values.notes || null,
  }
  return values
}

function useReferenceData(slug: string) {
  const stations = useQuery({
    queryKey: ['stations'],
    queryFn: () => apiFetch<ResourceResponse<Station[]>>('/api/v1/stations'),
  })
  const products = useQuery({
    queryKey: ['products'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/products'),
    enabled: ['tanks', 'procurement', 'suppliers'].includes(slug),
  })
  const tanks = useQuery({
    queryKey: ['tanks'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/tanks'),
    enabled: ['pumps', 'procurement', 'receiving'].includes(slug),
  })
  const suppliers = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/suppliers'),
    enabled: ['procurement'].includes(slug),
  })
  const pumps = useQuery({
    queryKey: ['pumps'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/pumps'),
    enabled: ['shifts', 'meter-readings', 'cash-count'].includes(slug),
  })
  const shifts = useQuery({
    queryKey: ['shifts'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/shifts'),
    enabled: slug === 'sales',
  })
  const purchaseOrders = useQuery({
    queryKey: ['purchase-orders'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/purchase-orders'),
    enabled: slug === 'receiving',
  })
  const users = useQuery({
    queryKey: ['users', 'shift-options'],
    queryFn: () => apiFetch<{ data: User[] }>('/api/v1/users?per_page=100'),
    enabled: slug === 'shifts',
  })
  return { stations, products, tanks, suppliers, pumps, shifts, purchaseOrders, users }
}

function SettingsPage() {
  const queryClient = useQueryClient()
  const settings = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<ResourceResponse<{ organization: { name: string; currency: string; timezone: string }; settings: Record<string, string | number> }>>('/api/v1/settings'),
  })
  const [values, setValues] = useState<FormValues>({})
  const current = settings.data?.data.settings
  const save = useMutation({
    mutationFn: (payload: Record<string, unknown>) => apiFetch('/api/v1/settings', { method: 'PUT', body: JSON.stringify(payload) }),
    onSuccess: async () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
  })
  const fields = [
    ['session_timeout_minutes', 'Session timeout (minutes)'],
    ['receiving_variance_tolerance_percent', 'Receiving variance tolerance (%)'],
    ['pump_variance_tolerance_percent', 'Pump variance tolerance (%)'],
    ['cash_variance_tolerance_ghs', 'Cash variance tolerance (GHS)'],
    ['low_stock_alert_percent', 'Low stock alert (%)'],
  ]
  return (
    <div className="mx-auto max-w-5xl">
      <ModuleHeader description="Organization-wide controls for sessions, tolerances, low-stock alerts, timezone, and GHS." icon={Settings} title="System Configuration" />
      <form className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" onSubmit={(event) => {
        event.preventDefault()
        const payload = Object.fromEntries(fields.map(([key]) => [key, Number(values[key] ?? current?.[key])]))
        save.mutate({ ...payload, report_timezone: values.report_timezone ?? current?.report_timezone ?? 'Africa/Accra' })
      }}>
        <div className="border-b border-slate-200 p-6"><h2 className="font-bold">{settings.data?.data.organization.name ?? 'FuelFlow organization'}</h2><p className="mt-1 text-sm text-slate-500">Currency is fixed to GHS as required.</p></div>
        <div className="grid gap-5 p-6 sm:grid-cols-2">
          {fields.map(([key, label]) => <label key={key}><span className="mb-2 block text-sm font-semibold">{label}</span><input className="form-input" min="0" onChange={(event) => setValues({ ...values, [key]: event.target.value })} step="0.1" type="number" value={values[key] ?? String(current?.[key] ?? '')} /></label>)}
          <label><span className="mb-2 block text-sm font-semibold">Report timezone</span><select className="form-input" onChange={(event) => setValues({ ...values, report_timezone: event.target.value })} value={values.report_timezone ?? String(current?.report_timezone ?? 'Africa/Accra')}><option value="Africa/Accra">Africa/Accra</option></select></label>
          <label><span className="mb-2 block text-sm font-semibold">Currency</span><input className="form-input" disabled value="GHS — Ghanaian cedi" /></label>
          {save.error ? <p className="text-sm text-rose-600 sm:col-span-2">{save.error instanceof Error ? save.error.message : 'Unable to save settings.'}</p> : null}
        </div>
        <footer className="flex justify-end border-t border-slate-200 p-4"><button className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" disabled={save.isPending} type="submit">{save.isPending ? 'Saving…' : 'Save configuration'}</button></footer>
      </form>
    </div>
  )
}

function ModuleHeader({ title, description, icon: Icon, children }: { title: string; description: string; icon: ComponentType<{ className?: string }>; children?: React.ReactNode }) {
  return (
    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex items-start gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600"><Icon className="h-5 w-5" /></span><div><p className="text-sm font-medium text-blue-600">FuelFlow FSMS</p><h1 className="mt-0.5 text-2xl font-bold tracking-tight">{title}</h1><p className="mt-2 max-w-3xl text-sm text-slate-500">{description}</p></div></div>
      {children}
    </header>
  )
}

function DataTable({ rows, columns, loading, actions }: { rows: OperationalRecord[]; columns: ModuleDefinition['columns']; loading: boolean; actions?: (row: OperationalRecord) => React.ReactNode }) {
  return (
    <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[850px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr>{columns.map((column) => <th className="px-5 py-3" key={column.key}>{column.label}</th>)}{actions ? <th className="px-5 py-3 text-right">Actions</th> : null}</tr></thead>
          <tbody className="divide-y divide-slate-100">
            {loading ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={columns.length + (actions ? 1 : 0)}>Loading operational records…</td></tr> : rows.length === 0 ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={columns.length + (actions ? 1 : 0)}>No records match this view. Use the action above to create the first record.</td></tr> : rows.map((row) => <tr className="hover:bg-slate-50/70" key={row.id}>{columns.map((column) => <td className="whitespace-nowrap px-5 py-4" key={column.key}><TableCell format={column.format} value={row[column.key]} /></td>)}{actions ? <td className="px-5 py-4 text-right">{actions(row)}</td> : null}</tr>)}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function OperationalModule({ slug }: { slug: string }) {
  const queryClient = useQueryClient()
  const { user } = useOutletContext<AuthenticatedOutletContext>()
  const definition = definitions[slug] ?? definitions.shifts
  const isPumpAttendant = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'cashier_attendant',
  )
  const isAdministrator = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'administrator',
  )
  const pageTitle = slug === 'sales' && isPumpAttendant
    ? 'Sale Entry'
    : definition.title
  const canCreate = Boolean(definition.fields)
    && (slug !== 'sales' || isPumpAttendant)
  const [formOpen, setFormOpen] = useState(false)
  const [values, setValues] = useState<FormValues>(() => initialFormValues(slug))
  const references = useReferenceData(slug)
  const openAssignedShifts = useMemo(
    () => (references.shifts.data?.data ?? []).filter((shift) => shift.status === 'open'),
    [references.shifts.data?.data],
  )
  const saleEntryReady = slug !== 'sales' || openAssignedShifts.length > 0
  const list = useQuery({
    queryKey: [slug],
    queryFn: () => apiFetch<ListResponse>(definition.endpoint),
  })
  const rows = useMemo(() => (list.data?.data ?? []).map((row) => {
    if (slug === 'pumps') {
      const nozzles = asArray(row.nozzles)
      return { ...row, nozzle_summary: nozzles.map((nozzle) => `${asText(nozzle.code)} · ${asText(nozzle.product_name)}`).join(', ') }
    }
    return row
  }), [list.data?.data, slug])
  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => {
      const endpoint = slug === 'reconciliation' ? '/api/v1/reconciliations/generate' : definition.endpoint
      return apiFetch(endpoint, { method: 'POST', body: JSON.stringify(payload) })
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [slug] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['tanks'] }),
        queryClient.invalidateQueries({ queryKey: ['purchase-orders'] }),
      ])
      setFormOpen(false)
      setValues(initialFormValues(slug))
    },
  })
  const action = useMutation({
    mutationFn: ({ endpoint, body }: { endpoint: string; body?: Record<string, unknown> }) => apiFetch(endpoint, { method: 'POST', body: JSON.stringify(body ?? {}) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [slug] })
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  const optionsFor = (field: FormField) => {
    if (field.options) return field.options
    const source = field.source
    if (source === 'stations') return (references.stations.data?.data ?? []).map((item) => ({ value: item.id, label: `${item.code} — ${item.name}` }))
    if (source === 'products') return (references.products.data?.data ?? []).filter((item) => item.is_active !== false).map((item) => ({ value: item.id, label: `${asText(item.code)} — ${asText(item.name)}` }))
    if (source === 'suppliers') return (references.suppliers.data?.data ?? []).filter((item) => item.is_active !== false).map((item) => ({ value: item.id, label: `${asText(item.code)} — ${asText(item.name)}` }))
    if (source === 'tanks') return (references.tanks.data?.data ?? []).filter((item) => !values.station_id || item.station_id === values.station_id).map((item) => ({ value: item.id, label: `${asText(item.code)} — ${asText(item.product_name)} (${asText(item.book_stock_litres)} L)` }))
    if (source === 'pumps') return (references.pumps.data?.data ?? []).filter((item) => !values.station_id || item.station_id === values.station_id).map((item) => ({ value: item.id, label: `${asText(item.code)} — ${asText(item.name)}` }))
    if (source === 'open-shifts') return openAssignedShifts.map((shift) => ({ value: shift.id, label: `${asText(shift.code)} — ${asText(shift.pump_name)}` }))
    if (source === 'nozzles' && slug === 'sales') return openAssignedShifts
      .filter((shift) => !values.shift_id || shift.id === values.shift_id)
      .flatMap((shift) => asArray(shift.pump_nozzles).map((nozzle) => ({
        value: nozzle.id,
        label: `${asText(shift.pump_name)} / ${asText(nozzle.code)} — ${asText(nozzle.product_name)}`,
      })))
    if (source === 'nozzles') return (references.pumps.data?.data ?? []).flatMap((pump) => asArray(pump.nozzles).map((nozzle) => ({ value: nozzle.id, label: `${asText(pump.name)} / ${asText(nozzle.code)} — ${asText(nozzle.product_name)}` })))
    if (source === 'po-lines') return (references.purchaseOrders.data?.data ?? []).filter((order) => ['sent', 'partially_received'].includes(asText(order.status))).flatMap((order) => asArray(order.lines).map((line) => ({ value: `${order.id}|${line.id}|${asText(line.target_tank_id)}`, label: `${asText(order.po_number)} — ${asText(line.product_name)} / ${asText(line.quantity_litres)} L` })))
    if (source === 'attendants') return (references.users.data?.data ?? []).filter((user) => user.role_assignments.some((assignment) => assignment.role.slug === 'cashier_attendant')).map((user) => ({ value: user.id, label: user.name }))
    return []
  }
  const error = create.error instanceof ApiError
    ? Object.values(create.error.errors ?? {})[0]?.[0] ?? create.error.message
    : create.error instanceof Error ? create.error.message : null
  const renderActions = (row: OperationalRecord) => {
    const button = (label: string, endpoint: string, body?: Record<string, unknown>) => <button className="ml-2 rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50 disabled:opacity-50" disabled={action.isPending} onClick={() => action.mutate({ endpoint, body })} type="button">{label}</button>
    if (slug === 'procurement') {
      if (row.status === 'draft') return button('Submit', `/api/v1/purchase-orders/${row.id}/submit`)
      if (row.status === 'pending_approval') return button('Approve', `/api/v1/purchase-orders/${row.id}/approve`)
      if (row.status === 'approved') return button('Send to supplier', `/api/v1/purchase-orders/${row.id}/send`)
    }
    if (slug === 'receiving' && ['pending_confirmation', 'pending_signoff'].includes(asText(row.status))) {
      return button('Confirm stock', `/api/v1/deliveries/${row.id}/confirm`, row.status === 'pending_signoff' ? { variance_comment: 'Variance reviewed and approved by authorized manager.' } : {})
    }
    if (slug === 'reconciliation' && row.status === 'in_review') {
      return button('Sign off & lock', `/api/v1/reconciliations/${row.id}/sign-off`, { manager_comment: 'Daily variances reviewed and accepted by station management.' })
    }
    if (slug === 'pumps') {
      const operational = row.status === 'operational'
      return button(operational ? 'Maintenance' : 'Return to service', `/api/v1/pumps/${row.id}/status`, { status: operational ? 'under_maintenance' : 'operational', description: operational ? 'Scheduled forecourt maintenance.' : 'Maintenance completed and pump tested.' })
    }
    if (slug === 'tanks') {
      return button('Record dip', `/api/v1/tanks/${row.id}/readings`, { reading_litres: row.book_stock_litres, read_at: new Date().toISOString(), notes: 'Manual physical dip recorded from tank page.' })
    }
    if (slug === 'sales' && row.status === 'confirmed' && isAdministrator) {
      return button('Reverse', `/api/v1/sales/${row.id}/reverse`, { reason: 'Authorized correction of an incorrectly entered sale.' })
    }
    return null
  }
  const Icon = definition.icon
  return (
    <div className="mx-auto max-w-[1440px]">
      <ModuleHeader description={definition.description} icon={Icon} title={pageTitle}>
        {canCreate ? <button className="flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-400" disabled={!saleEntryReady || references.shifts.isLoading} onClick={() => setFormOpen(true)} type="button"><Plus className="h-4 w-4" />{definition.actionLabel}</button> : null}
      </ModuleHeader>
      {slug === 'sales' && isPumpAttendant && !references.shifts.isLoading && !saleEntryReady ? (
        <p className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Start your assigned shift before recording a sale. Pump nozzles become available from the open shift.
        </p>
      ) : null}
      <section className="mt-6 grid gap-4 sm:grid-cols-3">
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Total records</p><p className="mt-2 text-2xl font-bold">{rows.length}</p></article>
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Operational scope</p><p className="mt-2 text-lg font-bold">{references.stations.data?.data.length ?? 0} station(s)</p></article>
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Currency</p><p className="mt-2 text-lg font-bold">GHS · Ghanaian cedi</p></article>
      </section>
      {action.error ? <p className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{action.error instanceof Error ? action.error.message : 'The action could not be completed.'}</p> : null}
      <DataTable actions={renderActions} columns={definition.columns} loading={list.isLoading} rows={rows} />
      {canCreate && saleEntryReady && formOpen ? <FormModal definition={definition} error={error} onClose={() => setFormOpen(false)} onSubmit={(event) => { event.preventDefault(); create.mutate(buildPayload(slug, values)) }} optionsFor={optionsFor} saving={create.isPending} setValues={setValues} values={values} /> : null}
    </div>
  )
}

function ReceiptsPage() {
  const sales = useQuery({ queryKey: ['sales'], queryFn: () => apiFetch<ListResponse>('/api/v1/sales') })
  return <div className="mx-auto max-w-[1440px]"><ModuleHeader description="Search and reprint transaction receipts from authorized sales." icon={ReceiptText} title="Receipts" /><DataTable columns={definitions.sales.columns} loading={sales.isLoading} rows={sales.data?.data ?? []} /></div>
}

function ShiftTaskPage({ slug }: { slug: string }) {
  const queryClient = useQueryClient()
  const title = slug === 'cash-count' ? 'Cash Count / Shift Close' : 'Meter Readings'
  const description = slug === 'cash-count' ? 'Review expected cash and close the assigned shift with a counted GHS amount.' : 'Review assigned pump opening and closing readings for the current shift.'
  const [selected, setSelected] = useState<OperationalRecord | null>(null)
  const [readings, setReadings] = useState<FormValues>({})
  const [countedCash, setCountedCash] = useState('')
  const shifts = useQuery({
    queryKey: ['shifts'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/shifts'),
  })
  const submit = useMutation({
    mutationFn: async () => {
      if (!selected) return
      const pumpNozzles = asArray(selected.pump_nozzles)
      const closing = selected.status === 'open'
      return apiFetch(`/api/v1/shifts/${selected.id}/${closing ? 'close' : 'open'}`, {
        method: 'POST',
        body: JSON.stringify({
          readings: pumpNozzles.map((nozzle) => ({
            nozzle_id: nozzle.id,
            reading: Number(readings[nozzle.id] ?? nozzle.current_meter_reading),
          })),
          ...(closing ? {
            counted_cash: Number(countedCash),
            notes: 'Shift cash and closing meters submitted from FuelFlow.',
          } : {}),
          override_reason: 'Authorized shift operation recorded through FuelFlow.',
        }),
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['shifts'] })
      setSelected(null)
      setReadings({})
      setCountedCash('')
    },
  })
  const openAction = (row: OperationalRecord) => {
    setSelected(row)
    setReadings(Object.fromEntries(asArray(row.pump_nozzles).map((nozzle) => [nozzle.id, asText(nozzle.current_meter_reading)])))
  }
  return <div className="mx-auto max-w-[1440px]">
    <ModuleHeader description={description} icon={slug === 'cash-count' ? Banknote : Gauge} title={title} />
    <DataTable
      actions={(row) => ['scheduled', 'open'].includes(asText(row.status)) ? <button className="rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700" onClick={() => openAction(row)} type="button">{row.status === 'open' ? 'Close shift' : 'Open shift'}</button> : <span className="text-xs text-slate-400">Completed</span>}
      columns={definitions.shifts.columns}
      loading={shifts.isLoading}
      rows={shifts.data?.data ?? []}
    />
    {selected ? <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-modal="true" className="w-full max-w-xl rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between border-b border-slate-200 p-6"><div><h2 className="text-lg font-bold">{selected.status === 'open' ? 'Close shift and count cash' : 'Open shift with meter readings'}</h2><p className="mt-1 text-sm text-slate-500">{asText(selected.code)} · {asText(selected.pump_name)}</p></div><button aria-label="Close shift form" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" onClick={() => setSelected(null)} type="button"><X className="h-5 w-5" /></button></header>
        <form onSubmit={(event) => { event.preventDefault(); submit.mutate() }}>
          <div className="space-y-5 p-6">
            {asArray(selected.pump_nozzles).map((nozzle) => <label key={nozzle.id}><span className="mb-2 block text-sm font-semibold">{asText(nozzle.code)} · {asText(nozzle.product_name)} meter reading</span><input className="form-input" min="0" onChange={(event) => setReadings({ ...readings, [nozzle.id]: event.target.value })} required step="0.001" type="number" value={readings[nozzle.id] ?? ''} /></label>)}
            {selected.status === 'open' ? <label><span className="mb-2 block text-sm font-semibold">Counted cash (GHS) *</span><input className="form-input" min="0" onChange={(event) => setCountedCash(event.target.value)} required step="0.01" type="number" value={countedCash} /></label> : null}
            {submit.error ? <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{submit.error instanceof Error ? submit.error.message : 'Unable to update the shift.'}</p> : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 p-4"><button className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold" onClick={() => setSelected(null)} type="button">Cancel</button><button className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={submit.isPending} type="submit">{submit.isPending ? 'Submitting…' : selected.status === 'open' ? 'Close shift' : 'Open shift'}</button></footer>
        </form>
      </section>
    </div> : null}
  </div>
}

function StationPerformancePage() {
  return <div className="space-y-8"><ModuleHeader description="Portfolio station sales, stock position, purchase orders, deliveries, and reconciliation status." icon={Gauge} title="Station Performance" /><DashboardPage /></div>
}

function ReportSchedulesPage() {
  const queryClient = useQueryClient()
  const [values, setValues] = useState<FormValues>({
    name: '',
    report_type: 'reconciliation',
    frequency: 'daily',
    send_time: '06:00',
    recipients: '',
  })
  const schedules = useQuery({
    queryKey: ['report-schedules'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/report-schedules'),
  })
  const save = useMutation({
    mutationFn: () => apiFetch('/api/v1/report-schedules', {
      method: 'POST',
      body: JSON.stringify({
        name: values.name,
        report_type: values.report_type,
        frequency: values.frequency,
        send_time: values.send_time,
        recipients: values.recipients.split(',').map((email) => email.trim()).filter(Boolean),
      }),
    }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['report-schedules'] })
      setValues({ name: '', report_type: 'reconciliation', frequency: 'daily', send_time: '06:00', recipients: '' })
    },
  })
  const toggle = useMutation({
    mutationFn: (id: string) => apiFetch(`/api/v1/report-schedules/${id}/toggle`, { method: 'POST', body: '{}' }),
    onSuccess: async () => queryClient.invalidateQueries({ queryKey: ['report-schedules'] }),
  })
  return <div className="mx-auto max-w-5xl">
    <ModuleHeader description="Configure persisted recurring report delivery recipients and schedules." icon={CalendarClock} title="Report Schedules" />
    <section className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <h2 className="font-bold">Create automated report delivery</h2>
      <form className="mt-5 grid gap-5 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); save.mutate() }}>
        <label><span className="mb-2 block text-sm font-semibold">Schedule name *</span><input className="form-input" onChange={(event) => setValues({ ...values, name: event.target.value })} required value={values.name} /></label>
        <label><span className="mb-2 block text-sm font-semibold">Report *</span><select className="form-input" onChange={(event) => setValues({ ...values, report_type: event.target.value })} value={values.report_type}><option value="daily-sales">Daily sales</option><option value="stock-movements">Stock movements</option><option value="procurement">Procurement</option><option value="reconciliation">Reconciliation</option><option value="supplier-performance">Supplier performance</option><option value="shift-attendance">Shift attendance</option><option value="user-activity">User activity</option></select></label>
        <label><span className="mb-2 block text-sm font-semibold">Frequency *</span><select className="form-input" onChange={(event) => setValues({ ...values, frequency: event.target.value })} value={values.frequency}><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
        <label><span className="mb-2 block text-sm font-semibold">Delivery time (Africa/Accra) *</span><input className="form-input" onChange={(event) => setValues({ ...values, send_time: event.target.value })} required type="time" value={values.send_time} /></label>
        <label className="sm:col-span-2"><span className="mb-2 block text-sm font-semibold">Recipients (comma-separated) *</span><input className="form-input" onChange={(event) => setValues({ ...values, recipients: event.target.value })} placeholder="finance@example.com, owner@example.com" required value={values.recipients} /></label>
        {save.error ? <p className="text-sm text-rose-600 sm:col-span-2">{save.error instanceof Error ? save.error.message : 'Unable to save the schedule.'}</p> : null}
        <div className="sm:col-span-2"><button className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" disabled={save.isPending} type="submit">{save.isPending ? 'Saving…' : 'Save schedule'}</button></div>
      </form>
    </section>
    <DataTable actions={(row) => <button className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold" onClick={() => toggle.mutate(row.id)} type="button">{row.is_active ? 'Pause' : 'Activate'}</button>} columns={[{ key: 'name', label: 'Schedule' }, { key: 'report_type', label: 'Report' }, { key: 'frequency', label: 'Frequency' }, { key: 'send_time', label: 'Send time' }, { key: 'recipients', label: 'Recipients' }, { key: 'is_active', label: 'Status', format: 'status' }]} loading={schedules.isLoading} rows={schedules.data?.data ?? []} />
  </div>
}

export default function ModulePage() {
  const { moduleSlug = '' } = useParams()
  if (moduleSlug === 'procurement' || moduleSlug === 'po-requests') {
    return <PurchaseOrdersPage paymentQueue={moduleSlug === 'po-requests'} />
  }
  if (moduleSlug === 'reporting') return <ReportingPage />
  if (moduleSlug === 'settings') return <SettingsPage />
  if (moduleSlug === 'stations') return <StationPerformancePage />
  if (moduleSlug === 'receipts') return <ReceiptsPage />
  if (moduleSlug === 'meter-readings' || moduleSlug === 'cash-count') return <ShiftTaskPage slug={moduleSlug} />
  if (moduleSlug === 'report-schedules') return <ReportSchedulesPage />
  return <OperationalModule key={moduleSlug} slug={moduleSlug} />
}
