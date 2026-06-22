import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { meetingsAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { classNames, addDays, startOfWeek, formatDate } from '@/lib/utils'
import { Plus, Search, ChevronLeft, ChevronRight } from 'lucide-react'
import toast from 'react-hot-toast'

interface Meeting {
  id: string; title: string; owner: string; time: string; day: string
  recurrence: string; portal: string
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
const DAYS_SHORT = ['MON', 'TUE', 'WED', 'THU', 'FRI']

function dayNameForDate(d: Date): string {
  return d.toLocaleDateString('en-US', { weekday: 'long' })
}
function isSameDate(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}
function isWeekend(d: Date): boolean { return d.getDay() === 0 || d.getDay() === 6 }
function nextBusinessDay(from: Date, dir: number): Date {
  let d = new Date(from)
  const step = dir >= 0 ? 1 : -1
  do { d = addDays(d, step) } while (isWeekend(d))
  return d
}
function normalizeWeekday(d: Date): Date {
  const out = new Date(d)
  if (out.getDay() === 6) return addDays(out, 2)
  if (out.getDay() === 0) return addDays(out, 1)
  return out
}

function parseTimeMinutes(t: string): number {
  const m = t.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i)
  if (!m) return -1
  let h = parseInt(m[1])
  const mins = parseInt(m[2])
  if (m[3].toUpperCase() === 'PM' && h !== 12) h += 12
  if (m[3].toUpperCase() === 'AM' && h === 12) h = 0
  return h * 60 + mins
}

function generateTimeSlots(): string[] {
  const slots: string[] = []
  for (let h = 0; h <= 23; h++) {
    for (const min of ['00', '30']) {
      const suffix = h >= 12 ? 'PM' : 'AM'
      const h12 = h % 12 === 0 ? 12 : h % 12
      slots.push(`${String(h12).padStart(2, '0')}:${min} ${suffix}`)
    }
  }
  return slots
}

const TIME_SLOTS = generateTimeSlots()

function fmtDateKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default function Calendar(): JSX.Element {
  const { user } = useAuth()
  const navigate = useNavigate()
  const gridRef = useRef<HTMLDivElement>(null)

  const [mode, setMode] = useState<'day' | 'week' | 'month'>('week')
  const [date, setDate] = useState(() => normalizeWeekday(new Date()))
  const [search, setSearch] = useState('')
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [loading, setLoading] = useState(true)
  const [miniMonth, setMiniMonth] = useState(() => new Date())

  const portal = user?.role || 'ops'
  const now = new Date()
  const nowMinutes = now.getHours() * 60 + now.getMinutes()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.list({ portal })
      const items = res.data?.items || []
      const expanded: Meeting[] = []
      items.forEach((item: any) => {
        const base = { title: item.title, owner: item.owner, time: item.time, recurrence: item.recurrence, portal: item.portal }
        if (item.recurrence === 'daily_weekdays') {
          DAYS.forEach((day, i) => {
            expanded.push({ ...base, id: (item.meetingKey || item.id) + (i === 0 ? '' : '-' + day.slice(0, 3).toLowerCase()), day })
          })
        } else {
          expanded.push({ ...base, id: String(item.meetingKey || item.id), day: item.dayOfWeek || 'Monday' })
        }
      })
      setMeetings(expanded)
    } catch { toast.error('Failed to load meetings') }
    finally { setLoading(false) }
  }, [portal])

  useEffect(() => { load() }, [load])

  // Auto-scroll to now
  useEffect(() => {
    if (mode === 'month') return
    const el = document.getElementById('cal-now-row')
    const grid = gridRef.current
    if (el && grid) {
      requestAnimationFrame(() => {
        grid.scrollTop = Math.max(0, el.offsetTop - grid.clientHeight / 2)
      })
    }
  }, [mode, date, meetings])

  const filtered = search
    ? meetings.filter((m) => m.title.toLowerCase().includes(search.toLowerCase()) || m.owner.toLowerCase().includes(search.toLowerCase()))
    : meetings

  const ws = startOfWeek(date)
  const isThisWeek = isSameDate(ws, startOfWeek(now))
  const todayIdx = isThisWeek ? (now.getDay() + 6) % 7 : -1
  const selectedDayName = dayNameForDate(date)

  function prevNav() {
    if (mode === 'day') setDate(nextBusinessDay(date, -1))
    else if (mode === 'month') setDate(new Date(date.getFullYear(), date.getMonth() - 1, 1))
    else setDate(addDays(ws, -7))
  }
  function nextNav() {
    if (mode === 'day') setDate(nextBusinessDay(date, 1))
    else if (mode === 'month') setDate(new Date(date.getFullYear(), date.getMonth() + 1, 1))
    else setDate(addDays(ws, 7))
  }
  function goToday() {
    setDate(mode === 'month' ? new Date() : normalizeWeekday(new Date()))
  }

  const rangeLabel = mode === 'day'
    ? date.toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })
    : mode === 'month'
      ? date.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
      : `${formatDate(ws)} — ${formatDate(addDays(ws, 4))}`

  function openMeeting(id: string) {
    navigate('/meetings')
  }

  // Mini calendar
  const miniYear = miniMonth.getFullYear()
  const miniMo = miniMonth.getMonth()
  const miniStart = new Date(miniYear, miniMo, 1)
  const miniFirstDay = miniStart.getDay()
  const miniDays = new Date(miniYear, miniMo + 1, 0).getDate()
  const miniCells: Array<{ date: Date | null; day: number }> = []
  for (let i = 0; i < miniFirstDay; i++) miniCells.push({ date: null, day: 0 })
  for (let d = 1; d <= miniDays; d++) miniCells.push({ date: new Date(miniYear, miniMo, d), day: d })

  if (loading) return <Loader label="Loading calendar..." />

  return (
    <div className="flex h-[calc(100vh-5rem)] -m-5">
      {/* Sidebar */}
      <aside className="w-56 shrink-0 border-r border-zinc-800 p-3 flex flex-col gap-4 overflow-y-auto">
        <button onClick={() => navigate('/meetings')} className="btn-primary w-full text-xs">
          <Plus className="h-3.5 w-3.5" /> Create
        </button>

        {/* Mini calendar */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <button onClick={() => setMiniMonth(new Date(miniYear, miniMo - 1, 1))} className="text-zinc-500 hover:text-zinc-300"><ChevronLeft className="h-4 w-4" /></button>
            <span className="text-xs font-medium text-zinc-300">{miniMonth.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}</span>
            <button onClick={() => setMiniMonth(new Date(miniYear, miniMo + 1, 1))} className="text-zinc-500 hover:text-zinc-300"><ChevronRight className="h-4 w-4" /></button>
          </div>
          <div className="grid grid-cols-7 gap-0.5 text-center">
            {['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => (
              <div key={i} className="text-[10px] text-zinc-600 py-0.5">{d}</div>
            ))}
            {miniCells.map((cell, i) => {
              if (!cell.date) return <div key={i} />
              const isToday = isSameDate(cell.date, now)
              const isSel = isSameDate(cell.date, date)
              return (
                <button
                  key={i}
                  onClick={() => { setDate(cell.date!); setMode('day') }}
                  className={classNames(
                    'text-[11px] py-1 rounded-full transition-colors',
                    isToday && 'bg-brand-600 text-white font-bold',
                    isSel && !isToday && 'bg-zinc-700 text-zinc-100',
                    !isToday && !isSel && 'text-zinc-400 hover:bg-zinc-800'
                  )}
                >
                  {cell.day}
                </button>
              )
            })}
          </div>
        </div>

        {/* Search */}
        <div className="relative">
          <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-zinc-500" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search meetings..."
            className="input-field text-xs pl-7"
          />
        </div>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Toolbar */}
        <div className="flex items-center justify-between border-b border-zinc-800 px-4 py-2.5 shrink-0">
          <div className="flex items-center gap-2">
            <button onClick={goToday} className="text-xs bg-surface-3 border border-zinc-700 rounded px-2.5 py-1 text-zinc-300 hover:border-zinc-600">Today</button>
            <button onClick={prevNav} className="text-zinc-400 hover:text-zinc-200"><ChevronLeft className="h-4 w-4" /></button>
            <button onClick={nextNav} className="text-zinc-400 hover:text-zinc-200"><ChevronRight className="h-4 w-4" /></button>
          </div>
          <span className="text-sm font-medium text-zinc-200">{rangeLabel}</span>
          <div className="flex rounded-lg bg-surface-1 p-0.5">
            {(['day', 'week', 'month'] as const).map((m) => (
              <button key={m} onClick={() => { setMode(m); if (m !== 'month') setDate(normalizeWeekday(date)) }}
                className={classNames('rounded-md px-3 py-1 text-xs font-medium transition-colors capitalize',
                  mode === m ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-500 hover:text-zinc-300')}>
                {m}
              </button>
            ))}
          </div>
        </div>

        {/* Content */}
        <div ref={gridRef} className="flex-1 overflow-auto">
          {mode === 'month' ? (
            <MonthView date={date} meetings={filtered} now={now} onDateClick={(d) => { setDate(d); setMode('day') }} onMeetingClick={openMeeting} />
          ) : mode === 'day' ? (
            <DayView date={date} meetings={filtered} now={now} nowMinutes={nowMinutes} isToday={isSameDate(date, now)} onMeetingClick={openMeeting} />
          ) : (
            <WeekView ws={ws} meetings={filtered} now={now} nowMinutes={nowMinutes} todayIdx={todayIdx} isThisWeek={isThisWeek}
              onDayClick={(d) => { setDate(d); setMode('day') }} onMeetingClick={openMeeting} />
          )}
        </div>
      </div>
    </div>
  )
}

// ── Day View ──
function DayView({ date, meetings, now, nowMinutes, isToday, onMeetingClick }: {
  date: Date; meetings: Meeting[]; now: Date; nowMinutes: number; isToday: boolean
  onMeetingClick: (id: string) => void
}) {
  const dayName = dayNameForDate(date)
  const dayMeetings = meetings.filter((m) => m.day === dayName)

  return (
    <div className="min-h-full">
      <div className="text-center py-4">
        <div className="text-xs text-zinc-500 uppercase tracking-wider">{date.toLocaleDateString('en-IN', { weekday: 'long' })}</div>
        <div className={classNames('text-3xl font-bold mt-1', isToday ? 'text-brand-400' : 'text-zinc-200')}>
          {isToday ? <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-600 text-white">{date.getDate()}</span> : date.getDate()}
        </div>
      </div>
      <div className="border-t border-zinc-800">
        {TIME_SLOTS.map((slot, si) => {
          const slotM = parseTimeMinutes(slot)
          const nextM = si + 1 < TIME_SLOTS.length ? parseTimeMinutes(TIME_SLOTS[si + 1]) : slotM + 30
          const isHalf = slot.includes(':30')
          const isNowRow = isToday && nowMinutes >= slotM && nowMinutes < nextM
          const matches = dayMeetings.filter((m) => { const mm = parseTimeMinutes(m.time); return mm >= slotM && mm < nextM })
          const nowPct = isNowRow ? Math.max(0, Math.min(100, ((nowMinutes - slotM) / Math.max(1, nextM - slotM)) * 100)) : 0

          return (
            <div key={slot} id={isNowRow ? 'cal-now-row' : undefined}
              className={classNames('flex border-b border-zinc-800/30 min-h-[40px] relative', isNowRow && 'bg-brand-600/5')}>
              <div className={classNames('w-16 shrink-0 text-right pr-2 py-1', isHalf ? 'text-[10px] text-zinc-700' : 'text-[11px] text-zinc-500')}>
                {!isHalf && slot}
              </div>
              <div className="flex-1 relative border-l border-zinc-800/50 px-1">
                {isNowRow && <div className="absolute left-0 right-0 h-0.5 bg-red-500 z-10" style={{ top: `${nowPct}%` }} />}
                {matches.map((m) => (
                  <div key={m.id} onClick={() => onMeetingClick(m.id)}
                    className="rounded bg-brand-600/15 border border-brand-600/30 px-2 py-1 mb-0.5 cursor-pointer hover:bg-brand-600/25 transition-colors">
                    <div className="text-xs font-medium text-zinc-200 truncate">{m.title}</div>
                    <div className="text-[10px] text-zinc-500">{m.owner} · {m.time}</div>
                  </div>
                ))}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ── Week View ──
function WeekView({ ws, meetings, now, nowMinutes, todayIdx, isThisWeek, onDayClick, onMeetingClick }: {
  ws: Date; meetings: Meeting[]; now: Date; nowMinutes: number; todayIdx: number; isThisWeek: boolean
  onDayClick: (d: Date) => void; onMeetingClick: (id: string) => void
}) {
  return (
    <div className="min-h-full">
      {/* Day headers */}
      <div className="grid grid-cols-[64px_repeat(5,1fr)] border-b border-zinc-800 sticky top-0 bg-surface-0 z-10">
        <div />
        {DAYS.map((day, i) => {
          const d = addDays(ws, i)
          const isToday = isThisWeek && i === todayIdx
          return (
            <button key={day} onClick={() => onDayClick(d)}
              className={classNames('py-2 text-center border-l border-zinc-800/50 hover:bg-surface-1 transition-colors')}>
              <div className="text-[10px] text-zinc-500 uppercase">{DAYS_SHORT[i]}</div>
              <div className={classNames('text-sm font-semibold mt-0.5',
                isToday ? 'text-white' : 'text-zinc-300')}>
                {isToday ? (
                  <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-600">{d.getDate()}</span>
                ) : d.getDate()}
              </div>
            </button>
          )
        })}
      </div>

      {/* Time grid */}
      {TIME_SLOTS.map((slot, si) => {
        const slotM = parseTimeMinutes(slot)
        const nextM = si + 1 < TIME_SLOTS.length ? parseTimeMinutes(TIME_SLOTS[si + 1]) : slotM + 30
        const isHalf = slot.includes(':30')
        const isNowSlot = isThisWeek && nowMinutes >= slotM && nowMinutes < nextM
        const nowPct = isNowSlot ? Math.max(0, Math.min(100, ((nowMinutes - slotM) / Math.max(1, nextM - slotM)) * 100)) : 0

        return (
          <div key={slot} id={isNowSlot ? 'cal-now-row' : undefined}
            className="grid grid-cols-[64px_repeat(5,1fr)] border-b border-zinc-800/30 min-h-[40px]">
            <div className={classNames('text-right pr-2 py-1', isHalf ? 'text-[10px] text-zinc-700' : 'text-[11px] text-zinc-500')}>
              {!isHalf && slot}
            </div>
            {DAYS.map((day, di) => {
              const isNowCell = isNowSlot && di === todayIdx
              const matches = meetings.filter((m) => {
                const mm = parseTimeMinutes(m.time)
                return m.day === day && mm >= slotM && mm < nextM
              })
              return (
                <div key={day} className={classNames('border-l border-zinc-800/50 px-0.5 relative', isNowCell && 'bg-brand-600/5')}>
                  {isNowCell && <div className="absolute left-0 right-0 h-0.5 bg-red-500 z-10" style={{ top: `${nowPct}%` }} />}
                  {matches.map((m) => (
                    <div key={m.id} onClick={() => onMeetingClick(m.id)}
                      className="rounded bg-brand-600/15 border border-brand-600/30 px-1.5 py-0.5 mb-0.5 cursor-pointer hover:bg-brand-600/25 text-[10px]">
                      <div className="font-medium text-zinc-200 truncate">{m.title}</div>
                      <div className="text-zinc-500">{m.owner} · {m.time}</div>
                    </div>
                  ))}
                </div>
              )
            })}
          </div>
        )
      })}
    </div>
  )
}

// ── Month View ──
function MonthView({ date, meetings, now, onDateClick, onMeetingClick }: {
  date: Date; meetings: Meeting[]; now: Date
  onDateClick: (d: Date) => void; onMeetingClick: (id: string) => void
}) {
  const year = date.getFullYear(), month = date.getMonth()
  const firstDay = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

  return (
    <div className="p-2">
      <div className="grid grid-cols-7 gap-px bg-zinc-800 rounded-lg overflow-hidden">
        {weekDays.map((wd) => (
          <div key={wd} className="bg-surface-0 px-2 py-1.5 text-[10px] font-medium text-zinc-500 text-center uppercase">{wd}</div>
        ))}
        {Array.from({ length: firstDay }).map((_, i) => (
          <div key={`pad-${i}`} className="bg-surface-0 min-h-[80px]" />
        ))}
        {Array.from({ length: daysInMonth }).map((_, i) => {
          const day = i + 1
          const cellDate = new Date(year, month, day)
          const cellDayName = dayNameForDate(cellDate)
          const isToday = isSameDate(cellDate, now)
          const cellMeetings = meetings.filter((m) =>
            (m.recurrence === 'daily_weekdays' || m.recurrence === 'weekly') && m.day === cellDayName
          )

          return (
            <div key={day} onClick={() => onDateClick(cellDate)}
              className={classNames('bg-surface-0 min-h-[80px] p-1.5 cursor-pointer hover:bg-surface-1 transition-colors')}>
              <div className={classNames('text-xs font-medium mb-1',
                isToday ? 'text-white' : 'text-zinc-400')}>
                {isToday ? (
                  <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 text-[10px]">{day}</span>
                ) : day}
              </div>
              {cellMeetings.slice(0, 3).map((m) => (
                <div key={m.id} onClick={(e) => { e.stopPropagation(); onMeetingClick(m.id) }}
                  className="text-[10px] text-zinc-400 bg-brand-600/10 rounded px-1 py-0.5 mb-0.5 truncate cursor-pointer hover:bg-brand-600/20">
                  {m.title.length > 20 ? m.title.slice(0, 18) + '...' : m.title}
                </div>
              ))}
              {cellMeetings.length > 3 && (
                <div className="text-[9px] text-zinc-600">+{cellMeetings.length - 3} more</div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
