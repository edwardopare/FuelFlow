import { zodResolver } from '@hookform/resolvers/zod'
import { LockKeyhole, ShieldCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'
import {
  Link,
  Navigate,
  useLocation,
  useNavigate,
} from 'react-router-dom'
import { z } from 'zod'
import { AuthLayout } from '../components/AuthLayout'
import { PasswordInput } from '../components/PasswordInput'
import { useCurrentUser, useLogin } from '../hooks/useAuth'
import { ApiError } from '../lib/api'

const loginSchema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean(),
})

type LoginForm = z.infer<typeof loginSchema>

export default function LoginPage() {
  const currentUser = useCurrentUser()
  const login = useLogin()
  const navigate = useNavigate()
  const location = useLocation()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginForm>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: '',
      password: '',
      remember: false,
    },
  })

  if (currentUser.data) {
    return <Navigate replace to="/" />
  }

  const onSubmit = handleSubmit(async (values) => {
    try {
      const user = await login.mutateAsync(values)
      const from =
        (location.state as { from?: { pathname?: string } } | null)?.from
          ?.pathname ?? '/'
      navigate(user.must_change_password ? '/change-password' : from, {
        replace: true,
      })
    } catch {
      // The mutation error is rendered below the form.
    }
  })

  const serverMessage =
    login.error instanceof ApiError
      ? login.error.errors?.email?.[0] ?? login.error.message
      : null

  return (
    <AuthLayout description="Secure access to station and Head Office operations.">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60">
        <div className="text-center">
          <span className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
            <LockKeyhole aria-hidden className="h-5 w-5" />
          </span>
          <h2 className="mt-4 text-2xl font-bold tracking-tight">
            Welcome back
          </h2>
          <p className="mt-2 text-sm text-slate-500">
            Sign in with the credentials assigned by your Administrator.
          </p>
        </div>

        <form className="mt-6 space-y-4" onSubmit={onSubmit}>
          <div>
            <label
              className="text-sm font-semibold text-slate-700"
              htmlFor="email"
            >
              Email address
            </label>
            <input
              autoComplete="username"
              className="form-input mt-2"
              id="email"
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
            <div className="flex items-center justify-between gap-3">
              <label
                className="text-sm font-semibold text-slate-700"
                htmlFor="password"
              >
                Password
              </label>
              <Link
                className="text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline"
                to="/forgot-password"
              >
                Forgot password?
              </Link>
            </div>
            <PasswordInput
              autoComplete="current-password"
              id="password"
              registration={register('password')}
            />
            {errors.password ? (
              <p className="mt-1.5 text-xs text-rose-600">
                {errors.password.message}
              </p>
            ) : null}
          </div>

          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              className="h-4 w-4 rounded border-slate-300 text-blue-600"
              type="checkbox"
              {...register('remember')}
            />
            Keep me signed in on this device
          </label>

          {serverMessage ? (
            <p
              className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"
              role="alert"
            >
              {serverMessage}
            </p>
          ) : null}

          <button
            className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            disabled={login.isPending}
            type="submit"
          >
            {login.isPending ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
        <div className="mt-5 border-t border-slate-200 pt-4 text-center">
          <Link
            className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-600"
            to="/vendor/login"
          >
            <ShieldCheck aria-hidden className="h-4 w-4" />
            Vendor Super User portal
          </Link>
        </div>
      </section>
    </AuthLayout>
  )
}
