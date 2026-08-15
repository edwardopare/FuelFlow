import { Fuel } from 'lucide-react'
import type { ReactNode } from 'react'
import loginStationBackground from '../assets/login-station-background-v2.webp'
import { CopyrightFooter } from './CopyrightFooter'

type AuthLayoutProps = {
  children: ReactNode
  description?: string
}

export function AuthLayout({
  children,
  description = 'Secure access to connected fuel operations.',
}: AuthLayoutProps) {
  return (
    <main className="relative isolate flex min-h-screen flex-col overflow-hidden bg-slate-950 text-slate-950">
      <img
        alt=""
        aria-hidden="true"
        className="absolute inset-0 -z-20 h-full w-full scale-[1.04] object-cover blur-[3px]"
        src={loginStationBackground}
      />
      <div
        aria-hidden="true"
        className="absolute inset-0 -z-10 bg-gradient-to-b from-slate-950/70 via-slate-950/50 to-slate-950/75"
      />

      <div className="flex flex-1 items-center justify-center px-5 py-6 sm:px-6">
        <div className="w-full max-w-md">
          <header className="mb-5 text-center">
            <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-950/30">
              <Fuel aria-hidden className="h-6 w-6" />
            </span>
            <p className="mt-3 text-sm font-bold uppercase tracking-[0.18em] text-blue-300">
              FuelFlow FSMS
            </p>
            <h1 className="mt-2 text-3xl font-extrabold tracking-tight text-white drop-shadow-sm sm:text-4xl">
              Fuel Management System
            </h1>
            <p className="mt-2 text-sm text-slate-200">{description}</p>
          </header>

          {children}
        </div>
      </div>

      <CopyrightFooter className="px-6 py-3 text-slate-200" />
    </main>
  )
}
