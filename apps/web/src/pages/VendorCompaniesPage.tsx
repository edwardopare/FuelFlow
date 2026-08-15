import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban,
  Building2,
  CalendarClock,
  CheckCircle2,
  MapPin,
  Plus,
  Search,
  ShieldAlert,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import { useDeferredValue, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { VendorAccountModal } from '../components/VendorAccountModal'
import { VendorLicenseModal } from '../components/VendorLicenseModal'
import { VendorOnboardingModal } from '../components/VendorOnboardingModal'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import { formatAccraDateTime } from '../lib/format'
import type {
  PaginatedResponse,
  ResourceResponse,
  VendorOrganization,
  VendorRole,
} from '../types/api'

function accountTone(
  status: NonNullable<VendorOrganization['accounts']>[number]['status'],
) {
  if (status === 'active') return 'success' as const
  if (status === 'pending_first_login') return 'warning' as const
  if (status === 'locked') return 'danger' as const
  return 'neutral' as const
}

function companyTone(status: VendorOrganization['status']) {
  if (status === 'active') return 'success' as const
  if (status === 'suspended') return 'warning' as const
  return 'danger' as const
}

function licenseTenure(license: VendorOrganization['license']) {
  const unit = license.duration === 1 ? license.unit.slice(0, -1) : license.unit
  return `${license.duration} ${unit}`
}

type AccessReasonModalProps = {
  company: VendorOrganization
  mode: 'suspension' | 'license'
  pending: boolean
  error: string | null
  onClose: () => void
  onSubmit: (reason: string) => void
}

function AccessReasonModal({
  company,
  mode,
  pending,
  error,
  onClose,
  onSubmit,
}: AccessReasonModalProps) {
  const [reason, setReason] = useState('')
  const isLicense = mode === 'license'
  const submit = (event: FormEvent) => {
    event.preventDefault()
    onSubmit(reason)
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/70 p-4 backdrop-blur-sm">
      <section
        aria-labelledby="access-action-title"
        aria-modal="true"
        className="w-full max-w-lg rounded-2xl bg-white shadow-2xl"
        role="dialog"
      >
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-xs font-bold uppercase tracking-widest text-rose-600">
              {isLicense ? 'License control' : 'Access control'}
            </p>
            <h2 className="mt-1 text-xl font-bold" id="access-action-title">
              {isLicense ? 'Deactivate license for' : 'Suspend'} {company.name}
            </h2>
          </div>
          <button
            aria-label={isLicense ? 'Close license deactivation form' : 'Close suspension form'}
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
            onClick={onClose}
            type="button"
          >
            <X className="h-5 w-5" />
          </button>
        </header>
        <form onSubmit={submit}>
          <div className="p-6">
            <p className="text-sm leading-6 text-slate-600">
              {isLicense
                ? 'The company license will be withdrawn and every tenant role will lose access immediately. License history will be preserved for a future renewal.'
                : 'All company sessions will be revoked immediately and staff will be unable to sign in until the vendor reactivates the company.'}
            </p>
            <label className="mt-5 block text-sm font-bold text-slate-700" htmlFor="access-reason">
              {isLicense ? 'Reason for license deactivation' : 'Reason for suspension'} *
            </label>
            <textarea
              className="form-input mt-2 min-h-28"
              id="access-reason"
              minLength={10}
              onChange={(event) => setReason(event.target.value)}
              required
              value={reason}
            />
            {error ? (
              <p className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert">
                {error}
              </p>
            ) : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
            <button className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold" onClick={onClose} type="button">
              Cancel
            </button>
            <button className="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60" disabled={pending} type="submit">
              {pending ? 'Saving…' : isLicense ? 'Deactivate license' : 'Suspend company'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

export default function VendorCompaniesPage() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [showOnboarding, setShowOnboarding] = useState(
    params.get('onboard') === 'true',
  )
  const [showAccount, setShowAccount] = useState(false)
  const [showLicense, setShowLicense] = useState(false)
  const [suspendCompany, setSuspendCompany] =
    useState<VendorOrganization | null>(null)
  const [licenseCompany, setLicenseCompany] =
    useState<VendorOrganization | null>(null)
  const deferredSearch = useDeferredValue(search.trim())
  const selectedId = params.get('company')
  const query = new URLSearchParams()

  if (deferredSearch) query.set('search', deferredSearch)
  if (status) query.set('status', status)

  const organizations = useQuery({
    queryKey: ['vendor-organizations', deferredSearch, status],
    queryFn: () =>
      apiFetch<PaginatedResponse<VendorOrganization>>(
        `/api/v1/vendor/organizations${query.size ? `?${query}` : ''}`,
      ),
  })
  const roles = useQuery({
    queryKey: ['vendor-roles'],
    queryFn: () =>
      apiFetch<ResourceResponse<VendorRole[]>>('/api/v1/vendor/roles'),
  })
  const selected = useQuery({
    queryKey: ['vendor-organization', selectedId],
    queryFn: () =>
      apiFetch<ResourceResponse<VendorOrganization>>(
        `/api/v1/vendor/organizations/${selectedId}`,
      ),
    enabled: Boolean(selectedId),
  })
  const lifecycle = useMutation({
    mutationFn: ({
      company,
      action,
      reason,
    }: {
      company: VendorOrganization
      action: 'activate' | 'suspend'
      reason?: string
    }) =>
      apiFetch<ResourceResponse<VendorOrganization>>(
        `/api/v1/vendor/organizations/${company.id}/${action}`,
        {
          method: 'POST',
          body: JSON.stringify(reason ? { reason } : {}),
        },
      ),
    onSuccess: async () => {
      setSuspendCompany(null)
      await invalidateCompanyQueries()
    },
  })
  const deactivateLicense = useMutation({
    mutationFn: ({
      company,
      reason,
    }: {
      company: VendorOrganization
      reason: string
    }) =>
      apiFetch<ResourceResponse<VendorOrganization>>(
        `/api/v1/vendor/organizations/${company.id}/license/deactivate`,
        { method: 'POST', body: JSON.stringify({ reason }) },
      ),
    onSuccess: async () => {
      setLicenseCompany(null)
      await invalidateCompanyQueries()
    },
  })

  const company = selected.data?.data
  const lifecycleError =
    lifecycle.error instanceof ApiError ? lifecycle.error.message : null
  const licenseError =
    deactivateLicense.error instanceof ApiError
      ? deactivateLicense.error.message
      : null

  function invalidateCompanyQueries() {
    return Promise.all([
      queryClient.invalidateQueries({ queryKey: ['vendor-organizations'] }),
      queryClient.invalidateQueries({ queryKey: ['vendor-organization'] }),
      queryClient.invalidateQueries({ queryKey: ['vendor-dashboard'] }),
    ])
  }

  const closeOnboarding = () => {
    setShowOnboarding(false)
    const next = new URLSearchParams(params)
    next.delete('onboard')
    setParams(next, { replace: true })
  }
  const selectCompany = (id: string) => {
    const next = new URLSearchParams(params)
    next.set('company', id)
    next.delete('onboard')
    setParams(next)
  }

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-sm font-bold text-blue-600">Tenant administration</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight">Companies and licenses</h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
            Provision customer companies, control license tenure, and manage their stations and role accounts.
          </p>
        </div>
        <button className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700" onClick={() => setShowOnboarding(true)} type="button">
          <Plus className="h-4 w-4" /> Onboard company
        </button>
      </header>

      <section className="mt-7 grid min-h-[680px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm xl:grid-cols-[0.75fr_1.25fr]">
        <div className="border-b border-slate-200 xl:border-b-0 xl:border-r">
          <div className="space-y-3 border-b border-slate-200 p-4">
            <label className="relative block">
              <span className="sr-only">Search companies</span>
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input className="w-full rounded-lg border border-slate-200 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50" onChange={(event) => setSearch(event.target.value)} placeholder="Search company or registration" type="search" value={search} />
            </label>
            <select aria-label="Filter companies by status" className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm" onChange={(event) => setStatus(event.target.value)} value={status}>
              <option value="">All company statuses</option>
              <option value="active">Active license</option>
              <option value="suspended">Access suspended</option>
              <option value="expired">License expired</option>
              <option value="license_deactivated">License deactivated</option>
            </select>
          </div>
          <div className="max-h-[570px] divide-y divide-slate-100 overflow-y-auto">
            {organizations.isLoading ? <p className="p-8 text-center text-sm text-slate-500">Loading companies…</p> : null}
            {organizations.error ? <p className="m-4 rounded-lg bg-rose-50 p-4 text-sm text-rose-700" role="alert">Companies could not be loaded.</p> : null}
            {organizations.data?.data.map((item) => (
              <button className={`w-full px-5 py-4 text-left transition ${selectedId === item.id ? 'bg-blue-50' : 'hover:bg-slate-50'}`} key={item.id} onClick={() => selectCompany(item.id)} type="button">
                <div className="flex items-start gap-3">
                  <span className={`mt-0.5 grid h-9 w-9 place-items-center rounded-lg ${selectedId === item.id ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'}`}><Building2 className="h-4 w-4" /></span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2"><p className="truncate text-sm font-bold">{item.name}</p><StatusBadge label={item.status.replaceAll('_', ' ')} tone={companyTone(item.status)} /></div>
                    <p className="mt-1 text-xs text-slate-500">{item.users_count} accounts · {item.stations_count} stations · {licenseTenure(item.license)}</p>
                  </div>
                </div>
              </button>
            ))}
            {!organizations.isLoading && organizations.data?.data.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No companies match these filters.</p> : null}
          </div>
        </div>

        <div className="min-w-0">
          {!selectedId ? (
            <div className="grid h-full min-h-96 place-items-center p-8 text-center"><div><span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-blue-50 text-blue-600"><Building2 className="h-6 w-6" /></span><h2 className="mt-4 text-lg font-bold">Select a company</h2><p className="mt-2 text-sm text-slate-500">Company license, stations, and accounts will appear here.</p></div></div>
          ) : selected.isLoading ? (
            <p className="p-10 text-center text-sm text-slate-500">Loading company details…</p>
          ) : selected.error || !company ? (
            <p className="m-6 rounded-lg bg-rose-50 p-4 text-sm text-rose-700" role="alert">Company details could not be loaded.</p>
          ) : (
            <div>
              <header className="border-b border-slate-200 p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <div className="flex items-center gap-3"><h2 className="text-2xl font-bold">{company.name}</h2><StatusBadge label={company.status.replaceAll('_', ' ')} tone={companyTone(company.status)} /></div>
                    <p className="mt-2 text-sm text-slate-500">{company.registration_number || 'No registration number'} · {company.contact_email || 'No contact email'} · GHS</p>
                    <p className="mt-1 text-xs text-slate-400">Onboarded {formatAccraDateTime(company.created_at)}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button className="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700" onClick={() => setShowAccount(true)} type="button"><UserPlus className="h-4 w-4" /> Add account</button>
                    <button className="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700" onClick={() => setShowLicense(true)} type="button"><CalendarClock className="h-4 w-4" /> {company.status === 'expired' || company.status === 'license_deactivated' ? 'Renew license' : 'Edit tenure'}</button>
                    {company.status === 'active' ? <button className="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700" onClick={() => setSuspendCompany(company)} type="button"><ShieldAlert className="h-4 w-4" /> Suspend access</button> : null}
                    {company.status === 'suspended' ? <button className="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-50" disabled={lifecycle.isPending} onClick={() => lifecycle.mutate({ company, action: 'activate' })} type="button"><CheckCircle2 className="h-4 w-4" /> Restore access</button> : null}
                    {company.status !== 'license_deactivated' ? <button className="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700" onClick={() => setLicenseCompany(company)} type="button"><Ban className="h-4 w-4" /> Deactivate license</button> : null}
                  </div>
                </div>

                <div className={`mt-5 rounded-xl border p-4 ${company.license.status === 'active' ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'}`}>
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3"><CalendarClock className={`mt-0.5 h-5 w-5 ${company.license.status === 'active' ? 'text-emerald-600' : 'text-rose-600'}`} /><div><p className="text-sm font-bold">License: {licenseTenure(company.license)}</p><p className="mt-1 text-xs text-slate-600">Started {company.license.started_at ? formatAccraDateTime(company.license.started_at) : 'not recorded'} · Expires {company.license.expires_at ? formatAccraDateTime(company.license.expires_at) : 'not set'}</p></div></div>
                    <p className="text-sm font-bold">{company.license.status === 'active' ? `${company.license.remaining_days ?? 0} days remaining` : company.license.status.replaceAll('_', ' ')}</p>
                  </div>
                  {company.license.deactivation_reason ? <p className="mt-3 border-t border-rose-200 pt-3 text-xs text-rose-700">Reason: {company.license.deactivation_reason}</p> : null}
                </div>

                <dl className="mt-5 grid gap-3 sm:grid-cols-3"><div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs font-semibold text-slate-500">Accounts</dt><dd className="mt-1 text-xl font-bold">{company.users_count}</dd></div><div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs font-semibold text-slate-500">Operating stations</dt><dd className="mt-1 text-xl font-bold">{company.stations_count}</dd></div><div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs font-semibold text-slate-500">Timezone</dt><dd className="mt-1 text-sm font-bold">{company.timezone}</dd></div></dl>
              </header>

              <section className="border-b border-slate-200 p-6"><div className="flex items-center gap-2"><MapPin className="h-5 w-5 text-blue-600" /><h3 className="font-bold">Locations</h3></div><div className="mt-4 grid gap-3 sm:grid-cols-2">{company.stations?.map((station) => <article className="rounded-lg border border-slate-200 p-4" key={station.id}><div className="flex items-start justify-between"><div><p className="text-sm font-bold">{station.name}</p><p className="mt-1 text-xs text-slate-500">{station.code} · {station.station_number}</p></div><StatusBadge label={station.station_number === 'HO-0001' ? 'Head Office' : 'Station'} tone={station.station_number === 'HO-0001' ? 'neutral' : 'success'} /></div><p className="mt-3 text-xs text-slate-500">{station.address || 'Address not recorded'}</p></article>)}</div></section>

              <section className="p-6"><div className="flex items-center gap-2"><Users className="h-5 w-5 text-blue-600" /><h3 className="font-bold">Role accounts</h3></div><div className="mt-4 overflow-x-auto rounded-lg border border-slate-200"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Account</th><th className="px-4 py-3">Role</th><th className="px-4 py-3">Assignment</th><th className="px-4 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{company.accounts?.map((account) => <tr key={account.id}><td className="px-4 py-3"><p className="font-semibold">{account.name}</p><p className="text-xs text-slate-500">{account.email}</p></td><td className="px-4 py-3">{account.role?.name ?? 'Unassigned'}</td><td className="px-4 py-3 text-slate-600">{account.station?.name ?? '—'}</td><td className="px-4 py-3"><StatusBadge label={account.status.replaceAll('_', ' ')} tone={accountTone(account.status)} /></td></tr>)}{!company.accounts?.length ? <tr><td className="px-4 py-8 text-center text-slate-500" colSpan={4}>No role accounts found.</td></tr> : null}</tbody></table></div></section>
            </div>
          )}
        </div>
      </section>

      {showOnboarding ? <VendorOnboardingModal roles={roles.data?.data ?? []} onClose={closeOnboarding} onCreated={(created) => { closeOnboarding(); selectCompany(created.id) }} /> : null}
      {showAccount && company ? <VendorAccountModal organization={company} roles={roles.data?.data ?? []} onClose={() => setShowAccount(false)} onCreated={() => setShowAccount(false)} /> : null}
      {showLicense && company ? <VendorLicenseModal organization={company} onClose={() => setShowLicense(false)} onUpdated={() => setShowLicense(false)} /> : null}
      {suspendCompany ? <AccessReasonModal company={suspendCompany} error={lifecycleError} mode="suspension" onClose={() => setSuspendCompany(null)} onSubmit={(reason) => lifecycle.mutate({ company: suspendCompany, action: 'suspend', reason })} pending={lifecycle.isPending} /> : null}
      {licenseCompany ? <AccessReasonModal company={licenseCompany} error={licenseError} mode="license" onClose={() => setLicenseCompany(null)} onSubmit={(reason) => deactivateLicense.mutate({ company: licenseCompany, reason })} pending={deactivateLicense.isPending} /> : null}
    </div>
  )
}
