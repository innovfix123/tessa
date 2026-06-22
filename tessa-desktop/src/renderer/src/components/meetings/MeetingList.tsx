import { useMemo } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { Plus, Pencil, Trash2 } from 'lucide-react'
import { formatDate, addDays, classNames } from '@/lib/utils'

interface Props {
  meetings: ExpandedMeeting[]
  selected: ExpandedMeeting | null
  weekStart: Date
  onSelect: (m: ExpandedMeeting) => void
  onEdit: (m: ExpandedMeeting) => void
  onDelete: (m: ExpandedMeeting) => void
  onAdd: () => void
}

const DAY_ORDER: Record<string, number> = {
  Monday: 0, Tuesday: 1, Wednesday: 2, Thursday: 3, Friday: 4
}
const DAY_SHORT: Record<string, string> = {
  Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri'
}

function parseTime(t: string): number {
  const m = t.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i)
  if (!m) return 0
  let h = parseInt(m[1])
  const min = parseInt(m[2])
  if (m[3].toUpperCase() === 'PM' && h !== 12) h += 12
  if (m[3].toUpperCase() === 'AM' && h === 12) h = 0
  return h * 60 + min
}

export default function MeetingList({
  meetings, selected, weekStart, onSelect, onEdit, onDelete, onAdd
}: Props): JSX.Element {
  const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' })
  const isThisWeek = (() => {
    const now = new Date()
    const mon = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7))
    return Math.abs(weekStart.getTime() - mon.getTime()) < 86400000
  })()

  function dateForDay(dayName: string): string {
    const offset = DAY_ORDER[dayName] ?? 0
    return formatDate(addDays(weekStart, offset))
  }

  function isSkipped(m: ExpandedMeeting): boolean {
    if (!m.skipDates?.length) return false
    return m.skipDates.includes(dateForDay(m.day))
  }

  const { dailyGroups, others } = useMemo(() => {
    const dMap = new Map<number, ExpandedMeeting[]>()
    const rest: ExpandedMeeting[] = []
    meetings.forEach((m) => {
      if (m.recurrence === 'daily_weekdays') {
        const arr = dMap.get(m.dbId) || []
        arr.push(m)
        dMap.set(m.dbId, arr)
      } else if (!isSkipped(m)) {
        rest.push(m)
      }
    })
    return { dailyGroups: Array.from(dMap.entries()), others: rest }
  }, [meetings, weekStart])

  type ListItem =
    | { type: 'daily'; dbId: number; instances: ExpandedMeeting[]; sortDay: number; sortTime: number }
    | { type: 'single'; meeting: ExpandedMeeting; sortDay: number; sortTime: number }

  const sorted = useMemo(() => {
    const items: ListItem[] = []
    dailyGroups.forEach(([dbId, instances]) => {
      items.push({ type: 'daily', dbId, instances, sortDay: 0, sortTime: parseTime(instances[0].time) })
    })
    others.forEach((m) => {
      items.push({ type: 'single', meeting: m, sortDay: DAY_ORDER[m.day] ?? 5, sortTime: parseTime(m.time) })
    })
    items.sort((a, b) => a.sortDay - b.sortDay || a.sortTime - b.sortTime)
    return items
  }, [dailyGroups, others])

  if (meetings.length === 0) {
    return (
      <aside className="w-72 shrink-0 border-r border-zinc-800 flex flex-col items-center justify-center p-4 text-center">
        <p className="text-sm text-zinc-500 mb-3">No meetings yet.</p>
        <button onClick={onAdd} className="btn-primary">
          <Plus className="h-4 w-4" /> Add Meeting
        </button>
      </aside>
    )
  }

  return (
    <aside className="w-72 shrink-0 border-r border-zinc-800 flex flex-col overflow-hidden">
      <div className="px-3 py-2 border-b border-zinc-800/50 flex items-center justify-between">
        <span className="text-xs font-medium text-zinc-500 uppercase tracking-wider">Scheduled Meetings</span>
      </div>

      <div className="flex-1 overflow-y-auto p-2 space-y-1.5">
        {sorted.map((item) => {
          if (item.type === 'daily') {
            const first = item.instances[0]
            const isSelected = item.instances.some((inst) => inst.id === selected?.id)
            const attendeeStr = (first.attendees || []).join(' · ')

            return (
              <div
                key={`daily-${item.dbId}`}
                className={classNames(
                  'rounded-lg border p-3 cursor-pointer transition-colors',
                  isSelected ? 'border-brand-600/50 bg-brand-600/5' : 'border-zinc-800 hover:border-zinc-700 bg-surface-3'
                )}
                onClick={() => {
                  const today = item.instances.find((i) => i.day === todayName && !isSkipped(i))
                  const fallback = item.instances.find((i) => !isSkipped(i))
                  onSelect(today || fallback || item.instances[0])
                }}
              >
                <div className="flex items-center justify-between mb-1">
                  <span className="text-sm font-medium text-zinc-200 truncate">{first.title}</span>
                  {first.canEdit && (
                    <div className="flex gap-0.5 shrink-0">
                      <button onClick={(e) => { e.stopPropagation(); onEdit(first) }} className="p-1 text-zinc-600 hover:text-zinc-300 rounded"><Pencil className="h-3 w-3" /></button>
                      <button onClick={(e) => { e.stopPropagation(); onDelete(first) }} className="p-1 text-zinc-600 hover:text-red-400 rounded"><Trash2 className="h-3 w-3" /></button>
                    </div>
                  )}
                </div>
                <div className="text-xs text-zinc-500 mb-1">
                  {first.time} · {first.owner} + Team
                </div>
                {attendeeStr && (
                  <div className="text-[11px] text-zinc-500 mb-2 truncate">{attendeeStr}</div>
                )}
                {/* Day badges */}
                <div className="flex gap-1 mb-1">
                  {item.instances.map((inst) => {
                    const skipped = isSkipped(inst)
                    const isToday = isThisWeek && inst.day === todayName
                    const active = inst.id === selected?.id
                    return (
                      <button
                        key={inst.id}
                        onClick={(e) => { e.stopPropagation(); if (!skipped) onSelect(inst) }}
                        disabled={skipped}
                        className={classNames(
                          'px-2 py-0.5 rounded text-[11px] font-medium transition-colors',
                          skipped ? 'bg-zinc-800/50 text-zinc-600 line-through cursor-not-allowed'
                            : active ? 'bg-brand-600 text-white'
                            : isToday ? 'bg-brand-600/20 text-brand-400 hover:bg-brand-600/30'
                            : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
                        )}
                      >
                        {DAY_SHORT[inst.day] || inst.day.slice(0, 3)}
                      </button>
                    )
                  })}
                </div>
                <div className="text-[10px] text-zinc-600">{first.recurringLabel}</div>
                {first.isGuest && !first.canEdit && (
                  <div className="mt-1 text-[10px] text-zinc-600">From {(first.portal || '').toUpperCase()}</div>
                )}
              </div>
            )
          }

          const m = item.meeting
          const isActive = m.id === selected?.id

          return (
            <div
              key={m.id}
              onClick={() => onSelect(m)}
              className={classNames(
                'rounded-lg border p-3 cursor-pointer transition-colors',
                isActive ? 'border-brand-600/50 bg-brand-600/5' : 'border-zinc-800 hover:border-zinc-700 bg-surface-3'
              )}
            >
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-zinc-200 truncate">{m.title}</span>
                {m.canEdit ? (
                  <div className="flex gap-0.5 shrink-0">
                    <button onClick={(e) => { e.stopPropagation(); onEdit(m) }} className="p-1 text-zinc-600 hover:text-zinc-300 rounded"><Pencil className="h-3 w-3" /></button>
                    <button onClick={(e) => { e.stopPropagation(); onDelete(m) }} className="p-1 text-zinc-600 hover:text-red-400 rounded"><Trash2 className="h-3 w-3" /></button>
                  </div>
                ) : m.isGuest ? (
                  <span className="text-[10px] text-zinc-600">From {(m.portal || '').toUpperCase()}</span>
                ) : null}
              </div>
              <div className="text-xs text-zinc-500 mt-1">
                <span className="inline-block bg-zinc-800 rounded px-1.5 py-0.5 mr-1 text-zinc-400">
                  {DAY_SHORT[m.day] || m.day}
                </span>
                {m.time} · {m.owner}
              </div>
              <div className="text-[10px] text-zinc-600 mt-1">{m.recurringLabel}</div>
            </div>
          )
        })}
      </div>

      {/* Add Meeting button — always visible at bottom of sidebar */}
      <div className="p-3 border-t border-zinc-800">
        <button onClick={onAdd} className="btn-primary w-full text-sm">
          <Plus className="h-4 w-4" /> Add Meeting
        </button>
      </div>
    </aside>
  )
}
