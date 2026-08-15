import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Mail } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { z } from 'zod'
import { AuthLayout } from '../components/AuthLayout'
import { useForgotPassword } from '../hooks/useAuth'
import { ApiError } from '../lib/api'

const forgotPasswordSchema = z.object({
  email: z.email('Enter a valid email address.'),
})

type ForgotPasswordForm = z.infer<typeof forgotPasswordSchema>

export default function ForgotPasswordPage() {
  const forgotPassword = useForgotPassword()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ForgotPasswordForm>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  })

  const onSubmit = handleSubmit(async ({ email }) => {
    try {
      await forgotPassword.mutateAsync(email)
    } catch {
      // The mutation error is rendered below the form.
    }
  })

  const serverMessage =
    forgotPassword.error instanceof ApiError
      ? forgotPassword.error.errors?.email?.[0] ??
        forgotPassword.error.message
      : null

  return (
    <AuthLayout description="Secure account recovery for FuelFlow users.">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-8">
        <div className="text-center">
          <span className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
            <Mail aria-hidden className="h-5 w-5" />
          </span>
          <h2 className="mt-4 text-2xl font-bold">Forgot your password?</h2>
          <p className="mt-2 text-sm leading-6 text-slate-500">
            Enter your login email and we will send you a secure password reset
            link.
          </p>
        </div>

        {forgotPassword.isSuccess ? (
          <div className="mt-7">
            <p
              className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-700"
              role="status"
            >
              {forgotPassword.data.message}
            </p>
            <Link
              className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700"
              to="/login"
            >
              <ArrowLeft aria-hidden className="h-4 w-4" />
              Return to sign in
            </Link>
          </div>
        ) : (
          <form className="mt-7 space-y-5" onSubmit={onSubmit}>
            <div>
              <label
                className="text-sm font-semibold text-slate-700"
                htmlFor="recovery-email"
              >
                Email address
              </label>
              <input
                autoComplete="email"
                className="form-input mt-2"
                id="recovery-email"
                type="email"
                {...register('email')}
              />
              {errors.email ? (
                <p className="mt-1.5 text-xs text-rose-600">
                  {errors.email.message}
                </p>
              ) : null}
            </div>

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
              disabled={forgotPassword.isPending}
              type="submit"
            >
              {forgotPassword.isPending
                ? 'Sending reset link…'
                : 'Send reset link'}
            </button>
            <Link
              className="inline-flex w-full items-center justify-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-700"
              to="/login"
            >
              <ArrowLeft aria-hidden className="h-4 w-4" />
              Back to sign in
            </Link>
          </form>
        )}
      </section>
    </AuthLayout>
  )
}
