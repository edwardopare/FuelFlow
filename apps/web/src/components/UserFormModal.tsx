import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { KeyRound, UserPlus, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { ApiError, apiFetch } from '../lib/api'
import type { ResourceResponse, Role, Station, User } from '../types/api'

const optionalPassword = z
  .string()
  .refine(
    (value) =>
      value.length === 0 ||
      (value.length >= 12 &&
        /[a-z]/.test(value) &&
        /[A-Z]/.test(value) &&
        /\d/.test(value) &&
        /[^A-Za-z0-9]/.test(value)),
    'Use 12+ characters with upper/lowercase, a number, and a symbol.',
  )

const userFormSchema = z
  .object({
    name: z.string().trim().min(2, 'Enter the user’s full name.').max(120),
    email: z.email('Enter a valid email address.'),
    phone: z.string().trim().max(40),
    role_id: z.string().min(1, 'Select an FRD role.'),
    station_id: z.string().min(1, 'Select a station.'),
    password: optionalPassword,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Temporary passwords do not match.',
    path: ['password_confirmation'],
  })

type UserFormValues = z.infer<typeof userFormSchema>

type UserFormModalProps = {
  initialUser?: User
  roles: Role[]
  stations: Station[]
  onClose: () => void
  onSaved: (user: User) => void
}

function initialValues(user?: User): UserFormValues {
  return {
    name: user?.name ?? '',
    email: user?.email ?? '',
    phone: user?.phone ?? '',
    role_id: user?.role_assignments[0]?.role.id ?? '',
    station_id:
      user?.stations.find((station) =>
        user.role_assignments.some(
          (assignment) => assignment.station_id === station.id,
        ),
      )?.id ??
      user?.stations[0]?.id ??
      '',
    password: '',
    password_confirmation: '',
  }
}

export function UserFormModal({
  initialUser,
  roles,
  stations,
  onClose,
  onSaved,
}: UserFormModalProps) {
  const queryClient = useQueryClient()
  const isEditing = Boolean(initialUser)
  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors },
  } = useForm<UserFormValues>({
    resolver: zodResolver(userFormSchema),
    defaultValues: initialValues(initialUser),
  })
  const selectedRoleId = watch('role_id')
  const selectedRole = roles.find((role) => role.id === selectedRoleId)

  const saveUser = useMutation({
    mutationFn: async (values: UserFormValues) => {
      if (!isEditing && !values.password) {
        setError('password', {
          type: 'required',
          message: 'Set a temporary password for the new user.',
        })
        throw new Error('FORM_VALIDATION')
      }

      const roleAssignments =
        selectedRole?.scope === 'station'
          ? [
              {
                role_id: values.role_id,
                station_id: values.station_id,
              },
            ]
          : [{ role_id: values.role_id, station_id: null }]
      const payload = {
        name: values.name,
        email: values.email,
        phone: values.phone || null,
        station_ids: [values.station_id],
        role_assignments: roleAssignments,
        ...(values.password
          ? {
              password: values.password,
              password_confirmation: values.password_confirmation,
            }
          : {}),
      }

      return apiFetch<ResourceResponse<User>>(
        initialUser ? `/api/v1/users/${initialUser.id}` : '/api/v1/users',
        {
          method: initialUser ? 'PATCH' : 'POST',
          body: JSON.stringify(payload),
        },
      )
    },
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['users'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['audit-events'] }),
      ])
      onSaved(response.data)
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    try {
      await saveUser.mutateAsync(values)
    } catch (error) {
      if (error instanceof Error && error.message === 'FORM_VALIDATION') {
        return
      }
    }
  })

  const serverMessage =
    saveUser.error instanceof ApiError
      ? Object.values(saveUser.error.errors ?? {})[0]?.[0] ??
        saveUser.error.message
      : saveUser.error instanceof Error &&
          saveUser.error.message !== 'FORM_VALIDATION'
        ? 'The user could not be saved.'
        : null

  return (
    <div
      aria-labelledby="user-form-title"
      aria-modal="true"
      className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      role="dialog"
    >
      <section className="my-6 w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
        <header className="flex items-start justify-between border-b border-slate-200 px-6 py-5">
          <div className="flex gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
              {isEditing ? (
                <KeyRound aria-hidden className="h-5 w-5" />
              ) : (
                <UserPlus aria-hidden className="h-5 w-5" />
              )}
            </span>
            <div>
              <h2 className="text-lg font-bold" id="user-form-title">
                {isEditing ? 'Edit user access' : 'Add a new user'}
              </h2>
              <p className="mt-1 text-sm text-slate-500">
                Assign the profile using only the roles defined by the FRD.
              </p>
            </div>
          </div>
          <button
            aria-label="Close user form"
            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden className="h-5 w-5" />
          </button>
        </header>

        <form className="space-y-6 p-6" onSubmit={onSubmit}>
          <div className="grid gap-5 sm:grid-cols-2">
            <FormField
              error={errors.name?.message}
              id="name"
              label="Full name"
            >
              <input
                autoComplete="name"
                className="form-input"
                id="name"
                placeholder="e.g. Ama Mensah"
                {...register('name')}
              />
            </FormField>
            <FormField
              error={errors.phone?.message}
              id="phone"
              label="Contact number"
            >
              <input
                autoComplete="tel"
                className="form-input"
                id="phone"
                placeholder="+233…"
                {...register('phone')}
              />
            </FormField>
          </div>

          <FormField
            error={errors.email?.message}
            id="email"
            label="Email address / login username"
          >
            <input
              autoComplete="email"
              className="form-input"
              id="email"
              placeholder="name@example.com"
              type="email"
              {...register('email')}
            />
          </FormField>

          <FormField
            error={errors.role_id?.message}
            id="role_id"
            label="FRD role"
          >
            <select className="form-input" id="role_id" {...register('role_id')}>
              <option value="">Select a role</option>
              {roles.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.name}
                </option>
              ))}
            </select>
            {selectedRole?.description ? (
              <p className="mt-2 text-xs leading-5 text-slate-500">
                {selectedRole.description}
              </p>
            ) : null}
          </FormField>

          <FormField
            error={errors.station_id?.message}
            id="station_id"
            label="Station assignment"
          >
            <select
              className="form-input"
              disabled={stations.length === 0}
              id="station_id"
              {...register('station_id')}
            >
              <option value="">
                {stations.length === 0
                  ? 'No stations available'
                  : 'Select a station'}
              </option>
              {stations.map((station) => (
                <option key={station.id} value={station.id}>
                  {station.name} ({station.code})
                  {station.is_active ? '' : ' — Inactive'}
                </option>
              ))}
            </select>
            <p className="mt-2 text-xs leading-5 text-slate-500">
              Options are loaded from the Stations page. This station becomes
              the user&apos;s primary assignment
              {selectedRole?.scope === 'station'
                ? ' and determines where their role applies.'
                : '.'}
            </p>
          </FormField>

          <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <h3 className="text-sm font-bold">
              {isEditing
                ? 'Reset password (optional)'
                : 'Temporary password'}
            </h3>
            <p className="mt-1 text-xs leading-5 text-slate-500">
              {isEditing
                ? 'Entering a new password forces the user to replace it at their next login.'
                : 'The user will be required to replace this password at first login.'}
            </p>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <FormField
                error={errors.password?.message}
                id="password"
                label="Temporary password"
              >
                <input
                  autoComplete="new-password"
                  className="form-input bg-white"
                  id="password"
                  type="password"
                  {...register('password')}
                />
              </FormField>
              <FormField
                error={errors.password_confirmation?.message}
                id="password_confirmation"
                label="Confirm password"
              >
                <input
                  autoComplete="new-password"
                  className="form-input bg-white"
                  id="password_confirmation"
                  type="password"
                  {...register('password_confirmation')}
                />
              </FormField>
            </div>
          </div>

          {serverMessage ? (
            <p
              className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"
              role="alert"
            >
              {serverMessage}
            </p>
          ) : null}

          <footer className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
            <button
              className="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
              onClick={onClose}
              type="button"
            >
              Cancel
            </button>
            <button
              className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"
              disabled={saveUser.isPending}
              type="submit"
            >
              {saveUser.isPending
                ? 'Saving…'
                : isEditing
                  ? 'Save changes'
                  : 'Create user'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

type FormFieldProps = {
  id: string
  label: string
  error?: string
  children: ReactNode
}

function FormField({
  id,
  label,
  error,
  children,
}: FormFieldProps) {
  return (
    <div>
      <label className="text-sm font-semibold text-slate-700" htmlFor={id}>
        {label}
      </label>
      <div className="mt-2">{children}</div>
      {error ? <p className="mt-1.5 text-xs text-rose-600">{error}</p> : null}
    </div>
  )
}
