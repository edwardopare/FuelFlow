import { zodResolver } from '@hookform/resolvers/zod'
import {
  Building2,
  KeyRound,
  Mail,
  MapPin,
  Phone,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useForm, type UseFormRegisterReturn } from 'react-hook-form'
import { useOutletContext } from 'react-router-dom'
import { z } from 'zod'
import type { AuthenticatedOutletContext } from '../components/ProtectedLayout'
import { StatusBadge } from '../components/StatusBadge'
import { useChangePassword } from '../hooks/useAuth'
import { ApiError } from '../lib/api'

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Current password is required.'),
    password: z
      .string()
      .min(12, 'Use at least 12 characters.')
      .regex(/[a-z]/, 'Include a lowercase letter.')
      .regex(/[A-Z]/, 'Include an uppercase letter.')
      .regex(/\d/, 'Include a number.')
      .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
    password_confirmation: z.string(),
    terminal_pin: z
      .string()
      .regex(/^\d{6}$/, 'Use exactly six digits.')
      .optional(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type PasswordForm = z.infer<typeof passwordSchema>

export default function ProfilePage() {
  const { user } = useOutletContext<AuthenticatedOutletContext>()
  const changePassword = useChangePassword()
  const [passwordChanged, setPasswordChanged] = useState(false)
  const isAttendant = user.role_assignments.some(
    (assignment) => assignment.role.slug === 'cashier_attendant',
  )
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<PasswordForm>({
    resolver: zodResolver(passwordSchema),
    defaultValues: {
      current_password: '',
      password: '',
      password_confirmation: '',
      terminal_pin: undefined,
    },
  })
  const initials = user.name
    .split(' ')
    .slice(0, 2)
    .map((part) => part[0])
    .join('')

  const onSubmit = handleSubmit(async (values) => {
    setPasswordChanged(false)
    changePassword.reset()

    if (isAttendant && !values.terminal_pin) {
      setError('terminal_pin', {
        type: 'required',
        message: 'A six-digit terminal PIN is required for this role.',
      })
      return
    }

    try {
      await changePassword.mutateAsync(values)
      reset()
      setPasswordChanged(true)
    } catch {
      // The mutation error is rendered below the form.
    }
  })

  const serverMessage =
    changePassword.error instanceof ApiError
      ? Object.values(changePassword.error.errors ?? {})[0]?.[0] ??
        changePassword.error.message
      : null

  return (
    <div className="mx-auto max-w-6xl">
      <header>
        <p className="text-sm font-medium text-blue-600">My profile</p>
        <h1 className="mt-1 text-2xl font-bold tracking-tight">
          Account and security
        </h1>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
          Review your FuelFlow identity, assigned access, and password security.
        </p>
      </header>

      <div className="mt-7 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(380px,0.8fr)]">
        <div className="space-y-6">
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-200 bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-7 text-white">
              <div className="flex items-center gap-4">
                <span className="grid h-16 w-16 place-items-center rounded-2xl bg-white/15 text-xl font-bold ring-1 ring-white/25">
                  {initials}
                </span>
                <div>
                  <h2 className="text-xl font-bold">{user.name}</h2>
                  <p className="mt-1 text-sm text-blue-100">{user.email}</p>
                  <div className="mt-3">
                    <StatusBadge
                      label={user.status.replaceAll('_', ' ')}
                      tone="success"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div className="grid gap-5 p-6 sm:grid-cols-2">
              <ProfileDetail
                icon={<UserRound aria-hidden className="h-4 w-4" />}
                label="Full name"
                value={user.name}
              />
              <ProfileDetail
                icon={<Mail aria-hidden className="h-4 w-4" />}
                label="Email address"
                value={user.email}
              />
              <ProfileDetail
                icon={<Phone aria-hidden className="h-4 w-4" />}
                label="Contact number"
                value={user.phone || 'Not provided'}
              />
              <ProfileDetail
                icon={<Building2 aria-hidden className="h-4 w-4" />}
                label="Organization"
                value={user.organization.name}
              />
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                <ShieldCheck aria-hidden className="h-5 w-5" />
              </span>
              <div>
                <h2 className="font-bold">Roles and access</h2>
                <p className="text-xs text-slate-500">
                  Access assigned by your Administrator
                </p>
              </div>
            </div>

            <div className="mt-5">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                FRD roles
              </h3>
              <div className="mt-2 flex flex-wrap gap-2">
                {user.role_assignments.map((assignment) => (
                  <StatusBadge
                    key={assignment.id}
                    label={assignment.role.name}
                    tone="info"
                  />
                ))}
              </div>
            </div>

            <div className="mt-6">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Station assignments
              </h3>
              <div className="mt-3 space-y-2">
                {user.stations.map((station, index) => (
                  <div
                    className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3"
                    key={station.id}
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <MapPin
                        aria-hidden
                        className="h-4 w-4 shrink-0 text-blue-600"
                      />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">
                          {station.name}
                        </p>
                        <p className="text-xs text-slate-500">{station.code}</p>
                      </div>
                    </div>
                    {index === 0 ? (
                      <StatusBadge label="Primary" tone="neutral" />
                    ) : null}
                  </div>
                ))}
              </div>
            </div>

            <div className="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
              System currency: <strong>GHS</strong>
              <span aria-hidden className="mx-2 text-slate-300">
                ·
              </span>
              Timezone: <strong>{user.organization.timezone}</strong>
            </div>
          </section>
        </div>

        <section className="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="flex items-center gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-amber-50 text-amber-600">
              <KeyRound aria-hidden className="h-5 w-5" />
            </span>
            <div>
              <h2 className="font-bold">Change password</h2>
              <p className="text-xs text-slate-500">
                Confirm your current password before saving
              </p>
            </div>
          </div>

          <form className="mt-6 space-y-5" onSubmit={onSubmit}>
            <PasswordField
              autoComplete="current-password"
              error={errors.current_password?.message}
              id="profile-current-password"
              label="Current password"
              registration={register('current_password')}
            />
            <PasswordField
              autoComplete="new-password"
              error={errors.password?.message}
              id="profile-new-password"
              label="New password"
              registration={register('password')}
            />
            <PasswordField
              autoComplete="new-password"
              error={errors.password_confirmation?.message}
              id="profile-password-confirmation"
              label="Confirm new password"
              registration={register('password_confirmation')}
            />

            {isAttendant ? (
              <PasswordField
                autoComplete="new-password"
                error={errors.terminal_pin?.message}
                id="profile-terminal-pin"
                inputMode="numeric"
                label="Six-digit terminal PIN"
                maxLength={6}
                registration={register('terminal_pin')}
              />
            ) : null}

            <p className="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">
              Use at least 12 characters with uppercase and lowercase letters,
              a number, and a symbol.
            </p>

            {serverMessage ? (
              <p
                className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"
                role="alert"
              >
                {serverMessage}
              </p>
            ) : null}

            {passwordChanged ? (
              <p
                className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700"
                role="status"
              >
                Your password has been changed successfully.
              </p>
            ) : null}

            <button
              className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
              disabled={changePassword.isPending}
              type="submit"
            >
              {changePassword.isPending ? 'Changing password…' : 'Change password'}
            </button>
          </form>
        </section>
      </div>
    </div>
  )
}

function ProfileDetail({
  icon,
  label,
  value,
}: {
  icon: ReactNode
  label: string
  value: string
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="mt-0.5 text-slate-400">{icon}</span>
      <div>
        <p className="text-xs font-medium text-slate-500">{label}</p>
        <p className="mt-1 text-sm font-semibold text-slate-900">{value}</p>
      </div>
    </div>
  )
}

function PasswordField({
  id,
  label,
  autoComplete,
  error,
  registration,
  inputMode,
  maxLength,
}: {
  id: string
  label: string
  autoComplete: string
  error?: string
  registration: UseFormRegisterReturn
  inputMode?: 'numeric'
  maxLength?: number
}) {
  return (
    <div>
      <label className="text-sm font-semibold text-slate-700" htmlFor={id}>
        {label}
      </label>
      <input
        autoComplete={autoComplete}
        className="form-input mt-2"
        id={id}
        inputMode={inputMode}
        maxLength={maxLength}
        type="password"
        {...registration}
      />
      {error ? <p className="mt-1.5 text-xs text-rose-600">{error}</p> : null}
    </div>
  )
}
