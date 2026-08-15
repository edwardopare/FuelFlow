import {
  BarChart3,
  Building2,
  ClipboardCheck,
  Droplets,
  FileText,
  Fuel,
  Gauge,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageCheck,
  ReceiptText,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Truck,
  UserRound,
  Users,
  Warehouse,
  X,
} from 'lucide-react'
import { useState, type ComponentType, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useLogout } from '../hooks/useAuth'
import type { RoleSlug, User } from '../types/api'
import { CopyrightFooter } from './CopyrightFooter'

type AppShellProps = {
  user: User
  children: ReactNode
}

type NavigationItem = {
  label: string
  href: string
  icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
}

const navigationByRole: Record<RoleSlug, NavigationItem[]> = {
  administrator: [
    { label: 'Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'User Management', href: '/users', icon: Users },
    { label: 'Stations', href: '/stations', icon: Warehouse },
    { label: 'Supplier Management', href: '/modules/suppliers', icon: Building2 },
    { label: 'PO Approvals', href: '/modules/procurement', icon: PackageCheck },
    { label: 'Fuel Receiving', href: '/modules/receiving', icon: Truck },
    { label: 'Fuel Products', href: '/modules/products', icon: Droplets },
    { label: 'Tank Management', href: '/modules/tanks', icon: Warehouse },
    { label: 'Pump Management', href: '/modules/pumps', icon: Fuel },
    { label: 'Sale Management', href: '/modules/sales', icon: ReceiptText },
    {
      label: 'Daily Reconciliation',
      href: '/modules/reconciliation',
      icon: ClipboardCheck,
    },
    { label: 'Reporting', href: '/modules/reporting', icon: BarChart3 },
    { label: 'Report Schedules', href: '/modules/report-schedules', icon: FileText },
    { label: 'Audit Log', href: '/audit', icon: ShieldCheck },
    { label: 'System Configuration', href: '/modules/settings', icon: Settings },
  ],
  owner: [
    { label: 'Portfolio Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'Station Performance', href: '/modules/stations', icon: Gauge },
    { label: 'Reporting', href: '/modules/reporting', icon: FileText },
  ],
  station_manager: [
    { label: 'Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'Pump Management', href: '/modules/pumps', icon: Fuel },
    { label: 'Tank Management', href: '/modules/tanks', icon: Warehouse },
    { label: 'Fuel Products', href: '/modules/products', icon: Droplets },
    { label: 'Fuel Receiving', href: '/modules/receiving', icon: Truck },
    { label: 'Procurement', href: '/modules/procurement', icon: ShoppingCart },
    { label: 'Supplier Management', href: '/modules/suppliers', icon: Building2 },
    {
      label: 'Daily Reconciliation',
      href: '/modules/reconciliation',
      icon: ClipboardCheck,
    },
    { label: 'Reporting', href: '/modules/reporting', icon: BarChart3 },
    { label: 'Report Schedules', href: '/modules/report-schedules', icon: FileText },
    { label: 'Users & Shifts', href: '/users', icon: Users },
    { label: 'Shift Management', href: '/modules/shifts', icon: Gauge },
  ],
  cashier_attendant: [
    { label: 'Assigned Shift', href: '/', icon: Gauge },
    { label: 'Sale Entry', href: '/modules/sales', icon: ReceiptText },
    { label: 'Meter Readings', href: '/modules/meter-readings', icon: Fuel },
    { label: 'Receipts', href: '/modules/receipts', icon: FileText },
    { label: 'Cash Count', href: '/modules/cash-count', icon: ClipboardCheck },
  ],
  accountant: [
    { label: 'Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'PO Requests', href: '/modules/po-requests', icon: PackageCheck },
    {
      label: 'Daily Reconciliation',
      href: '/modules/reconciliation',
      icon: ClipboardCheck,
    },
    { label: 'Reporting', href: '/modules/reporting', icon: BarChart3 },
    { label: 'Report Schedules', href: '/modules/report-schedules', icon: FileText },
  ],
  auditor: [
    { label: 'Read-only Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'Reporting', href: '/modules/reporting', icon: BarChart3 },
  ],
}

function navigationForUser(user: User): NavigationItem[] {
  const seen = new Set<string>()
  const items: NavigationItem[] = []

  for (const assignment of user.role_assignments) {
    for (const item of navigationByRole[assignment.role.slug] ?? []) {
      if (!seen.has(item.href)) {
        seen.add(item.href)
        items.push(item)
      }
    }
  }

  return items.length > 0 ? items : navigationByRole.auditor
}

type NavigationLinksProps = {
  navigation: NavigationItem[]
  onNavigate?: () => void
}

function NavigationLinks({
  navigation,
  onNavigate,
}: NavigationLinksProps) {
  return navigation.map((item) => (
    <NavLink
      className={({ isActive }) =>
        `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
          isActive
            ? 'bg-blue-50 text-blue-700'
            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'
        }`
      }
      end={item.href === '/'}
      key={item.href}
      onClick={onNavigate}
      to={item.href}
    >
      <item.icon aria-hidden className="h-[18px] w-[18px]" />
      {item.label}
    </NavLink>
  ))
}

export function AppShell({ user, children }: AppShellProps) {
  const logout = useLogout()
  const navigate = useNavigate()
  const [mobileNavigationOpen, setMobileNavigationOpen] = useState(false)
  const navigation = navigationForUser(user)
  const primaryRole = user.role_assignments[0]?.role.name ?? 'Authorized user'
  const primaryStation = user.stations[0]

  const handleLogout = async () => {
    await logout.mutateAsync()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-screen bg-slate-50 text-slate-950">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-[260px] flex-col border-r border-slate-200 bg-white lg:flex">
        <div className="flex h-[72px] items-center gap-3 border-b border-slate-200 px-6">
          <span className="grid h-9 w-9 place-items-center rounded-lg bg-blue-600 text-white">
            <Fuel aria-hidden className="h-5 w-5" />
          </span>
          <div>
            <p className="text-base font-bold tracking-tight">FuelFlow FSMS</p>
            <p className="text-xs text-slate-500">{primaryRole}</p>
          </div>
        </div>

        <nav
          aria-label="Primary navigation"
          className="flex-1 space-y-1 overflow-y-auto px-4 py-5"
        >
          <NavigationLinks navigation={navigation} />
        </nav>

        <div className="border-t border-slate-200 p-4">
          <div className="flex items-center gap-3 px-2 pb-3">
            <span className="grid h-9 w-9 place-items-center rounded-full bg-slate-900 text-sm font-semibold text-white">
              {user.name
                .split(' ')
                .slice(0, 2)
                .map((part) => part[0])
                .join('')}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{user.name}</p>
              <p className="truncate text-xs text-slate-500">{primaryRole}</p>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <NavLink
              className={({ isActive }) =>
                `inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-xs font-semibold ${
                  isActive
                    ? 'bg-blue-50 text-blue-700'
                    : 'bg-slate-50 text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                }`
              }
              to="/profile"
            >
              <UserRound aria-hidden className="h-4 w-4" />
              My profile
            </NavLink>
            <button
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
              disabled={logout.isPending}
              onClick={handleLogout}
              type="button"
            >
              <LogOut aria-hidden className="h-4 w-4" />
              {logout.isPending ? 'Signing out…' : 'Log out'}
            </button>
          </div>
        </div>
      </aside>

      <div className="flex min-h-screen flex-col lg:pl-[260px]">
        <header className="sticky top-0 z-20 flex h-[72px] items-center gap-2 border-b border-slate-200 bg-white/95 px-3 backdrop-blur sm:px-5 md:px-8">
          <button
            aria-expanded={mobileNavigationOpen}
            aria-label={
              mobileNavigationOpen
                ? 'Close primary navigation'
                : 'Open primary navigation'
            }
            className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50 lg:hidden"
            onClick={() => setMobileNavigationOpen((open) => !open)}
            type="button"
          >
            {mobileNavigationOpen ? (
              <X aria-hidden className="h-5 w-5" />
            ) : (
              <Menu aria-hidden className="h-5 w-5" />
            )}
          </button>
          <div className="flex items-center gap-2 lg:hidden">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-blue-600 text-white">
              <Fuel aria-hidden className="h-4 w-4" />
            </span>
            <span className="hidden font-bold sm:inline">FuelFlow</span>
          </div>
          <div className="ml-auto flex items-center gap-3">
            <label className="sr-only" htmlFor="station-context">
              Station context
            </label>
            <select
              className="max-w-[160px] rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 sm:max-w-56"
              defaultValue={primaryStation?.id ?? ''}
              id="station-context"
            >
              {user.stations.map((station) => (
                <option key={station.id} value={station.id}>
                  {station.name}
                </option>
              ))}
            </select>
            <span className="hidden rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-600 sm:inline">
              GHS
            </span>
          </div>
          {mobileNavigationOpen ? (
            <nav
              aria-label="Mobile primary navigation"
              className="absolute inset-x-0 top-full max-h-[calc(100vh-72px)] space-y-1 overflow-y-auto border-b border-slate-200 bg-white p-4 shadow-lg lg:hidden"
            >
              <NavigationLinks
                navigation={navigation}
                onNavigate={() => setMobileNavigationOpen(false)}
              />
              <div className="mt-4 grid gap-2 border-t border-slate-200 pt-4">
                <NavLink
                  className={({ isActive }) =>
                    `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium ${
                      isActive
                        ? 'bg-blue-50 text-blue-700'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'
                    }`
                  }
                  onClick={() => setMobileNavigationOpen(false)}
                  to="/profile"
                >
                  <UserRound aria-hidden className="h-[18px] w-[18px]" />
                  My profile &amp; password
                </NavLink>
                <button
                  className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-950 disabled:opacity-50"
                  disabled={logout.isPending}
                  onClick={handleLogout}
                  type="button"
                >
                  <LogOut aria-hidden className="h-[18px] w-[18px]" />
                  {logout.isPending ? 'Signing out…' : 'Log out'}
                </button>
              </div>
            </nav>
          ) : null}
        </header>

        <main className="flex-1 p-5 md:p-8">{children}</main>
        <CopyrightFooter className="border-t border-slate-200 bg-white px-6 py-4" />
      </div>
    </div>
  )
}
