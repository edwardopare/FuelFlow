import { zodResolver } from '@hookform/resolvers/zod'
import { Fuel, KeyRound } from 'lucide-react'
import { useForm, type UseFormRegisterReturn } from 'react-hook-form'
import { Navigate, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { LoadingScreen } from '../components/LoadingScreen'
import { CopyrightFooter } from '../components/CopyrightFooter'
import { useChangePassword, useCurrentUser } from '../hooks/useAuth'
import { ApiError } from '../lib/api'

const changePasswordSchema = z
  .object({
    password: z
      .string()
      .min(12, 'Use at least 12 characters.')
      .regex(/[a-z]/, 'Include a lowercase letter.')
      .regex(/[A-Z]/, 'Include an uppercase letter.')
      .regex(/\d/, 'Include a number.')
      .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
    password_confirmation: z.string(),
    terminal_pin: z.string().regex(/^\d{6}$/, 'Use exactly six digits.').optional(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type ChangePasswordForm = z.infer<typeof changePasswordSchema>

export default function ChangePasswordPage() {
  const currentUser = useCurrentUser()
  const changePassword = useChangePassword()
  const navigate = useNavigate()
  const isAttendant =
    currentUser.data?.role_assignments.some(
      (assignment) => assignment.role.slug === 'cashier_attendant',
    ) ?? false
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ChangePasswordForm>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: {
      password: '',
      password_confirmation: '',
      terminal_pin: undefined,
    },
  })

  if (currentUser.isLoading) {
    return <LoadingScreen label="Loading secure setup" />
  }

  if (
    currentUser.error instanceof ApiError &&
    currentUser.error.status === 401
  ) {
    return <Navigate replace to="/login" />
  }

  if (!currentUser.data) {
    return <Navigate replace to="/login" />
  }

  if (!currentUser.data.must_change_password) {
    return <Navigate replace to="/" />
  }

  const onSubmit = handleSubmit(async (values) => {
    if (isAttendant && !values.terminal_pin) {
      setError('terminal_pin', {
        type: 'required',
        message: 'A six-digit terminal PIN is required for this role.',
      })
      return
    }

    await changePassword.mutateAsync(values)
    navigate('/', { replace: true })
  })

  const serverMessage =
    changePassword.error instanceof ApiError
      ? Object.values(changePassword.error.errors ?? {})[0]?.[0] ??
        changePassword.error.message
      : null

  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <main className="grid flex-1 place-items-center px-6 py-12">
        <section className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <div className="flex items-center justify-between">
          <span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
            <KeyRound aria-hidden className="h-5 w-5" />
          </span>
          <div className="flex items-center gap-2 text-sm font-bold text-slate-700">
            <Fuel aria-hidden className="h-4 w-4 text-blue-600" />
            FuelFlow FSMS
          </div>
        </div>
        <h1 className="mt-6 text-2xl font-bold tracking-tight">
          Secure your account
        </h1>
        <p className="mt-2 text-sm leading-6 text-slate-500">
          You confirmed your temporary password when signing in. Set a
          permanent password before accessing station data.
          {isAttendant
            ? ' Your Cashier / Pump Attendant role also requires a terminal PIN.'
            : ''}
        </p>

        <form className="mt-8 space-y-5" onSubmit={onSubmit}>
          <PasswordField
            autoComplete="new-password"
            error={errors.password?.message}
            id="password"
            label="New password"
            registration={register('password')}
          />
          <PasswordField
            autoComplete="new-password"
            error={errors.password_confirmation?.message}
            id="password_confirmation"
            label="Confirm new password"
            registration={register('password_confirmation')}
          />

          {isAttendant ? (
            <div>
              <label
                className="text-sm font-semibold text-slate-700"
                htmlFor="terminal_pin"
              >
                Six-digit terminal PIN
              </label>
              <input
                autoComplete="new-password"
                className="mt-2 w-full rounded-lg border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50"
                id="terminal_pin"
                inputMode="numeric"
                maxLength={6}
                type="password"
                {...register('terminal_pin')}
              />
              {errors.terminal_pin ? (
                <p className="mt-1.5 text-xs text-rose-600">
                  {errors.terminal_pin.message}
                </p>
              ) : null}
            </div>
          ) : null}

          {serverMessage ? (
            <p
              className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"
              role="alert"
            >
              {serverMessage}
            </p>
          ) : null}

          <button
            className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            disabled={changePassword.isPending}
            type="submit"
          >
            {changePassword.isPending ? 'Saving…' : 'Save and continue'}
          </button>
        </form>
        </section>
      </main>
      <CopyrightFooter className="px-6 py-5" />
    </div>
  )
}

type PasswordFieldProps = {
  id: string
  label: string
  autoComplete: string
  error?: string
  registration: UseFormRegisterReturn
}

function PasswordField({
  id,
  label,
  autoComplete,
  error,
  registration,
}: PasswordFieldProps) {
  return (
    <div>
      <label className="text-sm font-semibold text-slate-700" htmlFor={id}>
        {label}
      </label>
      <input
        autoComplete={autoComplete}
        className="mt-2 w-full rounded-lg border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50"
        id={id}
        type="password"
        {...registration}
      />
      {error ? <p className="mt-1.5 text-xs text-rose-600">{error}</p> : null}
    </div>
  )
}
