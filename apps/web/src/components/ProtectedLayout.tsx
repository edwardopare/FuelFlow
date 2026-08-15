import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useCurrentUser } from '../hooks/useAuth'
import {
  ApiError,
  AUTHENTICATION_REQUIRED_EVENT,
  type AuthenticationScope,
} from '../lib/api'
import type { User } from '../types/api'
import { AppShell } from './AppShell'
import { LoadingScreen } from './LoadingScreen'

export type AuthenticatedOutletContext = {
  user: User
}

export function ProtectedLayout() {
  const currentUser = useCurrentUser()
  const location = useLocation()

  useEffect(() => {
    const refreshAuthentication = (event: Event) => {
      const scope = (event as CustomEvent<{ scope: AuthenticationScope }>).detail
        ?.scope

      if (scope === 'tenant') {
        void currentUser.refetch()
      }
    }

    window.addEventListener(
      AUTHENTICATION_REQUIRED_EVENT,
      refreshAuthentication,
    )

    return () =>
      window.removeEventListener(
        AUTHENTICATION_REQUIRED_EVENT,
        refreshAuthentication,
      )
  }, [currentUser.refetch])

  if (currentUser.isLoading) {
    return <LoadingScreen label="Checking access" />
  }

  if (
    currentUser.error instanceof ApiError &&
    currentUser.error.status === 401
  ) {
    return <Navigate replace state={{ from: location }} to="/login" />
  }

  if (currentUser.error || !currentUser.data) {
    return (
      <div className="grid min-h-screen place-items-center bg-slate-50 p-6">
        <div className="max-w-md rounded-xl border border-rose-200 bg-white p-6 text-center shadow-sm">
          <h1 className="text-lg font-bold text-slate-950">
            FuelFlow could not load
          </h1>
          <p className="mt-2 text-sm text-slate-600">
            Check the API connection and try again.
          </p>
          <button
            className="mt-5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            onClick={() => currentUser.refetch()}
            type="button"
          >
            Try again
          </button>
        </div>
      </div>
    )
  }

  if (currentUser.data.must_change_password) {
    return <Navigate replace to="/change-password" />
  }

  return (
    <AppShell user={currentUser.data}>
      <Outlet context={{ user: currentUser.data }} />
    </AppShell>
  )
}
