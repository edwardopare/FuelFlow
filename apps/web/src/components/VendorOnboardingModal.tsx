import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, Plus, ShieldCheck, Trash2, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, apiFetch } from '../lib/api'
import type {
  ResourceResponse,
  RoleSlug,
  VendorOrganization,
  VendorRole,
} from '../types/api'

type AccountDraft = {
  key: string
  role_slug: RoleSlug
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}

type OnboardingPayload = {
  company: {
    name: string
    slug: string
    registration_number: string
    contact_email: string
    phone: string
    address: string
    timezone: string
  }
  license: {
    duration: number
    unit: 'months' | 'years'
  }
  station: {
    name: string
    code: string
    station_number: string
    registration_number: string
    phone: string
    address: string
  }
  accounts: Array<Omit<AccountDraft, 'key'>>
}

type VendorOnboardingModalProps = {
  roles: VendorRole[]
  onClose: () => void
  onCreated: (organization: VendorOrganization) => void
}

const emptyCompany: OnboardingPayload['company'] = {
  name: '',
  slug: '',
  registration_number: '',
  contact_email: '',
  phone: '',
  address: '',
  timezone: 'Africa/Accra',
}

const emptyStation: OnboardingPayload['station'] = {
  name: '',
  code: '',
  station_number: '',
  registration_number: '',
  phone: '',
  address: '',
}

function newAccount(role_slug: RoleSlug): AccountDraft {
  return {
    key: crypto.randomUUID(),
    role_slug,
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  }
}

function slugify(value: string) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

type FieldProps = {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  required?: boolean
  type?: 'text' | 'email' | 'tel'
  placeholder?: string
}

function Field({
  id,
  label,
  value,
  onChange,
  required = false,
  type = 'text',
  placeholder,
}: FieldProps) {
  return (
    <div>
      <label className="text-xs font-bold text-slate-600" htmlFor={id}>
        {label}{required ? ' *' : ''}
      </label>
      <input
        className="form-input mt-1.5"
        id={id}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        required={required}
        type={type}
        value={value}
      />
    </div>
  )
}

export function VendorOnboardingModal({
  roles,
  onClose,
  onCreated,
}: VendorOnboardingModalProps) {
  const queryClient = useQueryClient()
  const [company, setCompany] = useState(emptyCompany)
  const [station, setStation] = useState(emptyStation)
  const [license, setLicense] = useState<OnboardingPayload['license']>({
    duration: 12,
    unit: 'months',
  })
  const [slugEdited, setSlugEdited] = useState(false)
  const [accounts, setAccounts] = useState<AccountDraft[]>([
    newAccount('administrator'),
  ])
  const [nextRole, setNextRole] = useState<RoleSlug>('owner')
  const mutation = useMutation({
    mutationFn: (payload: OnboardingPayload) =>
      apiFetch<ResourceResponse<VendorOrganization>>(
        '/api/v1/vendor/organizations',
        { method: 'POST', body: JSON.stringify(payload) },
      ),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['vendor-organizations'] }),
        queryClient.invalidateQueries({ queryKey: ['vendor-dashboard'] }),
      ])
      onCreated(response.data)
    },
  })

  const updateCompany = (field: keyof typeof company, value: string) => {
    setCompany((current) => ({ ...current, [field]: value }))
  }
  const updateStation = (field: keyof typeof station, value: string) => {
    setStation((current) => ({ ...current, [field]: value }))
  }
  const updateAccount = (
    key: string,
    field: keyof Omit<AccountDraft, 'key'>,
    value: string,
  ) => {
    setAccounts((current) =>
      current.map((account) =>
        account.key === key ? { ...account, [field]: value } : account,
      ),
    )
  }
  const submit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate({
      company,
      station,
      license,
      accounts: accounts.map(({ key: _key, ...account }) => account),
    })
  }
  const errorMessage =
    mutation.error instanceof ApiError
      ? Object.values(mutation.error.errors ?? {})[0]?.[0] ?? mutation.error.message
      : null

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/70 p-4 backdrop-blur-sm sm:p-8" role="presentation">
      <section aria-labelledby="onboard-title" aria-modal="true" className="w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5 sm:px-8">
          <div>
            <p className="text-xs font-bold uppercase tracking-widest text-blue-600">Tenant provisioning</p>
            <h2 className="mt-1 text-2xl font-bold" id="onboard-title">Onboard a company</h2>
            <p className="mt-1 text-sm text-slate-500">Company, Head Office, first station, and role accounts are committed together.</p>
          </div>
          <button aria-label="Close onboarding form" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" onClick={onClose} type="button"><X className="h-5 w-5" /></button>
        </header>

        <form onSubmit={submit}>
          <div className="max-h-[calc(100vh-13rem)] space-y-8 overflow-y-auto px-6 py-6 sm:px-8">
            <fieldset>
              <legend className="flex items-center gap-2 text-base font-bold"><Building2 className="h-5 w-5 text-blue-600" /> 1. Company information</legend>
              <p className="mt-1 text-sm text-slate-500">All customer companies use Ghanaian cedi (GHS).</p>
              <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Field id="company-name" label="Registered company name" onChange={(value) => { updateCompany('name', value); if (!slugEdited) updateCompany('slug', slugify(value)) }} required value={company.name} />
                <Field id="company-slug" label="System identifier" onChange={(value) => { setSlugEdited(true); updateCompany('slug', slugify(value)) }} required value={company.slug} />
                <Field id="company-registration" label="Registration number" onChange={(value) => updateCompany('registration_number', value)} value={company.registration_number} />
                <Field id="company-email" label="Contact email" onChange={(value) => updateCompany('contact_email', value)} required type="email" value={company.contact_email} />
                <Field id="company-phone" label="Contact phone" onChange={(value) => updateCompany('phone', value)} required type="tel" value={company.phone} />
                <div className="sm:col-span-2 lg:col-span-1"><Field id="company-address" label="Registered address" onChange={(value) => updateCompany('address', value)} required value={company.address} /></div>
              </div>
              <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4">
                <div className="flex items-center gap-2 font-bold text-blue-950">
                  <ShieldCheck className="h-5 w-5 text-blue-600" />
                  Company license tenure
                </div>
                <p className="mt-1 text-xs leading-5 text-blue-700">
                  Tenant access will stop automatically when this period ends. The vendor can renew or deactivate the license later.
                </p>
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                  <div>
                    <label className="text-xs font-bold text-blue-900" htmlFor="license-duration">Number of months or years *</label>
                    <input
                      className="form-input mt-1.5"
                      id="license-duration"
                      min={1}
                      onChange={(event) => setLicense((current) => ({ ...current, duration: Number(event.target.value) }))}
                      required
                      type="number"
                      value={license.duration}
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-blue-900" htmlFor="license-unit">Tenure unit *</label>
                    <select
                      className="form-input mt-1.5"
                      id="license-unit"
                      onChange={(event) => setLicense((current) => ({ ...current, unit: event.target.value as 'months' | 'years' }))}
                      value={license.unit}
                    >
                      <option value="months">Months</option>
                      <option value="years">Years</option>
                    </select>
                  </div>
                </div>
              </div>
            </fieldset>

            <fieldset className="border-t border-slate-200 pt-7">
              <legend className="text-base font-bold">2. First operating station</legend>
              <p className="mt-1 text-sm text-slate-500">Head Office (HO-0001) is created automatically for organization-wide roles.</p>
              <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Field id="station-name" label="Station name" onChange={(value) => updateStation('name', value)} required value={station.name} />
                <Field id="station-code" label="Station code" onChange={(value) => updateStation('code', value.toUpperCase().replace(/[^A-Z0-9-]/g, ''))} placeholder="ACC-001" required value={station.code} />
                <Field id="station-number" label="Station number" onChange={(value) => updateStation('station_number', value)} placeholder="STN-0001" required value={station.station_number} />
                <Field id="station-registration" label="Registration number" onChange={(value) => updateStation('registration_number', value)} value={station.registration_number} />
                <Field id="station-phone" label="Station phone" onChange={(value) => updateStation('phone', value)} type="tel" value={station.phone} />
                <Field id="station-address" label="Station address" onChange={(value) => updateStation('address', value)} required value={station.address} />
              </div>
            </fieldset>

            <fieldset className="border-t border-slate-200 pt-7">
              <legend className="flex items-center gap-2 text-base font-bold"><ShieldCheck className="h-5 w-5 text-blue-600" /> 3. Initial role accounts</legend>
              <p className="mt-1 text-sm text-slate-500">Administrator is mandatory. Every account receives its implemented permissions and must replace the temporary password.</p>
              <div className="mt-4 space-y-4">
                {accounts.map((account, index) => {
                  const role = roles.find((candidate) => candidate.slug === account.role_slug)
                  const locked = index === 0
                  return (
                    <article className="rounded-xl border border-slate-200 bg-slate-50/60 p-4" key={account.key}>
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex-1">
                          <label className="text-xs font-bold text-slate-600" htmlFor={`role-${account.key}`}>Role</label>
                          <select className="form-input mt-1.5" disabled={locked} id={`role-${account.key}`} onChange={(event) => updateAccount(account.key, 'role_slug', event.target.value)} value={account.role_slug}>
                            {roles.map((option) => <option key={option.slug} value={option.slug}>{option.name}</option>)}
                          </select>
                          <p className="mt-2 text-xs text-slate-500">{role?.description} · {role?.permissions.length ?? 0} permissions</p>
                        </div>
                        {!locked ? <button aria-label={`Remove ${role?.name ?? 'role'} account`} className="mt-5 rounded-lg p-2 text-rose-600 hover:bg-rose-50" onClick={() => setAccounts((current) => current.filter((item) => item.key !== account.key))} type="button"><Trash2 className="h-4 w-4" /></button> : null}
                      </div>
                      <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field id={`name-${account.key}`} label="Full name" onChange={(value) => updateAccount(account.key, 'name', value)} required value={account.name} />
                        <Field id={`email-${account.key}`} label="Email address" onChange={(value) => updateAccount(account.key, 'email', value)} required type="email" value={account.email} />
                        <Field id={`phone-${account.key}`} label="Phone" onChange={(value) => updateAccount(account.key, 'phone', value)} type="tel" value={account.phone} />
                        <div><label className="text-xs font-bold text-slate-600" htmlFor={`password-${account.key}`}>Temporary password *</label><input className="form-input mt-1.5" id={`password-${account.key}`} minLength={12} onChange={(event) => updateAccount(account.key, 'password', event.target.value)} required type="password" value={account.password} /></div>
                        <div><label className="text-xs font-bold text-slate-600" htmlFor={`confirmation-${account.key}`}>Confirm password *</label><input className="form-input mt-1.5" id={`confirmation-${account.key}`} minLength={12} onChange={(event) => updateAccount(account.key, 'password_confirmation', event.target.value)} required type="password" value={account.password_confirmation} /></div>
                      </div>
                    </article>
                  )
                })}
              </div>
              <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                <select aria-label="Role for next account" className="form-input sm:max-w-xs" onChange={(event) => setNextRole(event.target.value as RoleSlug)} value={nextRole}>
                  {roles.map((role) => <option key={role.slug} value={role.slug}>{role.name}</option>)}
                </select>
                <button className="inline-flex items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100" onClick={() => setAccounts((current) => [...current, newAccount(nextRole)])} type="button"><Plus className="h-4 w-4" /> Add role account</button>
              </div>
            </fieldset>

            {errorMessage ? <p className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700" role="alert">{errorMessage}</p> : null}
          </div>
          <footer className="flex flex-col-reverse gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end sm:px-8">
            <button className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700" onClick={onClose} type="button">Cancel</button>
            <button className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-60" disabled={mutation.isPending} type="submit">{mutation.isPending ? 'Creating company…' : 'Create company and accounts'}</button>
          </footer>
        </form>
      </section>
    </div>
  )
}
