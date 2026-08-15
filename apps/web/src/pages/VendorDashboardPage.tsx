import { useQuery } from '@tanstack/react-query'
import {
  Building2,
  MapPinned,
  ShieldAlert,
  ShieldCheck,
  UserRoundCheck,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import { KpiCard } from '../components/KpiCard'
import { StatusBadge } from '../components/StatusBadge'
import { apiFetch } from '../lib/api'
import { formatAccraDateTime } from '../lib/format'
import type { ResourceResponse, VendorDashboard } from '../types/api'

export default function VendorDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['vendor-dashboard'],
    queryFn: () =>
      apiFetch<ResourceResponse<VendorDashboard>>('/api/v1/vendor/dashboard'),
  })
  const data = dashboard.data?.data
  const total = data?.metrics.companies ?? 0
  const active = data?.metrics.active_companies ?? 0
  const activePercentage = total > 0 ? Math.round((active / total) * 100) : 0

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-bold text-blue-600">Vendor overview</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight">Super User dashboard</h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
            Onboard customer companies, govern role accounts, and control tenant access from one secure workspace.
          </p>
        </div>
        <Link className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700" to="/vendor/companies?onboard=true">
          <Building2 aria-hidden className="h-4 w-4" /> Onboard company
        </Link>
      </header>

      <section aria-label="Platform summary" className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <KpiCard icon={<Building2 className="h-5 w-5" />} label="Customer companies" value={String(total)} detail={`${active} currently active`} />
        <KpiCard icon={<ShieldCheck className="h-5 w-5" />} label="Active companies" value={String(active)} detail={`${activePercentage}% of onboarded companies`} tone="green" />
        <KpiCard icon={<MapPinned className="h-5 w-5" />} label="Operating stations" value={String(data?.metrics.operating_stations ?? 0)} detail="Head Office locations excluded" />
        <KpiCard icon={<UserRoundCheck className="h-5 w-5" />} label="Provisioned accounts" value={String(data?.metrics.accounts ?? 0)} detail="Across all tenant roles" tone="amber" />
        <KpiCard icon={<ShieldAlert className="h-5 w-5" />} label="License alerts" value={String((data?.metrics.expired_licenses ?? 0) + (data?.metrics.deactivated_licenses ?? 0))} detail={`${data?.metrics.licenses_expiring_soon ?? 0} expire within 30 days`} tone="amber" />
      </section>

      <section className="mt-6 grid gap-6 xl:grid-cols-[0.7fr_1.3fr]">
        <article className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="font-bold">Company access health</h2>
          <p className="mt-1 text-sm text-slate-500">Current tenant activation split</p>
          <div className="mt-8 flex items-end gap-3" aria-label={`${activePercentage}% of companies are active`}>
            <div className="h-4 flex-1 overflow-hidden rounded-full bg-rose-100">
              <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${activePercentage}%` }} />
            </div>
            <span className="text-sm font-bold text-emerald-700">{activePercentage}% active</span>
          </div>
          <dl className="mt-7 grid grid-cols-3 gap-3">
            <div className="rounded-lg bg-emerald-50 p-4"><dt className="text-xs font-semibold text-emerald-700">Active</dt><dd className="mt-1 text-2xl font-bold">{active}</dd></div>
            <div className="rounded-lg bg-amber-50 p-4"><dt className="text-xs font-semibold text-amber-700">Suspended</dt><dd className="mt-1 text-2xl font-bold">{data?.metrics.suspended_companies ?? 0}</dd></div>
            <div className="rounded-lg bg-rose-50 p-4"><dt className="text-xs font-semibold text-rose-700">License off</dt><dd className="mt-1 text-2xl font-bold">{(data?.metrics.expired_licenses ?? 0) + (data?.metrics.deactivated_licenses ?? 0)}</dd></div>
          </dl>
        </article>

        <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <header className="flex items-center justify-between border-b border-slate-200 px-6 py-5">
            <div><h2 className="font-bold">Recently onboarded</h2><p className="mt-1 text-sm text-slate-500">Latest customer companies</p></div>
            <Link className="text-sm font-semibold text-blue-600" to="/vendor/companies">View all</Link>
          </header>
          {dashboard.isLoading ? <p className="p-8 text-center text-sm text-slate-500">Loading companies…</p> : null}
          {dashboard.error ? <p className="m-6 rounded-lg bg-rose-50 p-4 text-sm text-rose-700" role="alert">Dashboard data could not be loaded.</p> : null}
          <div className="divide-y divide-slate-100">
            {data?.recent_companies.map((company) => (
              <Link className="flex items-center gap-4 px-6 py-4 hover:bg-slate-50" key={company.id} to={`/vendor/companies?company=${company.id}`}>
                <span className="grid h-10 w-10 place-items-center rounded-lg bg-blue-50 text-blue-600"><Building2 className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1"><p className="truncate text-sm font-bold">{company.name}</p><p className="mt-0.5 text-xs text-slate-500">{company.users_count} accounts · {company.stations_count} stations · License expires {company.license.expires_at ? formatAccraDateTime(company.license.expires_at) : 'not set'}</p></div>
                <StatusBadge label={company.status.replaceAll('_', ' ')} tone={company.status === 'active' ? 'success' : company.status === 'suspended' ? 'warning' : 'danger'} />
              </Link>
            ))}
            {!dashboard.isLoading && !data?.recent_companies.length ? <p className="p-8 text-center text-sm text-slate-500">No companies have been onboarded yet.</p> : null}
          </div>
        </article>
      </section>

      <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header className="border-b border-slate-200 px-6 py-5">
          <h2 className="font-bold">Vendor activity</h2>
          <p className="mt-1 text-sm text-slate-500">Immutable security record of onboarding and access changes</p>
        </header>
        <div className="divide-y divide-slate-100">
          {data?.recent_activity.map((activity) => (
            <div className="flex flex-col gap-1 px-6 py-4 sm:flex-row sm:items-center sm:justify-between" key={activity.id}>
              <div><p className="text-sm font-semibold">{activity.action.replaceAll('.', ' ')}</p><p className="mt-0.5 text-xs text-slate-500">{activity.actor?.name ?? 'System'}{activity.reason ? ` · ${activity.reason}` : ''}</p></div>
              <time className="text-xs text-slate-500" dateTime={activity.created_at}>{formatAccraDateTime(activity.created_at)}</time>
            </div>
          ))}
          {!dashboard.isLoading && !data?.recent_activity.length ? <p className="p-8 text-center text-sm text-slate-500">No vendor activity has been recorded yet.</p> : null}
        </div>
      </section>
    </div>
  )
}
