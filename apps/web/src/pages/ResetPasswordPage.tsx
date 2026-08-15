import { zodResolver } from '@hookform/resolvers/zod'
import { CheckCircle2, KeyRound } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { z } from 'zod'
import { AuthLayout } from '../components/AuthLayout'
import { PasswordInput } from '../components/PasswordInput'
import { useResetPassword } from '../hooks/useAuth'
import { ApiError } from '../lib/api'

const resetPasswordSchema = z
  .object({
    email: z.email('Enter a valid email address.'),
    password: z
      .string()
      .min(12, 'Use at least 12 characters.')
      .regex(/[a-z]/, 'Include a lowercase letter.')
      .regex(/[A-Z]/, 'Include an uppercase letter.')
      .regex(/\d/, 'Include a number.')
      .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type ResetPasswordForm = z.infer<typeof resetPasswordSchema>

export default function ResetPasswordPage() {
  const { token = '' } = useParams()
  const [searchParams] = useSearchParams()
  const resetPassword = useResetPassword()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ResetPasswordForm>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: {
      email: searchParams.get('email') ?? '',
      password: '',
      password_confirmation: '',
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    try {
      await resetPassword.mutateAsync({ token, ...values })
    } catch {
      // The mutation error is rendered below the form.
    }
  })

  const serverMessage =
    resetPassword.error instanceof ApiError
      ? Object.values(resetPassword.error.errors ?? {})[0]?.[0] ??
        resetPassword.error.message
      : token
        ? null
        : 'This password reset link is incomplete.'

  return (
    <AuthLayout description="Choose a new secure password for your account.">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-8">
        {resetPassword.isSuccess ? (
          <div className="text-center">
            <span className="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
              <CheckCircle2 aria-hidden className="h-6 w-6" />
            </span>
            <h2 className="mt-4 text-2xl font-bold">Password reset complete</h2>
            <p className="mt-2 text-sm leading-6 text-slate-500">
              {resetPassword.data.message}
            </p>
            <Link
              className="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700"
              to="/login"
            >
              Sign in with new password
            </Link>
          </div>
        ) : (
          <>
            <div className="text-center">
              <span className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
                <KeyRound aria-hidden className="h-5 w-5" />
              </span>
              <h2 className="mt-4 text-2xl font-bold">Reset password</h2>
              <p className="mt-2 text-sm text-slate-500">
                Enter and confirm your new account password.
              </p>
            </div>

            <form className="mt-7 space-y-5" onSubmit={onSubmit}>
              <div>
                <label
                  className="text-sm font-semibold text-slate-700"
                  htmlFor="reset-email"
                >
                  Email address
                </label>
                <input
                  autoComplete="email"
                  className="form-input mt-2"
                  id="reset-email"
                  type="email"
                  {...register('email')}
                />
                {errors.email ? (
                  <p className="mt-1.5 text-xs text-rose-600">
                    {errors.email.message}
                  </p>
                ) : null}
              </div>

              <div>
                <label
                  className="text-sm font-semibold text-slate-700"
                  htmlFor="reset-password"
                >
                  New password
                </label>
                <PasswordInput
                  autoComplete="new-password"
                  id="reset-password"
                  registration={register('password')}
                  visibilityLabel="new password"
                />
                {errors.password ? (
                  <p className="mt-1.5 text-xs text-rose-600">
                    {errors.password.message}
                  </p>
                ) : null}
              </div>

              <div>
                <label
                  className="text-sm font-semibold text-slate-700"
                  htmlFor="reset-password-confirmation"
                >
                  Confirm new password
                </label>
                <PasswordInput
                  autoComplete="new-password"
                  id="reset-password-confirmation"
                  registration={register('password_confirmation')}
                  visibilityLabel="password confirmation"
                />
                {errors.password_confirmation ? (
                  <p className="mt-1.5 text-xs text-rose-600">
                    {errors.password_confirmation.message}
                  </p>
                ) : null}
              </div>

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

              <button
                className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                disabled={!token || resetPassword.isPending}
                type="submit"
              >
                {resetPassword.isPending
                  ? 'Resetting password…'
                  : 'Reset password'}
              </button>
            </form>
          </>
        )}
      </section>
    </AuthLayout>
  )
}
