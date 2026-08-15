import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Building2,
  Edit3,
  MapPin,
  Phone,
  Plus,
  UserRound,
  Users,
  X,
} from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import type {
  PaginatedResponse,
  ResourceResponse,
  Station,
  User,
} from '../types/api'

type StationFormValues = {
  code: string
  station_number: string
  name: string
  registration_number: string
  phone: string
  address: string
  timezone: string
  manager_user_id: string
}

const emptyStation: StationFormValues = {
  code: '',
  station_number: '',
  name: '',
  registration_number: '',
  phone: '',
  address: '',
  timezone: 'Africa/Accra',
  manager_user_id: '',
}

function valuesForStation(station?: Station): StationFormValues {
  if (!station) return emptyStation
  return {
    code: station.code,
    station_number: station.station_number ?? '',
    name: station.name,
    registration_number: station.registration_number ?? '',
    phone: station.phone ?? '',
    address: station.address ?? '',
    timezone: station.timezone,
    manager_user_id: station.manager?.id ?? '',
  }
}

export default function StationsPage() {
  const queryClient = useQueryClient()
  const [editingStation, setEditingStation] = useState<Station | 'new' | null>(
    null,
  )
  const stations = useQuery({
    queryKey: ['stations'],
    queryFn: () =>
      apiFetch<ResourceResponse<Station[]>>('/api/v1/stations'),
    refetchOnMount: 'always',
  })
  const users = useQuery({
    queryKey: ['users', 'station-manager-options'],
    queryFn: () =>
      apiFetch<PaginatedResponse<User>>('/api/v1/users?per_page=100'),
  })
  const stationList = stations.data?.data ?? []
  const activeUsers = stationList.reduce(
    (total, station) =>
      total +
      (station.assigned_users?.filter((user) => user.status === 'active')
        .length ?? 0),
    0,
  )

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-sm font-medium text-blue-600">
            Organization structure
          </p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">Stations</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
            Add stations, assign their manager, maintain station numbers and
            contact details, and see every user tied to each location.
          </p>
        </div>
        <button
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
          onClick={() => setEditingStation('new')}
          type="button"
        >
          <Plus className="h-4 w-4" />
          Add station
        </button>
      </header>

      <section className="mt-7 grid gap-4 sm:grid-cols-3">
        <Summary
          icon={<Building2 className="h-5 w-5" />}
          label="Stations"
          value={String(stationList.length)}
        />
        <Summary
          icon={<UserRound className="h-5 w-5" />}
          label="Station managers"
          value={String(
            stationList.filter((station) => station.manager).length,
          )}
        />
        <Summary
          icon={<Users className="h-5 w-5" />}
          label="Active assignments"
          value={String(activeUsers)}
        />
      </section>

      {stations.isError ? (
        <section className="mt-6 rounded-xl border border-rose-200 bg-white p-6 text-center shadow-sm">
          <p className="text-sm text-rose-700">
            Station records could not be loaded.
          </p>
          <button
            className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"
            onClick={() => stations.refetch()}
            type="button"
          >
            Retry
          </button>
        </section>
      ) : stations.isLoading ? (
        <p className="mt-6 rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
          Loading stations and assigned users…
        </p>
      ) : (
        <section className="mt-6 grid gap-5 xl:grid-cols-2">
          {stationList.map((station) => (
            <article
              className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
              key={station.id}
            >
              <header className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                <div className="flex gap-3">
                  <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                    <Building2 className="h-5 w-5" />
                  </span>
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-bold">{station.name}</h2>
                      <StatusBadge
                        label={station.is_active ? 'Active' : 'Inactive'}
                        tone={station.is_active ? 'success' : 'neutral'}
                      />
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {station.code} ·{' '}
                      {station.station_number ?? 'No station number'}
                    </p>
                  </div>
                </div>
                <button
                  aria-label={`Edit ${station.name}`}
                  className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"
                  onClick={() => setEditingStation(station)}
                  type="button"
                >
                  <Edit3 className="h-4 w-4" />
                </button>
              </header>

              <div className="grid gap-4 border-b border-slate-100 p-5 sm:grid-cols-2">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Station manager
                  </p>
                  <p className="mt-2 text-sm font-semibold">
                    {station.manager?.name ?? 'Not assigned'}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    {station.manager?.email ?? 'Assign through station or user management'}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Station contact
                  </p>
                  <p className="mt-2 flex items-center gap-2 text-sm">
                    <Phone className="h-3.5 w-3.5 text-slate-400" />
                    {station.phone ?? 'No phone number'}
                  </p>
                  <p className="mt-1 flex items-start gap-2 text-xs text-slate-500">
                    <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {station.address ?? 'No address recorded'}
                  </p>
                </div>
              </div>

              <div className="p-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-bold">Assigned users</h3>
                    <p className="mt-1 text-xs text-slate-500">
                      Automatically synchronized from User Management
                    </p>
                  </div>
                  <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">
                    {station.assigned_users_count ?? 0}
                  </span>
                </div>
                <div className="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                  {station.assigned_users?.map((user) => (
                    <div
                      className="flex items-center gap-3 px-4 py-3"
                      key={user.id}
                    >
                      <span className="grid h-8 w-8 place-items-center rounded-full bg-slate-900 text-[11px] font-semibold text-white">
                        {user.name
                          .split(' ')
                          .slice(0, 2)
                          .map((part) => part[0])
                          .join('')}
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                          {user.name}
                        </p>
                        <p className="truncate text-xs text-slate-500">
                          {user.roles.join(', ') || 'Station user'} ·{' '}
                          {user.email}
                        </p>
                      </div>
                      <StatusBadge
                        label={user.status.replaceAll('_', ' ')}
                        tone={user.status === 'active' ? 'success' : 'warning'}
                      />
                    </div>
                  ))}
                  {!station.assigned_users?.length ? (
                    <p className="px-4 py-6 text-center text-sm text-slate-500">
                      No users are assigned to this station yet.
                    </p>
                  ) : null}
                </div>
              </div>
            </article>
          ))}
        </section>
      )}

      {editingStation ? (
        <StationFormModal
          initialStation={
            editingStation === 'new' ? undefined : editingStation
          }
          managers={(users.data?.data ?? []).filter((user) =>
            user.role_assignments.some(
              (assignment) =>
                assignment.role.slug === 'station_manager',
            ),
          )}
          onClose={() => setEditingStation(null)}
          onSaved={async () => {
            setEditingStation(null)
            await Promise.all([
              queryClient.invalidateQueries({ queryKey: ['stations'] }),
              queryClient.invalidateQueries({ queryKey: ['users'] }),
            ])
          }}
        />
      ) : null}
    </div>
  )
}

function Summary({
  icon,
  label,
  value,
}: {
  icon: React.ReactNode
  label: string
  value: string
}) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
            {label}
          </p>
          <p className="mt-2 text-2xl font-bold">{value}</p>
        </div>
        <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
          {icon}
        </span>
      </div>
    </article>
  )
}

function StationFormModal({
  initialStation,
  managers,
  onClose,
  onSaved,
}: {
  initialStation?: Station
  managers: User[]
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [values, setValues] = useState<StationFormValues>(() =>
    valuesForStation(initialStation),
  )
  const save = useMutation({
    mutationFn: () =>
      apiFetch<ResourceResponse<Station>>(
        initialStation
          ? `/api/v1/stations/${initialStation.id}`
          : '/api/v1/stations',
        {
          method: initialStation ? 'PATCH' : 'POST',
          body: JSON.stringify({
            ...values,
            manager_user_id: values.manager_user_id || null,
            registration_number: values.registration_number || null,
            phone: values.phone || null,
            address: values.address || null,
          }),
        },
      ),
    onSuccess: onSaved,
  })
  const error =
    save.error instanceof ApiError
      ? Object.values(save.error.errors ?? {})[0]?.[0] ?? save.error.message
      : save.error instanceof Error
        ? save.error.message
        : null
  const update = (key: keyof StationFormValues, value: string) =>
    setValues((current) => ({ ...current, [key]: value }))
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    save.mutate()
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section
        aria-modal="true"
        className="my-6 w-full max-w-2xl rounded-2xl bg-white shadow-2xl"
        role="dialog"
      >
        <header className="flex items-start justify-between border-b border-slate-200 p-6">
          <div>
            <h2 className="text-lg font-bold">
              {initialStation ? 'Edit station' : 'Add station'}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              Record the station number, manager, registration, and contact
              details.
            </p>
          </div>
          <button
            aria-label="Close station form"
            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"
            onClick={onClose}
            type="button"
          >
            <X className="h-5 w-5" />
          </button>
        </header>
        <form onSubmit={submit}>
          <div className="grid gap-5 p-6 sm:grid-cols-2">
            <Field
              label="Station code"
              onChange={(value) => update('code', value)}
              required
              value={values.code}
            />
            <Field
              label="Station number"
              onChange={(value) => update('station_number', value)}
              placeholder="e.g. STN-0002"
              required
              value={values.station_number}
            />
            <div className="sm:col-span-2">
              <Field
                label="Station name"
                onChange={(value) => update('name', value)}
                required
                value={values.name}
              />
            </div>
            <Field
              label="Registration number"
              onChange={(value) => update('registration_number', value)}
              value={values.registration_number}
            />
            <Field
              label="Phone number"
              onChange={(value) => update('phone', value)}
              value={values.phone}
            />
            <label>
              <span className="mb-2 block text-sm font-semibold">
                Station manager
              </span>
              <select
                className="form-input"
                onChange={(event) =>
                  update('manager_user_id', event.target.value)
                }
                value={values.manager_user_id}
              >
                <option value="">Assign later</option>
                {managers.map((manager) => (
                  <option key={manager.id} value={manager.id}>
                    {manager.name} — {manager.email}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span className="mb-2 block text-sm font-semibold">
                Timezone
              </span>
              <select
                className="form-input"
                onChange={(event) => update('timezone', event.target.value)}
                value={values.timezone}
              >
                <option value="Africa/Accra">Africa/Accra</option>
              </select>
            </label>
            <label className="sm:col-span-2">
              <span className="mb-2 block text-sm font-semibold">Address</span>
              <textarea
                className="form-input"
                onChange={(event) => update('address', event.target.value)}
                rows={3}
                value={values.address}
              />
            </label>
            {error ? (
              <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 sm:col-span-2">
                {error}
              </p>
            ) : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 p-4">
            <button
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold"
              onClick={onClose}
              type="button"
            >
              Cancel
            </button>
            <button
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              disabled={save.isPending}
              type="submit"
            >
              {save.isPending
                ? 'Saving…'
                : initialStation
                  ? 'Save changes'
                  : 'Create station'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function Field({
  label,
  value,
  onChange,
  required = false,
  placeholder,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  required?: boolean
  placeholder?: string
}) {
  return (
    <label>
      <span className="mb-2 block text-sm font-semibold">
        {label}
        {required ? ' *' : ''}
      </span>
      <input
        className="form-input"
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        required={required}
        value={value}
      />
    </label>
  )
}
