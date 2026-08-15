import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  Clock3,
  Gauge,
  Play,
  Square,
  Timer,
  X,
} from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { StatusBadge } from '../components/StatusBadge'
import { ApiError, apiFetch } from '../lib/api'
import {
  accraDateKey,
  formatAccraDate,
  formatAccraDateTime,
} from '../lib/format'
import type { ResourceResponse } from '../types/api'

type PumpNozzle = {
  id: string
  code: string
  product_name: string | null
  current_meter_reading: string | number
}

type ShiftRecord = {
  id: string
  code: string
  pump_name: string | null
  status: 'scheduled' | 'open' | 'closed'
  scheduled_start: string
  scheduled_end: string
  opened_at: string | null
  started_by_name: string | null
  late_seconds: number
  is_late: boolean
  closed_at: string | null
  ended_by_name: string | null
  overtime_seconds: number
  is_overtime: boolean
  elapsed_seconds: number
  pump_nozzles: PumpNozzle[]
}

type ShiftListResponse = {
  data: ShiftRecord[]
}

type ShiftAction = 'start' | 'end'

type ShiftPayload = {
  readings: Array<{ nozzle_id: string; reading: number }>
  counted_cash?: number
  notes?: string
}

function formatDuration(totalSeconds: number): string {
  const seconds = Math.max(0, Math.floor(totalSeconds))
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const remainingSeconds = seconds % 60
  return [hours, minutes, remainingSeconds]
    .map((value) => String(value).padStart(2, '0'))
    .join(':')
}

function formatMinutes(seconds: number): string {
  return `${Math.ceil(Math.max(0, seconds) / 60).toLocaleString('en-GH')} min`
}

function mutationError(error: unknown): string | null {
  if (error instanceof ApiError) {
    return Object.values(error.errors ?? {})[0]?.[0] ?? error.message
  }
  return error instanceof Error ? error.message : null
}

function ShiftActionModal({
  action,
  error,
  onClose,
  onSubmit,
  saving,
  shift,
}: {
  action: ShiftAction
  error: string | null
  onClose: () => void
  onSubmit: (payload: ShiftPayload) => void
  saving: boolean
  shift: ShiftRecord
}) {
  const [readings, setReadings] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      shift.pump_nozzles.map((nozzle) => [
        nozzle.id,
        String(nozzle.current_meter_reading),
      ]),
    ),
  )
  const [countedCash, setCountedCash] = useState('')
  const ending = action === 'end'

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    onSubmit({
      readings: shift.pump_nozzles.map((nozzle) => ({
        nozzle_id: nozzle.id,
        reading: Number(readings[nozzle.id]),
      })),
      ...(ending
        ? {
            counted_cash: Number(countedCash),
            notes: 'Shift closed by the assigned Pump Attendant.',
          }
        : {}),
    })
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <section
        aria-labelledby="shift-action-title"
        aria-modal="true"
        className="w-full max-w-xl rounded-2xl bg-white shadow-2xl"
        role="dialog"
      >
        <header className="flex items-start justify-between border-b border-slate-200 p-6">
          <div>
            <p className="text-sm font-semibold text-blue-600">{shift.code}</p>
            <h2 className="mt-1 text-xl font-bold" id="shift-action-title">
              {ending ? 'End shift' : 'Start shift'}
            </h2>
            <p className="mt-2 text-sm text-slate-500">
              Confirm the {ending ? 'closing' : 'opening'} meter readings for {shift.pump_name}.
            </p>
          </div>
          <button
            aria-label="Close shift form"
            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"
            onClick={onClose}
            type="button"
          >
            <X className="h-5 w-5" />
          </button>
        </header>

        <form onSubmit={submit}>
          <div className="space-y-5 p-6">
            {shift.pump_nozzles.map((nozzle) => (
              <label key={nozzle.id}>
                <span className="mb-2 block text-sm font-semibold">
                  {nozzle.code} · {nozzle.product_name ?? 'Fuel'} meter reading
                </span>
                <input
                  className="form-input"
                  min="0"
                  onChange={(event) =>
                    setReadings((current) => ({
                      ...current,
                      [nozzle.id]: event.target.value,
                    }))
                  }
                  required
                  step="0.001"
                  type="number"
                  value={readings[nozzle.id] ?? ''}
                />
              </label>
            ))}
            {ending ? (
              <label>
                <span className="mb-2 block text-sm font-semibold">
                  Counted cash (GHS) *
                </span>
                <input
                  className="form-input"
                  min="0"
                  onChange={(event) => setCountedCash(event.target.value)}
                  required
                  step="0.01"
                  type="number"
                  value={countedCash}
                />
              </label>
            ) : null}
            {error ? (
              <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">
                {error}
              </p>
            ) : null}
          </div>
          <footer className="flex justify-end gap-3 border-t border-slate-200 p-4">
            <button
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50"
              onClick={onClose}
              type="button"
            >
              Cancel
            </button>
            <button
              className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 ${ending ? 'bg-slate-900 hover:bg-slate-800' : 'bg-blue-600 hover:bg-blue-700'}`}
              disabled={saving}
              type="submit"
            >
              {ending ? <Square className="h-4 w-4" /> : <Play className="h-4 w-4" />}
              {saving ? 'Recording…' : ending ? 'End shift now' : 'Start shift now'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

export default function AssignedShiftDashboard() {
  const queryClient = useQueryClient()
  const [nowMs, setNowMs] = useState(Date.now())
  const [action, setAction] = useState<{
    shift: ShiftRecord
    type: ShiftAction
  } | null>(null)
  const shifts = useQuery({
    queryKey: ['shifts'],
    queryFn: () => apiFetch<ShiftListResponse>('/api/v1/shifts'),
    refetchInterval: 30_000,
  })
  const records = shifts.data?.data ?? []
  const openShift = records.find((shift) => shift.status === 'open')
  const todayKey = accraDateKey(nowMs)
  const scheduledShifts = records
    .filter((shift) => shift.status === 'scheduled')
    .toSorted(
      (left, right) =>
        new Date(left.scheduled_start).getTime()
        - new Date(right.scheduled_start).getTime(),
    )
  const todayShift = scheduledShifts.find(
    (shift) => accraDateKey(shift.scheduled_start) === todayKey,
  )
  const nextScheduled = scheduledShifts.find(
    (shift) => accraDateKey(shift.scheduled_start) > todayKey,
  )
  const activeShift = openShift ?? todayShift ?? nextScheduled
  const activeShiftDate = activeShift
    ? accraDateKey(activeShift.scheduled_start)
    : null
  const isFutureShift = activeShift?.status === 'scheduled'
    && activeShiftDate !== null
    && activeShiftDate > todayKey
  const canStartShift = activeShift?.status === 'scheduled'
    && activeShiftDate === todayKey

  useEffect(() => {
    const interval = window.setInterval(
      () => setNowMs(Date.now()),
      activeShift?.status === 'open' ? 1000 : 30_000,
    )
    return () => window.clearInterval(interval)
  }, [activeShift?.status])

  const updateShift = useMutation({
    mutationFn: ({
      payload,
      shift,
      type,
    }: {
      payload: ShiftPayload
      shift: ShiftRecord
      type: ShiftAction
    }) =>
      apiFetch<ResourceResponse<ShiftRecord>>(
        `/api/v1/shifts/${shift.id}/${type === 'start' ? 'open' : 'close'}`,
        { method: 'POST', body: JSON.stringify(payload) },
      ),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['shifts'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
      setAction(null)
      setNowMs(Date.now())
    },
  })

  const elapsedSeconds = activeShift?.status === 'open' && activeShift.opened_at
    ? Math.max(
        0,
        Math.floor(
          ((activeShift.closed_at
            ? new Date(activeShift.closed_at).getTime()
            : nowMs)
            - new Date(activeShift.opened_at).getTime())
            / 1000,
        ),
      )
    : 0
  const liveOvertimeSeconds = activeShift?.status === 'open'
    ? Math.max(
        0,
        Math.floor(
          (nowMs - new Date(activeShift.scheduled_end).getTime()) / 1000,
        ),
      )
    : (activeShift?.overtime_seconds ?? 0)
  const scheduledStartPassed = activeShift?.status === 'scheduled'
    && canStartShift
    && nowMs > new Date(activeShift.scheduled_start).getTime()

  return (
    <div className="mx-auto max-w-[1200px]">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-blue-600">Pump Attendant portal</p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">Assigned shift</h1>
          <p className="mt-2 text-sm text-slate-500">
            Start your assigned shift, monitor elapsed time, and record the end-of-shift meter and cash position.
          </p>
        </div>
        <StatusBadge
          label={activeShift?.status === 'open'
            ? 'Shift in progress'
            : isFutureShift
              ? `Next shift · ${formatAccraDate(activeShift.scheduled_start.slice(0, 10))}`
              : activeShift
                ? 'Awaiting start'
                : 'No active assignment'}
          tone={activeShift?.status === 'open' ? 'success' : activeShift ? 'warning' : 'neutral'}
        />
      </header>

      {shifts.isError ? (
        <p className="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
          {mutationError(shifts.error) ?? 'Unable to load assigned shifts.'}
        </p>
      ) : null}

      {activeShift ? (
        <section className="mt-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="grid gap-6 bg-gradient-to-r from-slate-700 to-slate-600 p-6 text-white lg:grid-cols-[1fr_auto] lg:items-center">
            <div>
              <div className="flex flex-wrap items-center gap-3">
                <p className="text-sm font-semibold text-slate-200">{activeShift.code}</p>
                {activeShift.is_late ? <StatusBadge label={`Late · ${formatMinutes(activeShift.late_seconds)}`} tone="danger" /> : null}
                {scheduledStartPassed ? <StatusBadge label="Start overdue" tone="danger" /> : null}
                {liveOvertimeSeconds > 0 ? <StatusBadge label={`Overtime · ${formatMinutes(liveOvertimeSeconds)}`} tone="warning" /> : null}
              </div>
              <h2 className="mt-3 text-2xl font-bold">{activeShift.pump_name ?? 'Assigned pump'}</h2>
              <p className="mt-2 text-sm text-slate-300">
                Scheduled {formatAccraDateTime(activeShift.scheduled_start)} to {formatAccraDateTime(activeShift.scheduled_end)}
              </p>
            </div>
            <div className="min-w-64 rounded-xl border border-white/15 bg-white/10 p-5 text-center backdrop-blur-sm">
              <div className="flex items-center justify-center gap-2 text-sm font-semibold text-slate-200">
                <Timer className="h-4 w-4" />
                {activeShift.status === 'open' ? 'Elapsed shift time' : 'Timer starts when you begin'}
              </div>
              <p aria-live="off" className="mt-2 font-mono text-4xl font-bold tracking-wider" role="timer">
                {formatDuration(elapsedSeconds)}
              </p>
            </div>
          </div>

          <div className="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-xl bg-slate-50 p-4">
              <CalendarClock className="h-5 w-5 text-blue-600" />
              <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Scheduled start</p>
              <p className="mt-1 text-sm font-bold">{formatAccraDateTime(activeShift.scheduled_start)}</p>
            </div>
            <div className="rounded-xl bg-slate-50 p-4">
              <Play className="h-5 w-5 text-emerald-600" />
              <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Actual start</p>
              <p className="mt-1 text-sm font-bold">{activeShift.status !== 'scheduled' && activeShift.opened_at ? formatAccraDateTime(activeShift.opened_at) : 'Not started'}</p>
            </div>
            <div className="rounded-xl bg-slate-50 p-4">
              <Clock3 className="h-5 w-5 text-amber-600" />
              <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Scheduled end</p>
              <p className="mt-1 text-sm font-bold">{formatAccraDateTime(activeShift.scheduled_end)}</p>
            </div>
            <div className="rounded-xl bg-slate-50 p-4">
              <Square className="h-5 w-5 text-slate-600" />
              <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Actual end</p>
              <p className="mt-1 text-sm font-bold">{activeShift.closed_at ? formatAccraDateTime(activeShift.closed_at) : 'Not ended'}</p>
            </div>
          </div>

          <div className="flex flex-col gap-4 border-t border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3 text-sm text-slate-600">
              {activeShift.status === 'open' ? <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" /> : <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />}
              <p>
                {activeShift.status === 'open'
                  ? `Started by ${activeShift.started_by_name ?? 'the assigned attendant'} at ${formatAccraDateTime(activeShift.opened_at as string)}.`
                  : isFutureShift
                    ? `This recurring shift unlocks on ${formatAccraDate(activeShift.scheduled_start.slice(0, 10))}.`
                    : 'Starting records the exact timestamp and determines whether the shift began late.'}
              </p>
            </div>
            <button
              className={`inline-flex shrink-0 items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:bg-slate-400 ${activeShift.status === 'open' ? 'bg-slate-900 hover:bg-slate-800' : 'bg-blue-600 hover:bg-blue-700'}`}
              disabled={activeShift.status === 'scheduled' && !canStartShift}
              onClick={() => setAction({
                shift: activeShift,
                type: activeShift.status === 'open' ? 'end' : 'start',
              })}
              type="button"
            >
              {activeShift.status === 'open' ? <Square className="h-4 w-4" /> : <Play className="h-4 w-4" />}
              {activeShift.status === 'open'
                ? 'End shift'
                : isFutureShift
                  ? `Available ${formatAccraDate(activeShift.scheduled_start.slice(0, 10))}`
                  : 'Start shift'}
            </button>
          </div>
        </section>
      ) : (
        <section className="mt-7 grid min-h-72 place-items-center rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
          <div>
            <Gauge className="mx-auto h-10 w-10 text-slate-400" />
            <h2 className="mt-4 text-lg font-bold">No shift assigned</h2>
            <p className="mt-2 text-sm text-slate-500">Your Station Manager has not assigned an actionable shift yet.</p>
          </div>
        </section>
      )}

      <section className="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header className="border-b border-slate-200 px-5 py-4">
          <h2 className="font-bold">Shift attendance history</h2>
          <p className="mt-1 text-sm text-slate-500">Scheduled and actual timestamps, lateness, and recorded overtime.</p>
        </header>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
              <tr><th className="px-5 py-3">Shift</th><th className="px-5 py-3">Schedule</th><th className="px-5 py-3">Actual start</th><th className="px-5 py-3">Actual end</th><th className="px-5 py-3">Attendance</th><th className="px-5 py-3">Overtime</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {records.slice(0, 8).map((shift) => (
                <tr key={shift.id}>
                  <td className="px-5 py-4"><p className="font-semibold">{shift.code}</p><p className="mt-1 text-xs text-slate-500">{shift.pump_name}</p></td>
                  <td className="px-5 py-4"><p>{formatAccraDateTime(shift.scheduled_start)}</p><p className="mt-1 text-xs text-slate-500">to {formatAccraDateTime(shift.scheduled_end)}</p></td>
                  <td className="px-5 py-4">{shift.status !== 'scheduled' && shift.opened_at ? formatAccraDateTime(shift.opened_at) : '—'}</td>
                  <td className="px-5 py-4">{shift.closed_at ? formatAccraDateTime(shift.closed_at) : '—'}</td>
                  <td className="px-5 py-4">{shift.status !== 'scheduled' && shift.opened_at ? <StatusBadge label={shift.is_late ? `Late · ${formatMinutes(shift.late_seconds)}` : 'On time'} tone={shift.is_late ? 'danger' : 'success'} /> : <StatusBadge label="Not started" tone="warning" />}</td>
                  <td className="px-5 py-4">{shift.overtime_seconds > 0 ? formatMinutes(shift.overtime_seconds) : '—'}</td>
                </tr>
              ))}
              {!shifts.isLoading && records.length === 0 ? <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={6}>No shift records are available.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </section>

      {action ? (
        <ShiftActionModal
          action={action.type}
          error={mutationError(updateShift.error)}
          key={`${action.shift.id}-${action.type}`}
          onClose={() => setAction(null)}
          onSubmit={(payload) => updateShift.mutate({
            payload,
            shift: action.shift,
            type: action.type,
          })}
          saving={updateShift.isPending}
          shift={action.shift}
        />
      ) : null}
    </div>
  )
}
