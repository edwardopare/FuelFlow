import { zodResolver } from '@hookform/resolvers/zod'
import { Building2, ShieldCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { AuthLayout } from '../components/AuthLayout'
import { PasswordInput } from '../components/PasswordInput'
import { useCurrentVendorUser, useVendorLogin } from '../hooks/useVendorAuth'
import { ApiError } from '../lib/api'

const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean(),
})

type VendorLoginForm = z.infer<typeof schema>

export default function VendorLoginPage() {
  const currentUser = useCurrentVendorUser()
  const login = useVendorLogin()
  const navigate = useNavigate()
  const location = useLocation()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<VendorLoginForm>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', remember: false },
  })

  if (currentUser.data) {
    return <Navigate replace to="/vendor" />
  }

  const onSubmit = handleSubmit(async (values) => {
    try {
      const user = await login.mutateAsync(values)
      const from =
        (location.state as { from?: { pathname?: string } } | null)?.from
          ?.pathname ?? '/vendor'
      navigate(
        user.must_change_password ? '/vendor/change-password' : from,
        { replace: true },
      )
    } catch {
      // The API error is shown below the form.
    }
  })

  const serverMessage =
    login.error instanceof ApiError
      ? login.error.errors?.email?.[0] ?? login.error.message
      : null

  return (
    <AuthLayout description="Vendor-managed company onboarding and access governance.">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-950/20">
        <div className="text-center">
          <span className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
            <ShieldCheck aria-hidden className="h-5 w-5" />
          </span>
          <p className="mt-4 text-xs font-bold uppercase tracking-[0.2em] text-blue-600">
            Vendor portal
          </p>
          <h2 className="mt-2 text-2xl font-bold tracking-tight">
            Super User sign in
          </h2>
          <p className="mt-2 text-sm text-slate-500">
            Restricted to authorized FuelFlow vendor personnel.
          </p>
        </div>

        <form className="mt-6 space-y-4" onSubmit={onSubmit}>
          <div>
            <label className="text-sm font-semibold text-slate-700" htmlFor="vendor-email">
              Email address
            </label>
            <input
              autoComplete="username"
              className="form-input mt-2"
              id="vendor-email"
              type="email"
              {...register('email')}
            />
            {errors.email ? <p className="mt-1.5 text-xs text-rose-600">{errors.email.message}</p> : null}
          </div>
          <div>
            <label className="text-sm font-semibold text-slate-700" htmlFor="vendor-password">
              Password
            </label>
            <PasswordInput
              autoComplete="current-password"
              id="vendor-password"
              registration={register('password')}
            />
            {errors.password ? <p className="mt-1.5 text-xs text-rose-600">{errors.password.message}</p> : null}
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input className="h-4 w-4 rounded border-slate-300" type="checkbox" {...register('remember')} />
            Keep this vendor session signed in
          </label>
          {serverMessage ? (
            <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700" role="alert">
              {serverMessage}
            </p>
          ) : null}
          <button
            className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            disabled={login.isPending}
            type="submit"
          >
            {login.isPending ? 'Signing in…' : 'Open vendor dashboard'}
          </button>
        </form>

        <div className="mt-5 border-t border-slate-200 pt-4 text-center">
          <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-600" to="/login">
            <Building2 aria-hidden className="h-4 w-4" /> Company staff sign in
          </Link>
        </div>
      </section>
    </AuthLayout>
  )
}
