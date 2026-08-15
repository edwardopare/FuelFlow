import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, apiFetch } from '../lib/api'
import type { ResourceResponse, VendorOrganization } from '../types/api'

type VendorLicenseModalProps = {
  organization: VendorOrganization
  onClose: () => void
  onUpdated: (organization: VendorOrganization) => void
}

export function VendorLicenseModal({
  organization,
  onClose,
  onUpdated,
}: VendorLicenseModalProps) {
  const queryClient = useQueryClient()
  const [duration, setDuration] = useState(organization.license.duration || 12)
  const [unit, setUnit] = useState<'months' | 'years'>(
    organization.license.unit || 'months',
  )
  const mutation = useMutation({
    mutationFn: () =>
      apiFetch<ResourceResponse<VendorOrganization>>(
        `/api/v1/vendor/organizations/${organization.id}/license`,
        {
          method: 'PATCH',
          body: JSON.stringify({ duration, unit }),
        },
      ),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['vendor-organization', organization.id],
        }),
        queryClient.invalidateQueries({ queryKey: ['vendor-organizations'] }),
        queryClient.invalidateQueries({ queryKey: ['vendor-dashboard'] }),
      ])
      onUpdated(response.data)
    },
  })
  const errorMessage =
    mutation.error instanceof ApiError
      ? Object.values(mutation.error.errors ?? {})[0]?.[0] ?? mutation.error.message
      : null
  const submit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate()
  }
  const isRenewal = organization.status === 'expired'
    || organization.status === 'license_deactivated'

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/70 p-4 backdrop-blur-sm">
      <section
        aria-labelledby="license-title"
        aria-modal="true"
        className="w-full max-w-lg rounded-2xl bg-white shadow-2xl"
        role="dialog"
      >
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-xs font-bold uppercase tracking-widest text-blue-600">
              {organization.name}
            </p>
            <h2 className="mt-1 text-xl font-bold" id="license-title">
              {isRenewal ? 'Renew and activate license' : 'Edit license tenure'}
            </h2>
          </div>
          <button
            aria-label="Close license form"
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
            onClick={onClose}
            type="button"
          >
            <X className="h-5 w-5" />
          </button>
        </header>
        <form onSubmit={submit}>
          <div className="p-6">
            <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900">
              <CalendarClock className="mb-2 h-5 w-5 text-blue-600" />
              The new tenure begins immediately. Saving an expired or deactivated license restores company access.
            </div>
            <div className="mt-5 grid gap-4 sm:grid-cols-2">
              <div>
                <label className="text-sm font-bold text-slate-700" htmlFor="edit-license-duration">
                  Duration
                </label>
                <input
                  className="form-input mt-2"
                  id="edit-license-duration"
                  min={1}
                  onChange={(event) => setDuration(Number(event.target.value))}
                  required
                  type="number"
                  value={duration}
                />
              </div>
              <div>
                <label className="text-sm font-bold text-slate-700" htmlFor="edit-license-unit">
                  Unit
                </label>
                <select
                  className="form-input mt-2"
                  id="edit-license-unit"
                  onChange={(event) => setUnit(event.target.value as 'months' | 'years')}
                  value={unit}
                >
                  <option value="months">Months</option>
                  <option value="years">Years</option>
                </select>
              </div>
            </div>
            <p className="mt-3 text-xs text-slate-500">
              Maximum: 120 months or 10 years.
            </p>
            {errorMessage ? (
              <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert">
                {errorMessage}
              </p>
            ) : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
            <button className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold" onClick={onClose} type="button">
              Cancel
            </button>
            <button className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60" disabled={mutation.isPending} type="submit">
              {mutation.isPending ? 'Saving…' : isRenewal ? 'Renew and activate' : 'Save tenure'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}
