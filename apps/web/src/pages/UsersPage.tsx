import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Edit3,
  KeyRound,
  Search,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserMinus,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import { useDeferredValue, useState, type ReactNode } from 'react'
import { useOutletContext } from 'react-router-dom'
import { StatusBadge } from '../components/StatusBadge'
import { UserFormModal } from '../components/UserFormModal'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { ApiError, apiFetch } from '../lib/api'
import { formatAccraDateTime } from '../lib/format'
import type {
  PaginatedResponse,
  ResourceResponse,
  Role,
  Station,
  User,
} from '../types/api'

function statusTone(status: User['status']) {
  if (status === 'active') return 'success' as const
  if (status === 'pending_first_login') return 'warning' as const
  if (status === 'locked') return 'danger' as const
  return 'neutral' as const
}

type UserAction = 'deactivate' | 'delete'

type PendingAction = {
  action: UserAction
  user: User
}

export default function UsersPage() {
  const { user: currentUser } =
    useOutletContext<AuthenticatedOutletContext>()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [formUser, setFormUser] = useState<User | 'new' | null>(null)
  const [pendingAction, setPendingAction] =
    useState<PendingAction | null>(null)
  const deferredSearch = useDeferredValue(search.trim())
  const isAdministrator = currentUser.role_assignments.some(
    (assignment) => assignment.role.slug === 'administrator',
  )
  const isStationManager =
    !isAdministrator &&
    currentUser.role_assignments.some(
      (assignment) => assignment.role.slug === 'station_manager',
    )
  const query = new URLSearchParams()

  if (deferredSearch) query.set('search', deferredSearch)
  if (status) query.set('status', status)

  const users = useQuery({
    queryKey: ['users', currentUser.id, deferredSearch, status],
    queryFn: () =>
      apiFetch<PaginatedResponse<User>>(
        `/api/v1/users${query.size ? `?${query.toString()}` : ''}`,
      ),
  })
  const roles = useQuery({
    queryKey: ['roles'],
    queryFn: () => apiFetch<ResourceResponse<Role[]>>('/api/v1/roles'),
    enabled: isAdministrator,
  })
  const stations = useQuery({
    queryKey: ['stations'],
    queryFn: () =>
      apiFetch<ResourceResponse<Station[]>>('/api/v1/stations'),
    enabled: isAdministrator,
  })
  const activateUser = useMutation({
    mutationFn: (user: User) =>
      apiFetch<ResourceResponse<User>>(`/api/v1/users/${user.id}/activate`, {
        method: 'POST',
      }),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: ['users'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['audit-events'] }),
      ]),
  })

  const closeForm = () => setFormUser(null)
  const roleOptions = roles.data?.data ?? []
  const stationOptions = stations.data?.data ?? []
  const referenceDataLoading = roles.isLoading || stations.isLoading
  const referenceDataError = roles.error ?? stations.error

  return (
    <div className="mx-auto max-w-[1440px]">
      <header className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-sm font-medium text-blue-600">
            {isStationManager ? 'Users & Shifts' : 'User Management'}
          </p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">
            {isStationManager
              ? 'Pump attendants assigned to your station'
              : 'Users, roles, and station access'}
          </h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
            {isStationManager
              ? 'View the Cashier / Pump Attendants available for shift assignment at your authorized station.'
              : 'Create staff profiles, assign an FRD role and permitted stations, reset credentials, and control account access.'}
          </p>
        </div>
        {isAdministrator ? (
          <button
            className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
            onClick={() => setFormUser('new')}
            type="button"
          >
            <UserPlus aria-hidden className="h-4 w-4" />
            Add user
          </button>
        ) : null}
      </header>

      <section
        aria-label="User account summary"
        className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
      >
        <SummaryCard
          icon={<Users aria-hidden className="h-5 w-5" />}
          label={isStationManager ? 'Total attendants' : 'Total accounts'}
          value={String(users.data?.meta.total ?? users.data?.data.length ?? 0)}
        />
        <SummaryCard
          icon={<UserCheck aria-hidden className="h-5 w-5" />}
          label="Active"
          tone="green"
          value={String(
            users.data?.data.filter((user) => user.status === 'active').length ??
              0,
          )}
        />
        <SummaryCard
          icon={<KeyRound aria-hidden className="h-5 w-5" />}
          label="First login pending"
          tone="amber"
          value={String(
            users.data?.data.filter(
              (user) => user.status === 'pending_first_login',
            ).length ?? 0,
          )}
        />
        <SummaryCard
          icon={<ShieldCheck aria-hidden className="h-5 w-5" />}
          label={isStationManager ? 'Assigned stations' : 'FRD roles'}
          value={String(
            isStationManager
              ? currentUser.stations.length
              : (roles.data?.data.length ?? 6),
          )}
        />
      </section>

      <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-4 border-b border-slate-200 px-6 py-5 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-3">
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-blue-50 text-blue-600">
              <Users aria-hidden className="h-4 w-4" />
            </span>
            <div>
              <h2 className="font-bold">
                {isStationManager
                  ? 'Assigned pump attendants'
                  : 'Authorized users'}
              </h2>
              <p className="text-xs text-slate-500">
                {isStationManager
                  ? 'Only attendants assigned to your station are shown'
                  : 'Results respect organization and station scope'}
              </p>
            </div>
          </div>
          <div className="flex flex-col gap-3 sm:flex-row">
            <label className="relative">
              <span className="sr-only">Search users</span>
              <Search
                aria-hidden
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              />
              <input
                className="w-full rounded-lg border border-slate-200 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50 sm:w-72"
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search name or email"
                type="search"
                value={search}
              />
            </label>
            <label>
              <span className="sr-only">Filter by status</span>
              <select
                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50 sm:w-48"
                onChange={(event) => setStatus(event.target.value)}
                value={status}
              >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="pending_first_login">
                  Pending first login
                </option>
                <option value="inactive">Inactive</option>
                <option value="locked">Locked</option>
              </select>
            </label>
          </div>
        </div>

        {users.isLoading ? (
          <p className="px-6 py-10 text-center text-sm text-slate-500">
            Loading users…
          </p>
        ) : users.error ? (
          <div
            className="m-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-center text-sm text-rose-700"
            role="alert"
          >
            <p>Users could not be loaded. Your records have not been removed.</p>
            <button
              className="mt-3 rounded-lg border border-rose-200 bg-white px-3 py-2 font-semibold text-rose-700"
              onClick={() => users.refetch()}
              type="button"
            >
              Retry loading users
            </button>
          </div>
        ) : users.data?.data.length === 0 ? (
          <div className="px-6 py-16 text-center">
            <Users
              aria-hidden
              className="mx-auto h-8 w-8 text-slate-300"
            />
            <h3 className="mt-3 font-bold">
              {isStationManager
                ? 'No pump attendants match these filters'
                : 'No users match these filters'}
            </h3>
            <p className="mt-1 text-sm text-slate-500">
              {isStationManager
                ? 'Clear the filters or ask the Administrator to assign an attendant to this station.'
                : 'Clear the filters or create a new account.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1050px] text-left text-sm">
              <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-6 py-3" scope="col">
                    User
                  </th>
                  <th className="px-6 py-3" scope="col">
                    FRD role
                  </th>
                  <th className="px-6 py-3" scope="col">
                    Station assignment
                  </th>
                  <th className="px-6 py-3" scope="col">
                    Status
                  </th>
                  <th className="px-6 py-3" scope="col">
                    Last authenticated
                  </th>
                  {isAdministrator ? (
                    <th className="px-6 py-3 text-right" scope="col">
                      Actions
                    </th>
                  ) : null}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.data?.data.map((user) => (
                  <tr className="hover:bg-slate-50/70" key={user.id}>
                    <th className="px-6 py-4" scope="row">
                      <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                          {user.name
                            .split(' ')
                            .slice(0, 2)
                            .map((part) => part[0])
                            .join('')}
                        </span>
                        <div>
                          <p className="font-semibold text-slate-900">
                            {user.name}
                          </p>
                          <p className="mt-1 font-normal text-slate-500">
                            {user.email}
                          </p>
                        </div>
                      </div>
                    </th>
                    <td className="px-6 py-4 text-slate-600">
                      {user.role_assignments
                        .map((assignment) => assignment.role.name)
                        .filter(
                          (name, index, names) =>
                            names.indexOf(name) === index,
                        )
                        .join(', ')}
                    </td>
                    <td className="px-6 py-4 text-slate-600">
                      {user.stations
                        .map((station) => station.name)
                        .join(', ')}
                    </td>
                    <td className="px-6 py-4">
                      <StatusBadge
                        label={user.status.replaceAll('_', ' ')}
                        tone={statusTone(user.status)}
                      />
                    </td>
                    <td className="px-6 py-4 text-slate-500">
                      {user.last_authenticated_at
                        ? formatAccraDateTime(user.last_authenticated_at)
                        : 'Never'}
                    </td>
                    {isAdministrator ? (
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            aria-label={`Edit ${user.name}`}
                            className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"
                            onClick={() => setFormUser(user)}
                            title="Edit user and reset password"
                            type="button"
                          >
                            <Edit3 aria-hidden className="h-4 w-4" />
                          </button>
                          {user.status === 'inactive' ? (
                            <button
                              aria-label={`Activate ${user.name}`}
                              className="rounded-lg p-2 text-slate-500 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-40"
                              disabled={activateUser.isPending}
                              onClick={() => activateUser.mutate(user)}
                              title="Activate user"
                              type="button"
                            >
                              <UserCheck aria-hidden className="h-4 w-4" />
                            </button>
                          ) : (
                            <button
                              aria-label={`Deactivate ${user.name}`}
                              className="rounded-lg p-2 text-slate-500 hover:bg-amber-50 hover:text-amber-700 disabled:opacity-40"
                              disabled={user.id === currentUser.id}
                              onClick={() =>
                                setPendingAction({
                                  action: 'deactivate',
                                  user,
                                })
                              }
                              title="Deactivate user"
                              type="button"
                            >
                              <UserMinus aria-hidden className="h-4 w-4" />
                            </button>
                          )}
                          <button
                            aria-label={`Delete ${user.name}`}
                            className="rounded-lg p-2 text-slate-500 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-40"
                            disabled={user.id === currentUser.id}
                            onClick={() =>
                              setPendingAction({ action: 'delete', user })
                            }
                            title="Delete eligible user"
                            type="button"
                          >
                            <Trash2 aria-hidden className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    ) : null}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {formUser !== null ? (
        referenceDataLoading ? (
          <UserFormStateDialog
            message="Loading FRD roles and station assignments…"
            onClose={closeForm}
            title={formUser === 'new' ? 'Add a new user' : 'Edit user access'}
          />
        ) : referenceDataError ? (
          <UserFormStateDialog
            actionLabel="Retry"
            message="The user form could not load its role or station options. Retry without losing your place."
            onAction={() => {
              void roles.refetch()
              void stations.refetch()
            }}
            onClose={closeForm}
            title="User form needs reference data"
          />
        ) : (
          <UserFormModal
            initialUser={formUser === 'new' ? undefined : formUser}
            key={formUser === 'new' ? 'new' : formUser.id}
            onClose={closeForm}
            onSaved={closeForm}
            roles={roleOptions}
            stations={stationOptions}
          />
        )
      ) : null}

      {pendingAction ? (
        <UserActionDialog
          action={pendingAction.action}
          onClose={() => setPendingAction(null)}
          onCompleted={async () => {
            setPendingAction(null)
            await Promise.all([
              queryClient.invalidateQueries({ queryKey: ['users'] }),
              queryClient.invalidateQueries({ queryKey: ['stations'] }),
              queryClient.invalidateQueries({ queryKey: ['audit-events'] }),
            ])
          }}
          user={pendingAction.user}
        />
      ) : null}

      {activateUser.error instanceof ApiError ? (
        <p
          className="fixed bottom-5 right-5 rounded-lg border border-rose-200 bg-white px-4 py-3 text-sm text-rose-700 shadow-lg"
          role="alert"
        >
          {activateUser.error.message}
        </p>
      ) : null}
    </div>
  )
}

function UserFormStateDialog({
  title,
  message,
  actionLabel,
  onAction,
  onClose,
}: {
  title: string
  message: string
  actionLabel?: string
  onAction?: () => void
  onClose: () => void
}) {
  return (
    <div
      aria-modal="true"
      className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4 backdrop-blur-sm"
      role="dialog"
    >
      <section className="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl">
        <span className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
          <UserPlus aria-hidden className="h-5 w-5" />
        </span>
        <h2 className="mt-4 text-lg font-bold">{title}</h2>
        <p className="mt-2 text-sm leading-6 text-slate-500">{message}</p>
        <div className="mt-5 flex justify-center gap-3">
          <button
            className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold"
            onClick={onClose}
            type="button"
          >
            Close
          </button>
          {actionLabel && onAction ? (
            <button
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"
              onClick={onAction}
              type="button"
            >
              {actionLabel}
            </button>
          ) : null}
        </div>
      </section>
    </div>
  )
}

type SummaryCardProps = {
  icon: ReactNode
  label: string
  value: string
  tone?: 'blue' | 'green' | 'amber'
}

const summaryTone = {
  blue: 'bg-blue-50 text-blue-600',
  green: 'bg-emerald-50 text-emerald-600',
  amber: 'bg-amber-50 text-amber-600',
}

function SummaryCard({
  icon,
  label,
  value,
  tone = 'blue',
}: SummaryCardProps) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
            {label}
          </p>
          <p className="mt-2 text-2xl font-bold">{value}</p>
        </div>
        <span
          className={`grid h-10 w-10 place-items-center rounded-xl ${summaryTone[tone]}`}
        >
          {icon}
        </span>
      </div>
    </article>
  )
}

type UserActionDialogProps = {
  action: UserAction
  user: User
  onClose: () => void
  onCompleted: () => Promise<void>
}

function UserActionDialog({
  action,
  user,
  onClose,
  onCompleted,
}: UserActionDialogProps) {
  const [reason, setReason] = useState('')
  const actionUser = useMutation({
    mutationFn: () =>
      apiFetch<ResourceResponse<User> | undefined>(
        `/api/v1/users/${user.id}${action === 'deactivate' ? '/deactivate' : ''}`,
        {
          method: action === 'deactivate' ? 'POST' : 'DELETE',
          body: JSON.stringify({ reason }),
        },
      ),
    onSuccess: onCompleted,
  })
  const title =
    action === 'deactivate' ? `Deactivate ${user.name}?` : `Delete ${user.name}?`
  const description =
    action === 'deactivate'
      ? 'The user will be signed out and cannot access FuelFlow until reactivated.'
      : 'Deletion is allowed only when the account has no business activity. Otherwise, deactivate it to preserve attribution.'

  return (
    <div
      aria-labelledby="user-action-title"
      aria-modal="true"
      className="fixed inset-0 z-[60] grid place-items-center bg-slate-950/50 p-4 backdrop-blur-sm"
      role="dialog"
    >
      <section className="w-full max-w-md rounded-2xl bg-white shadow-2xl">
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <h2 className="text-lg font-bold" id="user-action-title">
              {title}
            </h2>
            <p className="mt-2 text-sm leading-6 text-slate-500">
              {description}
            </p>
          </div>
          <button
            aria-label="Close action dialog"
            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden className="h-5 w-5" />
          </button>
        </header>
        <div className="p-6">
          <label
            className="text-sm font-semibold text-slate-700"
            htmlFor="action-reason"
          >
            Reason
          </label>
          <textarea
            className="form-input mt-2 min-h-28 resize-y"
            id="action-reason"
            onChange={(event) => setReason(event.target.value)}
            placeholder="Enter at least 10 characters for the audit trail"
            value={reason}
          />
          {actionUser.error instanceof ApiError ? (
            <p className="mt-3 text-sm text-rose-600" role="alert">
              {actionUser.error.message}
            </p>
          ) : null}
          <footer className="mt-6 flex justify-end gap-3">
            <button
              className="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50"
              onClick={onClose}
              type="button"
            >
              Cancel
            </button>
            <button
              className="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
              disabled={reason.trim().length < 10 || actionUser.isPending}
              onClick={() => actionUser.mutate()}
              type="button"
            >
              {actionUser.isPending
                ? 'Saving…'
                : action === 'deactivate'
                  ? 'Deactivate user'
                  : 'Delete user'}
            </button>
          </footer>
        </div>
      </section>
    </div>
  )
}
