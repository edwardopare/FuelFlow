import {
  Building2,
  Fuel,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Menu,
  ShieldCheck,
  X,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useVendorLogout } from '../hooks/useVendorAuth'
import type { VendorUser } from '../types/api'
import { CopyrightFooter } from './CopyrightFooter'

type VendorShellProps = { user: VendorUser; children: ReactNode }

const vendorNavigation = [
  { label: 'Dashboard', href: '/vendor', icon: LayoutDashboard },
  { label: 'Companies', href: '/vendor/companies', icon: Building2 },
]

type VendorLinksProps = { onNavigate?: () => void }

function VendorLinks({ onNavigate }: VendorLinksProps) {
  return vendorNavigation.map((item) => (
    <NavLink
      className={({ isActive }) =>
        `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition-colors ${
          isActive
            ? 'bg-blue-600 text-white shadow-sm'
            : 'text-slate-300 hover:bg-slate-800 hover:text-white'
        }`
      }
      end={item.href === '/vendor'}
      key={item.href}
      onClick={onNavigate}
      to={item.href}
    >
      <item.icon aria-hidden className="h-[18px] w-[18px]" />
      {item.label}
    </NavLink>
  ))
}

export function VendorShell({ user, children }: VendorShellProps) {
  const logout = useVendorLogout()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  const handleLogout = async () => {
    await logout.mutateAsync()
    navigate('/vendor/login', { replace: true })
  }

  return (
    <div className="min-h-screen bg-slate-50 text-slate-950">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-[264px] flex-col bg-slate-950 text-white lg:flex">
        <div className="flex h-[72px] items-center gap-3 border-b border-slate-800 px-6">
          <span className="grid h-9 w-9 place-items-center rounded-lg bg-blue-600">
            <Fuel aria-hidden className="h-5 w-5" />
          </span>
          <div>
            <p className="font-bold tracking-tight">FuelFlow Control</p>
            <p className="text-xs text-blue-300">Vendor administration</p>
          </div>
        </div>
        <div className="mx-4 mt-5 rounded-xl border border-slate-800 bg-slate-900 p-4">
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-blue-300">
            <ShieldCheck aria-hidden className="h-4 w-4" />
            Super User
          </div>
          <p className="mt-2 truncate text-sm font-semibold">{user.name}</p>
          <p className="truncate text-xs text-slate-400">{user.email}</p>
        </div>
        <nav aria-label="Vendor navigation" className="flex-1 space-y-1 px-4 py-5">
          <VendorLinks />
        </nav>
        <div className="space-y-2 border-t border-slate-800 p-4">
          <NavLink
            className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-300 hover:bg-slate-800 hover:text-white"
            to="/vendor/change-password"
          >
            <KeyRound aria-hidden className="h-[18px] w-[18px]" />
            Change password
          </NavLink>
          <button
            className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-semibold text-slate-300 hover:bg-slate-800 hover:text-white disabled:opacity-50"
            disabled={logout.isPending}
            onClick={handleLogout}
            type="button"
          >
            <LogOut aria-hidden className="h-[18px] w-[18px]" />
            {logout.isPending ? 'Signing out…' : 'Log out'}
          </button>
        </div>
      </aside>

      <div className="flex min-h-screen flex-col lg:pl-[264px]">
        <header className="sticky top-0 z-20 flex h-[72px] items-center border-b border-slate-200 bg-white/95 px-5 backdrop-blur md:px-8">
          <button
            aria-expanded={mobileOpen}
            aria-label={mobileOpen ? 'Close vendor navigation' : 'Open vendor navigation'}
            className="rounded-lg border border-slate-200 p-2 text-slate-600 lg:hidden"
            onClick={() => setMobileOpen((open) => !open)}
            type="button"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
          <div className="ml-3 flex items-center gap-2 lg:ml-0">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-blue-600 text-white lg:hidden">
              <Fuel className="h-4 w-4" />
            </span>
            <div>
              <p className="text-sm font-bold">Vendor Control Plane</p>
              <p className="hidden text-xs text-slate-500 sm:block">
                Company onboarding and access governance
              </p>
            </div>
          </div>
          <span className="ml-auto rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
            Secure session
          </span>
          {mobileOpen ? (
            <nav className="absolute inset-x-0 top-full space-y-1 bg-slate-950 p-4 shadow-xl lg:hidden">
              <VendorLinks onNavigate={() => setMobileOpen(false)} />
              <NavLink
                className="mt-3 flex items-center gap-3 border-t border-slate-800 px-3 pt-4 text-sm font-semibold text-slate-300"
                onClick={() => setMobileOpen(false)}
                to="/vendor/change-password"
              >
                <KeyRound className="h-[18px] w-[18px]" /> Change password
              </NavLink>
              <button
                className="flex w-full items-center gap-3 px-3 py-2.5 text-sm font-semibold text-slate-300"
                disabled={logout.isPending}
                onClick={handleLogout}
                type="button"
              >
                <LogOut className="h-[18px] w-[18px]" /> Log out
              </button>
            </nav>
          ) : null}
        </header>
        <main className="flex-1 p-5 md:p-8">{children}</main>
        <CopyrightFooter className="border-t border-slate-200 bg-white px-6 py-4" />
      </div>
    </div>
  )
}
