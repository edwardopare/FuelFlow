import type { ReactNode } from 'react'

type KpiCardProps = {
  label: string
  value: string
  detail: string
  icon: ReactNode
  tone?: 'blue' | 'green' | 'amber' | 'slate'
}

const iconToneClasses = {
  blue: 'bg-blue-50 text-blue-600',
  green: 'bg-emerald-50 text-emerald-600',
  amber: 'bg-amber-50 text-amber-600',
  slate: 'bg-slate-100 text-slate-600',
}

export function KpiCard({
  label,
  value,
  detail,
  icon,
  tone = 'blue',
}: KpiCardProps) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
            {label}
          </p>
          <p className="mt-3 text-2xl font-bold tracking-tight text-slate-950">
            {value}
          </p>
        </div>
        <span
          aria-hidden="true"
          className={`grid h-10 w-10 place-items-center rounded-lg ${iconToneClasses[tone]}`}
        >
          {icon}
        </span>
      </div>
      <p className="mt-3 text-sm text-slate-500">{detail}</p>
    </article>
  )
}
