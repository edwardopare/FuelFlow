import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useCurrentVendorUser } from '../hooks/useVendorAuth'
import {
  ApiError,
  AUTHENTICATION_REQUIRED_EVENT,
  type AuthenticationScope,
} from '../lib/api'
import type { VendorUser } from '../types/api'
import { LoadingScreen } from './LoadingScreen'
import { VendorShell } from './VendorShell'

export type VendorOutletContext = { vendorUser: VendorUser }

export function VendorProtectedLayout() {
  const currentUser = useCurrentVendorUser()
  const location = useLocation()

  useEffect(() => {
    const refreshAuthentication = (event: Event) => {
      const scope = (event as CustomEvent<{ scope: AuthenticationScope }>).detail
        ?.scope

      if (scope === 'vendor') {
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
    return <LoadingScreen label="Checking vendor access" />
  }

  if (
    currentUser.error instanceof ApiError &&
    currentUser.error.status === 401
  ) {
    return (
      <Navigate replace state={{ from: location }} to="/vendor/login" />
    )
  }

  if (currentUser.error || !currentUser.data) {
    return (
      <div className="grid min-h-screen place-items-center bg-slate-50 p-6">
        <section className="max-w-md rounded-xl border border-rose-200 bg-white p-6 text-center shadow-sm">
          <h1 className="text-lg font-bold">Vendor portal could not load</h1>
          <p className="mt-2 text-sm text-slate-600">
            Check the API connection and try again.
          </p>
          <button
            className="mt-5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"
            onClick={() => currentUser.refetch()}
            type="button"
          >
            Try again
          </button>
        </section>
      </div>
    )
  }

  if (currentUser.data.must_change_password) {
    return <Navigate replace to="/vendor/change-password" />
  }

  return (
    <VendorShell user={currentUser.data}>
      <Outlet context={{ vendorUser: currentUser.data }} />
    </VendorShell>
  )
}
