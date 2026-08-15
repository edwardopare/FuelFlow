import { useQuery } from '@tanstack/react-query'
import { useOutletContext } from 'react-router-dom'
import {
  AlertTriangle,
  CircleDollarSign,
  Droplets,
  Fuel,
  ShoppingCart,
} from 'lucide-react'
import { KpiCard } from '../components/KpiCard'
import { StatusBadge } from '../components/StatusBadge'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { apiFetch } from '../lib/api'
import {
  formatAccraDate,
  formatAccraDateTime,
  formatAccraTime,
  formatGhs,
} from '../lib/format'
import type { ResourceResponse } from '../types/api'
import type { DashboardSummary } from '../types/operations'
import AssignedShiftDashboard from './AssignedShiftDashboard'

function OperationsDashboard({
  showAttendantSales,
}: {
  showAttendantSales: boolean
}) {
  const dashboard = useQuery({
    queryKey: ['dashboard'],
    queryFn: () =>
      apiFetch<ResourceResponse<DashboardSummary>>('/api/v1/dashboard'),
    refetchInterval: 60_000,
  })
  const data = dashboard.data?.data

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-blue-600">
            Live station operations
          </p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">
            FuelFlow dashboard
          </h1>
          <p className="mt-2 text-sm text-slate-500">
            Sales, stock, procurement, receiving, and reconciliation for{' '}
            {data?.business_date ?? 'today'}.
          </p>
        </div>
        <StatusBadge
          label={dashboard.isFetching ? 'Refreshing' : 'Live data'}
          tone={dashboard.isError ? 'danger' : 'success'}
        />
      </header>

      <section
        aria-label="Operational summary"
        className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
      >
        <KpiCard
          detail={`${Number(data?.sales_litres ?? 0).toLocaleString('en-GH')} litres across ${data?.transactions ?? 0} transactions`}
          icon={<CircleDollarSign className="h-5 w-5" />}
          label="Today's gross sales"
          tone="green"
          value={formatGhs(data?.sales_value ?? 0)}
        />
        <KpiCard
          detail="Current book stock in authorized tanks"
          icon={<Droplets className="h-5 w-5" />}
          label="Fuel on hand"
          value={`${Number(data?.total_stock_litres ?? 0).toLocaleString('en-GH')} L`}
        />
        <KpiCard
          detail={`${data?.pending_deliveries ?? 0} deliveries require attention`}
          icon={<ShoppingCart className="h-5 w-5" />}
          label="Open purchase orders"
          tone="amber"
          value={String(data?.pending_purchase_orders ?? 0)}
        />
        <KpiCard
          detail="At or below configured minimum safe level"
          icon={<AlertTriangle className="h-5 w-5" />}
          label="Low-stock tanks"
          tone={(data?.low_tanks ?? 0) > 0 ? 'amber' : 'green'}
          value={String(data?.low_tanks ?? 0)}
        />
      </section>

      {showAttendantSales ? (
        <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <header className="border-b border-slate-200 px-6 py-5">
            <h2 className="font-bold">Daily sales by attendant</h2>
            <p className="mt-1 text-sm text-slate-500">
              Actual shift times and confirmed sales recorded by each Pump Attendant today.
            </p>
          </header>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[800px] text-left text-sm">
              <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-6 py-3">Date</th>
                  <th className="px-6 py-3">Attendant name</th>
                  <th className="px-6 py-3">Time started work</th>
                  <th className="px-6 py-3">Time closed</th>
                  <th className="px-6 py-3 text-right">Amount made</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {dashboard.isLoading ? (
                  <tr>
                    <td className="px-6 py-10 text-center text-slate-500" colSpan={5}>
                      Loading attendant sales…
                    </td>
                  </tr>
                ) : (data?.daily_attendant_sales ?? []).length === 0 ? (
                  <tr>
                    <td className="px-6 py-10 text-center text-slate-500" colSpan={5}>
                      No attendant shifts or sales have been recorded today.
                    </td>
                  </tr>
                ) : (
                  data?.daily_attendant_sales.map((row) => (
                    <tr className="hover:bg-slate-50/70" key={`${row.date}-${row.attendant_id}`}>
                      <td className="whitespace-nowrap px-6 py-4">{formatAccraDate(row.date)}</td>
                      <td className="px-6 py-4 font-semibold">{row.attendant_name}</td>
                      <td className="whitespace-nowrap px-6 py-4">
                        {row.started_at ? formatAccraTime(row.started_at) : 'Not started'}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        {row.closed_at
                          ? formatAccraTime(row.closed_at)
                          : row.started_at
                            ? 'In progress'
                            : '—'}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-right font-bold">
                        {formatGhs(row.amount)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      <section className="mt-6 grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
        <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-6 py-5">
            <h2 className="font-bold">Tank inventory</h2>
            <p className="mt-1 text-sm text-slate-500">
              Live book stock against physical tank capacity
            </p>
          </div>
          <div className="grid gap-4 p-6 sm:grid-cols-2">
            {(data?.tanks ?? []).map((tank) => (
              <div
                className="rounded-lg border border-slate-200 p-4"
                key={tank.id}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold">{tank.name}</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {tank.product_name} · {tank.station_name}
                    </p>
                  </div>
                  <Fuel className="h-4 w-4 text-blue-600" />
                </div>
                <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={`h-full rounded-full ${tank.stock_percentage <= 20 ? 'bg-rose-500' : 'bg-blue-600'}`}
                    style={{
                      width: `${Math.min(tank.stock_percentage, 100)}%`,
                    }}
                  />
                </div>
                <div className="mt-2 flex justify-between text-xs">
                  <span className="font-semibold">
                    {Number(tank.book_stock_litres).toLocaleString('en-GH')} L
                  </span>
                  <span className="text-slate-500">
                    {tank.stock_percentage}%
                  </span>
                </div>
              </div>
            ))}
          </div>
        </article>

        <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-6 py-5">
            <h2 className="font-bold">Recent sales</h2>
            <p className="mt-1 text-sm text-slate-500">
              Latest confirmed receipts in GHS
            </p>
          </div>
          <div className="divide-y divide-slate-100">
            {(data?.recent_sales ?? []).map((sale) => (
              <div className="flex items-center gap-3 px-6 py-4" key={sale.id}>
                <span className="grid h-9 w-9 place-items-center rounded-lg bg-emerald-50 text-emerald-600">
                  <CircleDollarSign className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">
                    {sale.receipt_number}
                  </p>
                  <p className="text-xs text-slate-500">
                    {sale.product_name} · {sale.litres} L ·{' '}
                    {formatAccraDateTime(sale.sold_at)}
                  </p>
                </div>
                <p className="text-sm font-bold">{formatGhs(sale.amount)}</p>
              </div>
            ))}
            {!data?.recent_sales.length ? (
              <p className="p-6 text-sm text-slate-500">
                No confirmed sales for this station today.
              </p>
            ) : null}
          </div>
        </article>
      </section>
    </div>
  )
}

export default function DashboardPage() {
  const { user } = useOutletContext<AuthenticatedOutletContext>()
  const isAttendant = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'cashier_attendant',
  )
  const isStationManager = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'station_manager',
  )

  return isAttendant ? (
    <AssignedShiftDashboard />
  ) : (
    <OperationsDashboard showAttendantSales={isStationManager} />
  )
}
