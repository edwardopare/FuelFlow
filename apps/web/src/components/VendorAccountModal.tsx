import { useMutation, useQueryClient } from '@tanstack/react-query'
import { UserPlus, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, apiFetch } from '../lib/api'
import type { ResourceResponse, RoleSlug, VendorOrganization, VendorRole } from '../types/api'

type VendorAccountModalProps = {
  organization: VendorOrganization
  roles: VendorRole[]
  onClose: () => void
  onCreated: (organization: VendorOrganization) => void
}

const organizationRoles: RoleSlug[] = ['administrator', 'owner', 'accountant', 'auditor']

export function VendorAccountModal({ organization, roles, onClose, onCreated }: VendorAccountModalProps) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ name: '', email: '', phone: '', role_slug: 'station_manager' as RoleSlug, station_id: '', password: '', password_confirmation: '' })
  const mutation = useMutation({
    mutationFn: () => apiFetch<ResourceResponse<VendorOrganization>>(`/api/v1/vendor/organizations/${organization.id}/accounts`, { method: 'POST', body: JSON.stringify({ ...form, station_id: organizationRoles.includes(form.role_slug) ? null : form.station_id }) }),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['vendor-organization', organization.id] }),
        queryClient.invalidateQueries({ queryKey: ['vendor-organizations'] }),
        queryClient.invalidateQueries({ queryKey: ['vendor-dashboard'] }),
      ])
      onCreated(response.data)
    },
  })
  const operatingStations = organization.stations?.filter((station) => station.station_number !== 'HO-0001') ?? []
  const selectedRole = roles.find((role) => role.slug === form.role_slug)
  const errorMessage = mutation.error instanceof ApiError ? Object.values(mutation.error.errors ?? {})[0]?.[0] ?? mutation.error.message : null
  const update = (field: keyof typeof form, value: string) => setForm((current) => ({ ...current, [field]: value }))
  const submit = (event: FormEvent) => { event.preventDefault(); mutation.mutate() }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/70 p-4 backdrop-blur-sm">
      <section aria-labelledby="account-title" aria-modal="true" className="w-full max-w-2xl rounded-2xl bg-white shadow-2xl" role="dialog">
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div><p className="text-xs font-bold uppercase tracking-widest text-blue-600">{organization.name}</p><h2 className="mt-1 text-xl font-bold" id="account-title">Create a role account</h2></div>
          <button aria-label="Close account form" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" onClick={onClose} type="button"><X className="h-5 w-5" /></button>
        </header>
        <form onSubmit={submit}>
          <div className="grid gap-4 p-6 sm:grid-cols-2">
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-role">Role *</label><select className="form-input mt-1.5" id="account-role" onChange={(event) => update('role_slug', event.target.value)} value={form.role_slug}>{roles.map((role) => <option key={role.slug} value={role.slug}>{role.name}</option>)}</select><p className="mt-1.5 text-xs text-slate-500">{selectedRole?.description} · {selectedRole?.permissions.length ?? 0} permissions</p></div>
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-station">Station assignment</label><select className="form-input mt-1.5" disabled={organizationRoles.includes(form.role_slug)} id="account-station" onChange={(event) => update('station_id', event.target.value)} required={!organizationRoles.includes(form.role_slug)} value={organizationRoles.includes(form.role_slug) ? '' : form.station_id}><option value="">{organizationRoles.includes(form.role_slug) ? 'Head Office (automatic)' : 'Select operating station'}</option>{operatingStations.map((station) => <option key={station.id} value={station.id}>{station.name}</option>)}</select></div>
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-name">Full name *</label><input className="form-input mt-1.5" id="account-name" onChange={(event) => update('name', event.target.value)} required value={form.name} /></div>
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-email">Email *</label><input className="form-input mt-1.5" id="account-email" onChange={(event) => update('email', event.target.value)} required type="email" value={form.email} /></div>
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-phone">Phone</label><input className="form-input mt-1.5" id="account-phone" onChange={(event) => update('phone', event.target.value)} type="tel" value={form.phone} /></div>
            <div />
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-password">Temporary password *</label><input className="form-input mt-1.5" id="account-password" minLength={12} onChange={(event) => update('password', event.target.value)} required type="password" value={form.password} /></div>
            <div><label className="text-xs font-bold text-slate-600" htmlFor="account-confirmation">Confirm password *</label><input className="form-input mt-1.5" id="account-confirmation" minLength={12} onChange={(event) => update('password_confirmation', event.target.value)} required type="password" value={form.password_confirmation} /></div>
            {errorMessage ? <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700 sm:col-span-2" role="alert">{errorMessage}</p> : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold" onClick={onClose} type="button">Cancel</button><button className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60" disabled={mutation.isPending} type="submit"><UserPlus className="h-4 w-4" />{mutation.isPending ? 'Creating…' : 'Create account'}</button></footer>
        </form>
      </section>
    </div>
  )
}
