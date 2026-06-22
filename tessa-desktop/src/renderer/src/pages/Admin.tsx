import { useState, useEffect, useCallback } from 'react'
import { adminAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import type { AdminMeetingRow, AdminDailyRow, AdminAttendanceRow } from '@/lib/types'
import { classNames, formatDate } from '@/lib/utils'

type Tab = 'meetings' | 'daily' | 'attendance'

const ROW_BG: Record<string, string> = {
  yellow: 'bg-yellow-500/10',
  red: 'bg-red-500/10',
  green: 'bg-emerald-500/10'
}

function agendaBadge(status: AdminMeetingRow['agendaStatus']): JSX.Element {
  const cls =
    status === 'filled'
      ? 'bg-emerald-500/15 text-emerald-400'
      : status === 'partial'
        ? 'bg-amber-500/15 text-amber-400'
        : 'bg-red-500/15 text-red-400'
  return (
    <span className={classNames('inline-block rounded-full px-2 py-0.5 text-[11px] font-medium', cls)}>
      {status}
    </span>
  )
}

function notesBadge(status: AdminMeetingRow['notesStatus']): JSX.Element {
  const cls =
    status === 'written'
      ? 'bg-emerald-500/15 text-emerald-400'
      : 'bg-zinc-700/60 text-zinc-400'
  return (
    <span className={classNames('inline-block rounded-full px-2 py-0.5 text-[11px] font-medium', cls)}>
      {status}
    </span>
  )
}

function dailyBadge(status: AdminDailyRow['status']): JSX.Element {
  const cls =
    status === 'submitted'
      ? 'bg-emerald-500/15 text-emerald-400'
      : status === 'partial'
        ? 'bg-amber-500/15 text-amber-400'
        : status === 'missing'
          ? 'bg-red-500/15 text-red-400'
          : 'bg-zinc-700/60 text-zinc-400'
  return (
    <span className={classNames('inline-block rounded-full px-2 py-0.5 text-[11px] font-medium', cls)}>
      {status}
    </span>
  )
}

function attendanceStatusBadge(status: string): JSX.Element {
  const cls =
    status === 'present'
      ? 'bg-emerald-500/15 text-emerald-400'
      : status === 'absent'
        ? 'bg-red-500/15 text-red-400'
        : 'bg-zinc-700/60 text-zinc-500'
  const label = status === 'no_data' ? '—' : status
  return (
    <span className={classNames('inline-block rounded-full px-2 py-0.5 text-[11px] font-medium', cls)}>
      {label}
    </span>
  )
}

function rateBadge(rate: number, hasData: boolean): JSX.Element {
  if (!hasData) {
    return (
      <span className="inline-block rounded-full px-2 py-0.5 text-[11px] font-medium bg-zinc-700/60 text-zinc-500">
        No data
      </span>
    )
  }
  const cls =
    rate === 100
      ? 'bg-emerald-500/15 text-emerald-400'
      : rate >= 50
        ? 'bg-amber-500/15 text-amber-400'
        : 'bg-red-500/15 text-red-400'
  return (
    <span className={classNames('inline-block rounded-full px-2 py-0.5 text-[11px] font-medium', cls)}>
      {rate}%
    </span>
  )
}

export default function Admin(): JSX.Element {
  const [tab, setTab] = useState<Tab>('meetings')

  // ── Meetings state ──
  const [meetDate, setMeetDate] = useState(() => formatDate(new Date()))
  const [meetings, setMeetings] = useState<AdminMeetingRow[]>([])
  const [meetLoading, setMeetLoading] = useState(true)

  // ── Daily Reports state ──
  const [dailyDate, setDailyDate] = useState(() => formatDate(new Date()))
  const [dailyRows, setDailyRows] = useState<AdminDailyRow[]>([])
  const [dailyLoading, setDailyLoading] = useState(true)

  // ── Attendance state ──
  const [attDate, setAttDate] = useState(() => formatDate(new Date()))
  const [attRows, setAttRows] = useState<AdminAttendanceRow[]>([])
  const [attLoading, setAttLoading] = useState(true)

  // ── Fetch attendance overview ──
  const loadAttendance = useCallback(async () => {
    setAttLoading(true)
    try {
      const res = await adminAPI.attendanceOverview({ date: attDate })
      const data = res.data
      if (data?.ok) {
        setAttRows(data.items || [])
      }
    } catch {
      /* silently fail */
    } finally {
      setAttLoading(false)
    }
  }, [attDate])

  // ── Fetch meetings ──
  const loadMeetings = useCallback(async () => {
    setMeetLoading(true)
    try {
      const res = await adminAPI.meetingsOverview({ date: meetDate })
      const data = res.data
      if (data?.ok) {
        setMeetings(data.items || [])
      }
    } catch {
      /* silently fail */
    } finally {
      setMeetLoading(false)
    }
  }, [meetDate])

  // ── Fetch daily reports ──
  const loadDaily = useCallback(async () => {
    setDailyLoading(true)
    try {
      const res = await adminAPI.dailyReportsOverview({ report_date: dailyDate })
      const data = res.data
      if (data?.ok) {
        setDailyRows(data.items || [])
      }
    } catch {
      /* silently fail */
    } finally {
      setDailyLoading(false)
    }
  }, [dailyDate])

  useEffect(() => {
    loadMeetings()
  }, [loadMeetings])

  useEffect(() => {
    loadDaily()
  }, [loadDaily])

  useEffect(() => {
    loadAttendance()
  }, [loadAttendance])

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-zinc-100">Admin Overview</h2>

      {/* ── Tab nav ── */}
      <div className="flex gap-2">
        <button
          onClick={() => setTab('meetings')}
          className={classNames(
            'rounded-lg px-4 py-1.5 text-sm font-medium transition-colors',
            tab === 'meetings'
              ? 'bg-brand-600 text-white'
              : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
          )}
        >
          Meetings
        </button>
        <button
          onClick={() => setTab('daily')}
          className={classNames(
            'rounded-lg px-4 py-1.5 text-sm font-medium transition-colors',
            tab === 'daily'
              ? 'bg-brand-600 text-white'
              : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
          )}
        >
          Daily Reports
        </button>
        <button
          onClick={() => setTab('attendance')}
          className={classNames(
            'rounded-lg px-4 py-1.5 text-sm font-medium transition-colors',
            tab === 'attendance'
              ? 'bg-brand-600 text-white'
              : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
          )}
        >
          Attendance
        </button>
      </div>

      {/* ── Meetings Overview ── */}
      {tab === 'meetings' && (
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            <input
              type="date"
              value={meetDate}
              onChange={(e) => setMeetDate(e.target.value)}
              className="input-field"
            />
            <button
              onClick={loadMeetings}
              className="rounded-lg bg-zinc-800 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700 transition-colors"
            >
              Refresh
            </button>
          </div>

          {meetLoading ? (
            <Loader label="Loading meetings overview..." />
          ) : meetings.length === 0 ? (
            <p className="text-sm text-zinc-500 text-center py-12">
              No meetings found for this date.
            </p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-zinc-800">
              <table className="w-full text-sm border-collapse">
                <thead>
                  <tr className="bg-surface-3">
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Meeting
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Owner
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Time
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Recurrence
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Portal
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Attendees
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Agenda Status
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Notes Status
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {meetings.map((row, i) => (
                    <tr
                      key={i}
                      className={classNames(
                        'border-t border-zinc-800/50',
                        ROW_BG[row.rowColor] || ''
                      )}
                    >
                      <td className="px-3 py-2 text-zinc-200 font-medium">{row.title}</td>
                      <td className="px-3 py-2 text-zinc-300">{row.owner}</td>
                      <td className="px-3 py-2 text-zinc-300">{row.time}</td>
                      <td className="px-3 py-2 text-zinc-400 capitalize">{row.recurrence}</td>
                      <td className="px-3 py-2 text-zinc-400">{row.portal || '—'}</td>
                      <td className="px-3 py-2 text-zinc-400">
                        {row.attendees?.length
                          ? row.attendees.join(', ')
                          : '—'}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-2">
                          {agendaBadge(row.agendaStatus)}
                          <span className="text-[10px] text-zinc-500">
                            {row.agendaFilled}/{row.agendaTotal}
                          </span>
                        </div>
                      </td>
                      <td className="px-3 py-2">{notesBadge(row.notesStatus)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ── Daily Reports Overview ── */}
      {tab === 'daily' && (
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            <input
              type="date"
              value={dailyDate}
              onChange={(e) => setDailyDate(e.target.value)}
              className="input-field"
            />
            <button
              onClick={loadDaily}
              className="rounded-lg bg-zinc-800 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700 transition-colors"
            >
              Refresh
            </button>
          </div>

          {dailyLoading ? (
            <Loader label="Loading daily reports overview..." />
          ) : dailyRows.length === 0 ? (
            <p className="text-sm text-zinc-500 text-center py-12">
              No daily report data for this date.
            </p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-zinc-800">
              <table className="w-full text-sm border-collapse">
                <thead>
                  <tr className="bg-surface-3">
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      User
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Role
                    </th>
                    <th className="px-3 py-2.5 text-center text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Filled
                    </th>
                    <th className="px-3 py-2.5 text-center text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Total
                    </th>
                    <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                      Status
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {dailyRows.map((row, i) => (
                    <tr key={i} className="border-t border-zinc-800/50 hover:bg-surface-1/50">
                      <td className="px-3 py-2 text-zinc-200 font-medium">{row.userName}</td>
                      <td className="px-3 py-2 text-zinc-400">{row.role}</td>
                      <td className="px-3 py-2 text-center text-zinc-300">{row.filledCount}</td>
                      <td className="px-3 py-2 text-center text-zinc-300">{row.totalFields}</td>
                      <td className="px-3 py-2">{dailyBadge(row.status)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ── Attendance Overview ── */}
      {tab === 'attendance' && (
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            <input
              type="date"
              value={attDate}
              onChange={(e) => setAttDate(e.target.value)}
              className="input-field"
            />
            <button
              onClick={loadAttendance}
              className="rounded-lg bg-zinc-800 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700 transition-colors"
            >
              Refresh
            </button>
          </div>

          {attLoading ? (
            <Loader label="Loading attendance overview..." />
          ) : attRows.length === 0 ? (
            <p className="text-sm text-zinc-500 text-center py-12">
              No meetings found for this date.
            </p>
          ) : (
            <div className="space-y-4">
              {attRows.map((row) => (
                <div key={row.meetingKey} className="rounded-lg border border-zinc-800 overflow-hidden">
                  {/* Meeting header row */}
                  <div className="flex items-center justify-between px-4 py-3 bg-surface-3">
                    <div className="flex items-center gap-3">
                      <span className="text-sm font-medium text-zinc-200">{row.title}</span>
                      <span className="text-xs text-zinc-500">{row.time}</span>
                      <span className="text-xs text-zinc-600">by {row.owner}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-zinc-400">
                        {row.present}/{row.total}
                      </span>
                      {rateBadge(row.rate, row.hasData)}
                    </div>
                  </div>
                  {/* Attendees table */}
                  <table className="w-full text-sm border-collapse">
                    <thead>
                      <tr className="bg-zinc-900/50">
                        <th className="px-4 py-2 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                          Attendee
                        </th>
                        <th className="px-4 py-2 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                          Status
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {row.attendees.map((a) => (
                        <tr key={a.userId} className="border-t border-zinc-800/50 hover:bg-surface-1/50">
                          <td className="px-4 py-2 text-zinc-300">{a.userName}</td>
                          <td className="px-4 py-2">{attendanceStatusBadge(a.status)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
