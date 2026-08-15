import { useQuery } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { Navigate, useOutletContext } from 'react-router-dom'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { StatusBadge } from '../components/StatusBadge'
import { apiFetch } from '../lib/api'
import { formatAccraDateTime } from '../lib/format'
import type { AuditEvent, PaginatedResponse } from '../types/api'

export default function AuditPage() {
  const { user } = useOutletContext<AuthenticatedOutletContext>()
  const isAdministrator = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'administrator',
  )
  const auditEvents = useQuery({
    queryKey: ['audit-events'],
    queryFn: () =>
      apiFetch<PaginatedResponse<AuditEvent>>('/api/v1/audit-events'),
    enabled: isAdministrator,
  })

  if (!isAdministrator) {
    return <Navigate replace to="/" />
  }

  return (
    <div className="mx-auto max-w-[1440px]">
      <header>
        <p className="text-sm font-medium text-blue-600">Audit</p>
        <h1 className="mt-1 text-2xl font-bold tracking-tight">
          Immutable activity trail
        </h1>
        <p className="mt-2 text-sm text-slate-500">
          Administrator-only events across the FuelFlow organization.
        </p>
      </header>

      <section className="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex items-center gap-3 border-b border-slate-200 px-6 py-5">
          <span className="grid h-9 w-9 place-items-center rounded-lg bg-emerald-50 text-emerald-600">
            <ShieldCheck aria-hidden className="h-4 w-4" />
          </span>
          <div>
            <h2 className="font-bold">Recent events</h2>
            <p className="text-xs text-slate-500">
              Append-only records with request correlation
            </p>
          </div>
        </div>

        {auditEvents.isLoading ? (
          <p className="px-6 py-10 text-center text-sm text-slate-500">
            Loading events…
          </p>
        ) : auditEvents.error ? (
          <p
            className="m-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
            role="alert"
          >
            Audit events could not be loaded for this role.
          </p>
        ) : auditEvents.data?.data.length === 0 ? (
          <p className="px-6 py-10 text-center text-sm text-slate-500">
            No audit events are visible in this scope yet.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {auditEvents.data?.data.map((event) => (
              <li
                className="grid gap-4 px-6 py-5 md:grid-cols-[minmax(0,1fr)_auto]"
                key={event.id}
              >
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold text-slate-900">
                      {event.action.replaceAll('.', ' ')}
                    </p>
                    <StatusBadge label="Audited" tone="success" />
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    {event.actor?.name ?? 'System'} ·{' '}
                    {event.subject_type
                      ? `${event.subject_type.split('\\').at(-1)} ${event.subject_id ?? ''}`
                      : 'Authentication event'}
                  </p>
                  {event.reason ? (
                    <p className="mt-2 text-sm text-slate-600">
                      Reason: {event.reason}
                    </p>
                  ) : null}
                </div>
                <div className="text-left md:text-right">
                  <p className="text-sm font-medium text-slate-700">
                    {formatAccraDateTime(event.created_at)}
                  </p>
                  <p className="mt-1 font-mono text-[11px] text-slate-400">
                    {event.request_id ?? 'No request ID'}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
