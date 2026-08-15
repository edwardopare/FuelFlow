import { zodResolver } from '@hookform/resolvers/zod'
import { KeyRound } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Navigate, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { AuthLayout } from '../components/AuthLayout'
import { LoadingScreen } from '../components/LoadingScreen'
import { PasswordInput } from '../components/PasswordInput'
import {
  useCurrentVendorUser,
  useVendorChangePassword,
} from '../hooks/useVendorAuth'
import { ApiError } from '../lib/api'

const schema = z
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
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type FormValues = z.infer<typeof schema>

export default function VendorChangePasswordPage() {
  const currentUser = useCurrentVendorUser()
  const changePassword = useVendorChangePassword()
  const navigate = useNavigate()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      current_password: '',
      password: '',
      password_confirmation: '',
    },
  })

  if (currentUser.isLoading) return <LoadingScreen label="Loading secure setup" />
  if (!currentUser.data) return <Navigate replace to="/vendor/login" />

  const onSubmit = handleSubmit(async (values) => {
    await changePassword.mutateAsync(values)
    navigate('/vendor', { replace: true })
  })
  const serverMessage =
    changePassword.error instanceof ApiError
      ? Object.values(changePassword.error.errors ?? {})[0]?.[0] ?? changePassword.error.message
      : null

  return (
    <AuthLayout description="Protect access to every company managed by the vendor.">
      <section className="rounded-2xl border border-slate-200 bg-white p-7 shadow-xl">
        <span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
          <KeyRound aria-hidden className="h-5 w-5" />
        </span>
        <h1 className="mt-5 text-2xl font-bold">Secure your vendor account</h1>
        <p className="mt-2 text-sm leading-6 text-slate-500">
          Use a unique permanent password with at least 12 characters.
        </p>
        <form className="mt-6 space-y-4" onSubmit={onSubmit}>
          {([
            ['current_password', 'Current password', 'current-password'],
            ['password', 'New password', 'new-password'],
            ['password_confirmation', 'Confirm new password', 'new-password'],
          ] as const).map(([id, label, autoComplete]) => (
            <div key={id}>
              <label className="text-sm font-semibold text-slate-700" htmlFor={`vendor-${id}`}>{label}</label>
              <PasswordInput
                autoComplete={autoComplete}
                id={`vendor-${id}`}
                registration={register(id)}
              />
              {errors[id] ? <p className="mt-1.5 text-xs text-rose-600">{errors[id]?.message}</p> : null}
            </div>
          ))}
          {serverMessage ? <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert">{serverMessage}</p> : null}
          <button className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60" disabled={changePassword.isPending} type="submit">
            {changePassword.isPending ? 'Saving…' : 'Save and continue'}
          </button>
        </form>
      </section>
    </AuthLayout>
  )
}
