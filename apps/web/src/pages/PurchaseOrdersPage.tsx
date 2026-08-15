import {
  Check,
  Clock3,
  Eye,
  FileCheck2,
  PackageCheck,
  Plus,
  Receipt,
  Send,
  X,
  XCircle,
} from 'lucide-react'
import {
  useMemo,
  useState,
  type FormEvent,
} from 'react'
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { useOutletContext } from 'react-router-dom'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import { formatAccraDateTime, formatGhs } from '../lib/format'
import type { ResourceResponse, Station } from '../types/api'
import type { ListResponse, OperationalRecord } from '../types/operations'

type PurchaseOrderLine = {
  id: string
  product_id: string
  product_name: string
  target_tank_id: string
  target_tank_name: string
  quantity_litres: string
  unit_price: string
  line_total: string
}

type PurchaseOrder = {
  id: string
  po_number: string
  station_id: string
  station_name: string
  supplier_id: string
  supplier_name: string
  status: string
  expected_delivery_date: string
  subtotal: string
  total: string
  notes: string | null
  created_by: string
  approved_by: string | null
  approved_by_name: string | null
  approved_at: string | null
  paid_by: string | null
  paid_by_name: string | null
  paid_at: string | null
  payment_reference: string | null
  payment_receipt_name: string | null
  payment_receipt_size: number | null
  payment_receipt_url: string | null
  sent_at: string | null
  created_at: string
  lines: PurchaseOrderLine[]
}

type PurchaseOrderResponse = {
  data: PurchaseOrder[]
}

type PurchaseOrderResource = {
  data: PurchaseOrder
}

type FormValues = {
  station_id: string
  supplier_id: string
  expected_delivery_date: string
  product_id: string
  target_tank_id: string
  quantity_litres: string
  unit_price: string
  notes: string
}

const initialValues = (): FormValues => ({
  station_id: '',
  supplier_id: '',
  expected_delivery_date: new Date(Date.now() + 2 * 86_400_000)
    .toISOString()
    .slice(0, 10),
  product_id: '',
  target_tank_id: '',
  quantity_litres: '',
  unit_price: '',
  notes: '',
})

const roleSet = (context: AuthenticatedOutletContext) =>
  new Set(context.user.role_assignments.map((assignment) => assignment.role.slug))

function validationMessage(error: unknown): string | null {
  if (error instanceof ApiError) {
    return Object.values(error.errors ?? {})[0]?.[0] ?? error.message
  }
  return error instanceof Error ? error.message : null
}

function statusPresentation(status: string): {
  label: string
  tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
} {
  if (status === 'pending_approval') return { label: 'Pending', tone: 'warning' }
  if (status === 'approved') return { label: 'Approved - payment due', tone: 'info' }
  if (status === 'paid') return { label: 'Paid', tone: 'success' }
  if (status === 'rejected') return { label: 'Rejected', tone: 'danger' }
  if (status === 'cancelled') return { label: 'Cancelled', tone: 'danger' }
  if (['sent', 'received', 'closed'].includes(status)) {
    return { label: status.replaceAll('_', ' '), tone: 'success' }
  }
  return { label: status.replaceAll('_', ' '), tone: 'neutral' }
}

function OrderStatus({ status }: { status: string }) {
  const presentation = statusPresentation(status)
  return <StatusBadge label={presentation.label} tone={presentation.tone} />
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes < 1024 * 1024) return `${Math.ceil(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function PurchaseOrderForm({
  references,
  saving,
  error,
  onClose,
  onSubmit,
}: {
  references: {
    stations: Station[]
    suppliers: OperationalRecord[]
    products: OperationalRecord[]
    tanks: OperationalRecord[]
  }
  saving: boolean
  error: string | null
  onClose: () => void
  onSubmit: (values: FormValues, submitForApproval: boolean) => void
}) {
  const [values, setValues] = useState<FormValues>(initialValues)
  const tanks = useMemo(
    () => references.tanks.filter((tank) =>
      (!values.station_id || tank.station_id === values.station_id)
      && (!values.product_id || tank.product_id === values.product_id)),
    [references.tanks, values.product_id, values.station_id],
  )
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const nativeEvent = event.nativeEvent as SubmitEvent
    const submitter = nativeEvent.submitter as HTMLButtonElement | null
    onSubmit(values, submitter?.value === 'submit')
  }
  const update = (key: keyof FormValues, value: string) => {
    setValues((current) => ({
      ...current,
      [key]: value,
      ...(key === 'station_id' || key === 'product_id'
        ? { target_tank_id: '' }
        : {}),
    }))
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-labelledby="po-form-title" aria-modal="true" className="my-6 w-full max-w-3xl rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-sm font-semibold text-blue-600">Station Manager</p>
            <h2 className="mt-1 text-xl font-bold" id="po-form-title">Create purchase order request</h2>
            <p className="mt-1 text-sm text-slate-500">Save it as a draft or send it directly to the Administrator for a decision.</p>
          </div>
          <button aria-label="Close purchase order form" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" onClick={onClose} type="button">
            <X className="h-5 w-5" />
          </button>
        </header>
        <form onSubmit={submit}>
          <div className="grid gap-5 p-6 sm:grid-cols-2">
            <label>
              <span className="mb-2 block text-sm font-semibold">Receiving station *</span>
              <select className="form-input" onChange={(event) => update('station_id', event.target.value)} required value={values.station_id}>
                <option value="">Select station</option>
                {references.stations.map((station) => <option key={station.id} value={station.id}>{station.code} - {station.name}</option>)}
              </select>
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Supplier *</span>
              <select className="form-input" onChange={(event) => update('supplier_id', event.target.value)} required value={values.supplier_id}>
                <option value="">Select supplier</option>
                {references.suppliers.filter((supplier) => supplier.is_active !== false).map((supplier) => <option key={supplier.id} value={supplier.id}>{String(supplier.code)} - {String(supplier.name)}</option>)}
              </select>
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Fuel product *</span>
              <select className="form-input" onChange={(event) => update('product_id', event.target.value)} required value={values.product_id}>
                <option value="">Select product</option>
                {references.products.filter((product) => product.is_active !== false).map((product) => <option key={product.id} value={product.id}>{String(product.code)} - {String(product.name)}</option>)}
              </select>
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Target tank *</span>
              <select className="form-input" disabled={!values.station_id || !values.product_id} onChange={(event) => update('target_tank_id', event.target.value)} required value={values.target_tank_id}>
                <option value="">Select matching tank</option>
                {tanks.map((tank) => <option key={tank.id} value={tank.id}>{String(tank.code)} - {String(tank.name)} ({Number(tank.book_stock_litres).toLocaleString('en-GH')} L in stock)</option>)}
              </select>
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Quantity (litres) *</span>
              <input className="form-input" min="0.001" onChange={(event) => update('quantity_litres', event.target.value)} required step="0.001" type="number" value={values.quantity_litres} />
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Supplier price (GHS/litre) *</span>
              <input className="form-input" min="0.0001" onChange={(event) => update('unit_price', event.target.value)} required step="0.0001" type="number" value={values.unit_price} />
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Expected delivery date *</span>
              <input className="form-input" min={new Date().toISOString().slice(0, 10)} onChange={(event) => update('expected_delivery_date', event.target.value)} required type="date" value={values.expected_delivery_date} />
            </label>
            <div className="rounded-lg bg-blue-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-blue-700">Estimated total</p>
              <p className="mt-1 text-xl font-bold text-blue-950">{formatGhs((Number(values.quantity_litres) * Number(values.unit_price)) || 0)}</p>
            </div>
            <label className="sm:col-span-2">
              <span className="mb-2 block text-sm font-semibold">Request notes</span>
              <textarea className="form-input" maxLength={1000} onChange={(event) => update('notes', event.target.value)} rows={3} value={values.notes} />
            </label>
            {error ? <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 sm:col-span-2">{error}</p> : null}
          </div>
          <footer className="flex flex-wrap justify-end gap-3 border-t border-slate-200 px-6 py-4">
            <button className="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50" onClick={onClose} type="button">Cancel</button>
            <button className="rounded-lg border border-blue-200 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50" disabled={saving} type="submit" value="draft">Save draft</button>
            <button className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" disabled={saving} type="submit" value="submit">
              <Send className="h-4 w-4" />{saving ? 'Submitting...' : 'Submit to Administrator'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function RejectModal({
  order,
  saving,
  error,
  onClose,
  onReject,
}: {
  order: PurchaseOrder
  saving: boolean
  error: string | null
  onClose: () => void
  onReject: (reason: string) => void
}) {
  const [reason, setReason] = useState('')
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-labelledby="reject-po-title" aria-modal="true" className="w-full max-w-lg rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="border-b border-slate-200 p-6">
          <p className="text-sm font-semibold text-rose-600">Administrator decision</p>
          <h2 className="mt-1 text-xl font-bold" id="reject-po-title">Reject {order.po_number}</h2>
          <p className="mt-2 text-sm text-slate-500">The reason will be recorded in the audit trail and shown to the station manager.</p>
        </header>
        <form onSubmit={(event) => { event.preventDefault(); onReject(reason) }}>
          <div className="p-6">
            <label>
              <span className="mb-2 block text-sm font-semibold">Reason for rejection *</span>
              <textarea className="form-input" minLength={10} onChange={(event) => setReason(event.target.value)} required rows={4} value={reason} />
            </label>
            {error ? <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p> : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 p-4">
            <button className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold" onClick={onClose} type="button">Keep pending</button>
            <button className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={saving} type="submit">{saving ? 'Rejecting...' : 'Reject request'}</button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function PaymentModal({
  order,
  saving,
  error,
  onClose,
  onPay,
}: {
  order: PurchaseOrder
  saving: boolean
  error: string | null
  onClose: () => void
  onPay: (reference: string, receipt: File) => void
}) {
  const [reference, setReference] = useState('')
  const [receipt, setReceipt] = useState<File | null>(null)
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-labelledby="payment-po-title" aria-modal="true" className="w-full max-w-lg rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="border-b border-slate-200 p-6">
          <p className="text-sm font-semibold text-emerald-600">Accountant action</p>
          <h2 className="mt-1 text-xl font-bold" id="payment-po-title">Record payment for {order.po_number}</h2>
          <p className="mt-2 text-sm text-slate-500">Amount due: <strong className="text-slate-900">{formatGhs(order.total)}</strong>. Payment evidence is mandatory.</p>
        </header>
        <form onSubmit={(event) => {
          event.preventDefault()
          if (receipt) onPay(reference, receipt)
        }}>
          <div className="space-y-5 p-6">
            <label>
              <span className="mb-2 block text-sm font-semibold">Bank / transaction reference</span>
              <input className="form-input" maxLength={120} onChange={(event) => setReference(event.target.value)} placeholder="e.g. GCB-TRX-0001" value={reference} />
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">Payment receipt *</span>
              <input accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="form-input file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-blue-700" onChange={(event) => setReceipt(event.target.files?.[0] ?? null)} required type="file" />
              <span className="mt-2 block text-xs text-slate-500">PDF, JPG, or PNG up to 10 MB.</span>
            </label>
            {error ? <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p> : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 p-4">
            <button className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold" onClick={onClose} type="button">Cancel</button>
            <button className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={saving || !receipt} type="submit">
              <FileCheck2 className="h-4 w-4" />{saving ? 'Recording...' : 'Mark as paid'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function DetailsModal({
  order,
  onClose,
}: {
  order: PurchaseOrder
  onClose: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section aria-labelledby="po-details-title" aria-modal="true" className="my-6 w-full max-w-3xl rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between border-b border-slate-200 p-6">
          <div>
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="text-xl font-bold" id="po-details-title">{order.po_number}</h2>
              <OrderStatus status={order.status} />
            </div>
            <p className="mt-2 text-sm text-slate-500">{order.station_name} - {order.supplier_name}</p>
          </div>
          <button aria-label="Close purchase order details" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" onClick={onClose} type="button"><X className="h-5 w-5" /></button>
        </header>
        <div className="space-y-6 p-6">
          <dl className="grid gap-4 rounded-xl bg-slate-50 p-5 sm:grid-cols-3">
            <div><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</dt><dd className="mt-1 font-bold">{formatGhs(order.total)}</dd></div>
            <div><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Expected delivery</dt><dd className="mt-1 font-semibold">{new Date(`${order.expected_delivery_date}T00:00:00`).toLocaleDateString('en-GH')}</dd></div>
            <div><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Requested</dt><dd className="mt-1 font-semibold">{formatAccraDateTime(order.created_at)}</dd></div>
          </dl>
          <div>
            <h3 className="text-sm font-bold">Order lines</h3>
            <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500"><tr><th className="px-4 py-3">Product / tank</th><th className="px-4 py-3">Quantity</th><th className="px-4 py-3">Unit price</th><th className="px-4 py-3 text-right">Line total</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{order.lines.map((line) => <tr key={line.id}><td className="px-4 py-3"><span className="font-semibold">{line.product_name}</span><span className="block text-xs text-slate-500">{line.target_tank_name}</span></td><td className="px-4 py-3">{Number(line.quantity_litres).toLocaleString('en-GH')} L</td><td className="px-4 py-3">{formatGhs(line.unit_price)}</td><td className="px-4 py-3 text-right font-semibold">{formatGhs(line.line_total)}</td></tr>)}</tbody>
              </table>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <article className="rounded-xl border border-slate-200 p-4">
              <h3 className="text-sm font-bold">Administrator decision</h3>
              {order.approved_at ? <p className="mt-2 text-sm text-slate-600">Approved by <strong>{order.approved_by_name}</strong><br />{formatAccraDateTime(order.approved_at)}</p> : order.status === 'rejected' ? <p className="mt-2 text-sm text-rose-700">Rejected. See the request notes for the recorded reason.</p> : <p className="mt-2 text-sm text-amber-700">Awaiting Administrator decision.</p>}
            </article>
            <article className="rounded-xl border border-slate-200 p-4">
              <h3 className="text-sm font-bold">Payment</h3>
              {order.paid_at ? <div className="mt-2 text-sm text-slate-600"><p>Paid by <strong>{order.paid_by_name}</strong><br />{formatAccraDateTime(order.paid_at)}</p>{order.payment_reference ? <p className="mt-2">Reference: <strong>{order.payment_reference}</strong></p> : null}{order.payment_receipt_url ? <a className="mt-3 inline-flex items-center gap-2 font-semibold text-blue-700 hover:underline" href={order.payment_receipt_url}><Receipt className="h-4 w-4" />Download {order.payment_receipt_name} {formatBytes(order.payment_receipt_size)}</a> : null}</div> : <p className="mt-2 text-sm text-slate-500">{order.status === 'approved' ? 'Approved and awaiting accountant payment.' : 'Not yet available for payment.'}</p>}
            </article>
          </div>
          {order.notes ? <div><h3 className="text-sm font-bold">Notes</h3><p className="mt-2 whitespace-pre-wrap rounded-xl bg-slate-50 p-4 text-sm text-slate-600">{order.notes}</p></div> : null}
        </div>
      </section>
    </div>
  )
}

export default function PurchaseOrdersPage({
  paymentQueue,
}: {
  paymentQueue: boolean
}) {
  const context = useOutletContext<AuthenticatedOutletContext>()
  const roles = roleSet(context)
  const isAdministrator = roles.has('administrator')
  const canCreate = roles.has('station_manager')
  const canDecide = isAdministrator
  const canPay = roles.has('accountant')
  const canSend = roles.has('station_manager')
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [rejecting, setRejecting] = useState<PurchaseOrder | null>(null)
  const [paying, setPaying] = useState<PurchaseOrder | null>(null)
  const [details, setDetails] = useState<PurchaseOrder | null>(null)

  const orders = useQuery({
    queryKey: ['purchase-orders', paymentQueue ? 'payment' : 'all'],
    queryFn: () => apiFetch<PurchaseOrderResponse>(
      `/api/v1/purchase-orders${paymentQueue ? '?queue=payment' : ''}`,
    ),
  })
  const stations = useQuery({
    queryKey: ['stations'],
    queryFn: () => apiFetch<ResourceResponse<Station[]>>('/api/v1/stations'),
    enabled: canCreate,
  })
  const suppliers = useQuery({
    queryKey: ['suppliers'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/suppliers'),
    enabled: canCreate,
  })
  const products = useQuery({
    queryKey: ['products'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/products'),
    enabled: canCreate,
  })
  const tanks = useQuery({
    queryKey: ['tanks'],
    queryFn: () => apiFetch<ListResponse>('/api/v1/tanks'),
    enabled: canCreate,
  })

  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] }),
      queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
    ])
  }
  const create = useMutation({
    mutationFn: async ({
      values,
      submitForApproval,
    }: {
      values: FormValues
      submitForApproval: boolean
    }) => {
      const created = await apiFetch<PurchaseOrderResource>('/api/v1/purchase-orders', {
        method: 'POST',
        body: JSON.stringify({
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
        }),
      })
      if (!submitForApproval) return created
      return apiFetch<PurchaseOrderResource>(
        `/api/v1/purchase-orders/${created.data.id}/submit`,
        { method: 'POST', body: '{}' },
      )
    },
    onSuccess: async () => {
      await refresh()
      setFormOpen(false)
    },
  })
  const transition = useMutation({
    mutationFn: ({
      order,
      action,
    }: {
      order: PurchaseOrder
      action: 'submit' | 'approve' | 'send'
    }) => apiFetch<PurchaseOrderResource>(
      `/api/v1/purchase-orders/${order.id}/${action}`,
      { method: 'POST', body: '{}' },
    ),
    onSuccess: refresh,
  })
  const reject = useMutation({
    mutationFn: ({ order, reason }: { order: PurchaseOrder; reason: string }) =>
      apiFetch<PurchaseOrderResource>(
        `/api/v1/purchase-orders/${order.id}/reject`,
        { method: 'POST', body: JSON.stringify({ reason }) },
      ),
    onSuccess: async () => {
      await refresh()
      setRejecting(null)
    },
  })
  const pay = useMutation({
    mutationFn: ({
      order,
      reference,
      receipt,
    }: {
      order: PurchaseOrder
      reference: string
      receipt: File
    }) => {
      const body = new FormData()
      if (reference) body.set('payment_reference', reference)
      body.set('receipt', receipt)
      return apiFetch<PurchaseOrderResource>(
        `/api/v1/purchase-orders/${order.id}/pay`,
        { method: 'POST', body },
      )
    },
    onSuccess: async () => {
      await refresh()
      setPaying(null)
    },
  })

  const allOrders = orders.data?.data ?? []
  const visibleOrders = paymentQueue
    ? allOrders
    : isAdministrator
      ? allOrders.filter((order) => order.status !== 'draft')
      : allOrders
  const pendingCount = visibleOrders.filter((order) => order.status === 'pending_approval').length
  const approvedCount = visibleOrders.filter((order) => order.status === 'approved').length
  const paidCount = visibleOrders.filter((order) => order.status === 'paid').length
  const valueAwaitingPayment = visibleOrders
    .filter((order) => order.status === 'approved')
    .reduce((sum, order) => sum + Number(order.total), 0)
  const pageTitle = paymentQueue
    ? 'PO Requests'
    : isAdministrator
      ? 'PO Approvals'
      : 'Procurement'
  const pageDescription = paymentQueue
    ? 'Administrator-approved requests awaiting payment, with secure receipt evidence and paid history.'
    : isAdministrator
      ? 'Review station purchase order requests and approve or reject each pending decision.'
      : 'Create, submit, and track station purchase orders through approval, payment, and supplier dispatch.'

  const actionsFor = (order: PurchaseOrder) => (
    <div className="flex flex-wrap justify-end gap-2">
      <button className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50" onClick={() => setDetails(order)} type="button"><Eye className="h-3.5 w-3.5" />Details</button>
      {canCreate && order.status === 'draft' ? <button className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50" disabled={transition.isPending} onClick={() => transition.mutate({ order, action: 'submit' })} type="button"><Send className="h-3.5 w-3.5" />Submit to Administrator</button> : null}
      {canDecide && order.status === 'pending_approval' ? <>
        <button className="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" disabled={transition.isPending} onClick={() => transition.mutate({ order, action: 'approve' })} type="button"><Check className="h-3.5 w-3.5" />Approve</button>
        <button className="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100" onClick={() => setRejecting(order)} type="button"><XCircle className="h-3.5 w-3.5" />Reject</button>
      </> : null}
      {canPay && order.status === 'approved' ? <button className="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700" onClick={() => setPaying(order)} type="button"><Receipt className="h-3.5 w-3.5" />Mark paid</button> : null}
      {canSend && order.status === 'paid' ? <button className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50" disabled={transition.isPending} onClick={() => transition.mutate({ order, action: 'send' })} type="button"><Send className="h-3.5 w-3.5" />Send to supplier</button> : null}
      {order.payment_receipt_url ? <a className="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100" href={order.payment_receipt_url}><Receipt className="h-3.5 w-3.5" />Receipt</a> : null}
    </div>
  )
  const actionError = validationMessage(transition.error)
    ?? validationMessage(orders.error)

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600"><PackageCheck className="h-5 w-5" /></span>
          <div><p className="text-sm font-medium text-blue-600">FuelFlow FSMS</p><h1 className="mt-0.5 text-2xl font-bold tracking-tight">{pageTitle}</h1><p className="mt-2 max-w-3xl text-sm text-slate-500">{pageDescription}</p></div>
        </div>
        {canCreate && !paymentQueue ? <button className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700" onClick={() => setFormOpen(true)} type="button"><Plus className="h-4 w-4" />Create PO request</button> : null}
      </header>

      <section className="mt-6 grid gap-4 sm:grid-cols-3">
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div className="flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{paymentQueue ? 'Awaiting payment' : 'Pending Administrator decision'}</p><Clock3 className="h-4 w-4 text-amber-500" /></div><p className="mt-2 text-2xl font-bold">{paymentQueue ? approvedCount : pendingCount}</p></article>
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div className="flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Paid requests</p><FileCheck2 className="h-4 w-4 text-emerald-500" /></div><p className="mt-2 text-2xl font-bold">{paidCount}</p></article>
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Approved value due</p><p className="mt-2 text-2xl font-bold">{formatGhs(valueAwaitingPayment)}</p></article>
      </section>

      {actionError ? <p className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{actionError}</p> : null}

      <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">PO request</th><th className="px-5 py-3">Station</th><th className="px-5 py-3">Supplier</th><th className="px-5 py-3">Expected delivery</th><th className="px-5 py-3">Total</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {orders.isLoading ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={7}>Loading purchase order requests...</td></tr> : visibleOrders.length === 0 ? <tr><td className="px-5 py-12 text-center text-slate-500" colSpan={7}>{paymentQueue ? 'No Administrator-approved requests are awaiting payment.' : 'No purchase order requests are available in this view.'}</td></tr> : visibleOrders.map((order) => <tr className="align-top hover:bg-slate-50/70" key={order.id}><td className="px-5 py-4"><p className="font-semibold">{order.po_number}</p><p className="mt-1 text-xs text-slate-500">{order.lines.map((line) => line.product_name).join(', ')}</p></td><td className="px-5 py-4">{order.station_name}</td><td className="px-5 py-4">{order.supplier_name}</td><td className="px-5 py-4">{new Date(`${order.expected_delivery_date}T00:00:00`).toLocaleDateString('en-GH')}</td><td className="px-5 py-4 font-semibold">{formatGhs(order.total)}</td><td className="px-5 py-4"><OrderStatus status={order.status} />{order.approved_by_name ? <p className="mt-2 text-xs text-slate-500">By {order.approved_by_name}</p> : null}</td><td className="px-5 py-4">{actionsFor(order)}</td></tr>)}
            </tbody>
          </table>
        </div>
      </section>

      {formOpen ? <PurchaseOrderForm error={validationMessage(create.error)} onClose={() => setFormOpen(false)} onSubmit={(values, submitForApproval) => create.mutate({ values, submitForApproval })} references={{ stations: stations.data?.data ?? [], suppliers: suppliers.data?.data ?? [], products: products.data?.data ?? [], tanks: tanks.data?.data ?? [] }} saving={create.isPending} /> : null}
      {rejecting ? <RejectModal error={validationMessage(reject.error)} onClose={() => setRejecting(null)} onReject={(reason) => reject.mutate({ order: rejecting, reason })} order={rejecting} saving={reject.isPending} /> : null}
      {paying ? <PaymentModal error={validationMessage(pay.error)} onClose={() => setPaying(null)} onPay={(reference, receipt) => pay.mutate({ order: paying, reference, receipt })} order={paying} saving={pay.isPending} /> : null}
      {details ? <DetailsModal onClose={() => setDetails(null)} order={details} /> : null}
    </div>
  )
}
