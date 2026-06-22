import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { meetingsAPI } from '@/api/client'
import MeetingList from '@/components/meetings/MeetingList'
import MeetingDetail from '@/components/meetings/MeetingDetail'
import MeetingModal from '@/components/meetings/MeetingModal'
import { Loader } from '@/components/ui'
import { startOfWeek, addDays, formatDate, weekKey } from '@/lib/utils'
import toast from 'react-hot-toast'

export interface ExpandedMeeting {
  id: string
  dbId: number
  meetingKey: string
  title: string
  owner: string
  ownerId: number | null
  day: string
  time: string
  recurrence: string
  recurringLabel: string
  attendees: string[]
  attendeeIds: number[]
  agendaTemplateId: number | null
  isGuest: boolean
  canEdit: boolean
  portal: string
  skipDates: string[]
}

function recurrenceLabel(v: string): string {
  if (v === 'daily_weekdays') return 'Daily (Mon–Fri)'
  if (v === 'weekly') return 'Recurring Weekly'
  return 'One-time'
}

function expandMeetings(items: any[]): ExpandedMeeting[] {
  const list: ExpandedMeeting[] = []
  const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
  const suffixes = ['', '-tue', '-wed', '-thu', '-fri']

  items.forEach((item) => {
    const base: Omit<ExpandedMeeting, 'id' | 'day'> = {
      dbId: Number(item.id),
      meetingKey: String(item.meetingKey || ''),
      title: String(item.title || ''),
      owner: String(item.owner || ''),
      ownerId: item.ownerId != null ? Number(item.ownerId) : null,
      time: String(item.time || ''),
      recurrence: String(item.recurrence || 'none'),
      recurringLabel: recurrenceLabel(String(item.recurrence || 'none')),
      attendees: Array.isArray(item.attendees) ? item.attendees : [],
      attendeeIds: Array.isArray(item.attendeeIds) ? item.attendeeIds.map(Number) : [],
      agendaTemplateId: item.agendaTemplateId || null,
      isGuest: Boolean(item.isGuest),
      canEdit: item.canEdit !== undefined ? Boolean(item.canEdit) : !Boolean(item.isGuest),
      portal: String(item.portal || ''),
      skipDates: Array.isArray(item.skipDates) ? item.skipDates : []
    }

    if (base.recurrence === 'daily_weekdays') {
      days.forEach((day, idx) => {
        list.push({ ...base, id: base.meetingKey + suffixes[idx], day })
      })
    } else {
      list.push({ ...base, id: base.meetingKey, day: String(item.dayOfWeek || 'Monday') })
    }
  })

  return list
}

export default function Meetings(): JSX.Element {
  const { user } = useAuth()
  const portal = user?.role || 'ops'

  const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()))
  const [meetings, setMeetings] = useState<ExpandedMeeting[]>([])
  const [selected, setSelected] = useState<ExpandedMeeting | null>(null)
  const [loading, setLoading] = useState(true)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingMeeting, setEditingMeeting] = useState<ExpandedMeeting | null>(null)

  const loadMeetings = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.list({ portal })
      const expanded = expandMeetings(res.data?.items || [])
      setMeetings(expanded)

      setSelected((prev) => {
        if (prev) {
          return expanded.find((m) => m.id === prev.id) || expanded[0] || null
        }
        const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' })
        const todayDaily = expanded.find(
          (m) => m.recurrence === 'daily_weekdays' && m.day === todayName
        )
        return todayDaily || expanded[0] || null
      })
    } catch {
      toast.error('Failed to load meetings')
    } finally {
      setLoading(false)
    }
  }, [portal])

  useEffect(() => {
    loadMeetings()
  }, [loadMeetings])

  const weekDiff = Math.round(
    (weekStart.getTime() - startOfWeek(new Date()).getTime()) / (7 * 86400000)
  )
  const weekLabel =
    weekDiff === 0 ? 'This Week'
    : weekDiff === -1 ? 'Last Week'
    : weekDiff === 1 ? 'Next Week'
    : weekDiff < 0 ? `${Math.abs(weekDiff)} Weeks Ago`
    : `${weekDiff} Weeks Ahead`

  function fmtWeekDate(d: Date): string {
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
  }
  const weekRange = `${fmtWeekDate(weekStart)} — ${fmtWeekDate(addDays(weekStart, 4))}`

  function prevWeek() {
    setWeekStart((w) => addDays(w, -7))
  }
  function nextWeek() {
    setWeekStart((w) => addDays(w, 7))
  }

  function handleEdit(m: ExpandedMeeting) {
    setEditingMeeting(m)
    setModalOpen(true)
  }

  async function handleDelete(m: ExpandedMeeting) {
    if (!confirm('Delete this meeting and linked agenda/action items?')) return
    try {
      await meetingsAPI.create({ action: 'delete', id: m.dbId })
      toast.success('Meeting deleted')
      await loadMeetings()
    } catch {
      toast.error('Failed to delete meeting')
    }
  }

  function handleAdd() {
    setEditingMeeting(null)
    setModalOpen(true)
  }

  async function handleModalSave() {
    setModalOpen(false)
    setEditingMeeting(null)
    await loadMeetings()
  }

  if (loading && meetings.length === 0) {
    return <Loader label="Loading meetings..." />
  }

  return (
    <div className="flex flex-col h-[calc(100vh-5rem)] -m-5">
      {/* Week Navigation — matching mtg-top-row > mtg-week-nav */}
      <div className="px-5 py-3">
        <div className="flex items-center justify-center gap-3">
          <button onClick={prevWeek} className="h-9 w-9 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 hover:border-zinc-600 transition-colors">
            ←
          </button>
          <div className="text-center min-w-[180px]">
            <div className="text-[17px] font-bold text-zinc-100 tracking-tight">{weekLabel}</div>
            <div className="text-[13px] text-zinc-500">{weekRange}</div>
          </div>
          <button onClick={nextWeek} className="h-9 w-9 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 hover:border-zinc-600 transition-colors">
            →
          </button>
        </div>
      </div>

      {/* Two-column layout */}
      <div className="flex flex-1 overflow-hidden">
        <MeetingList
          meetings={meetings}
          selected={selected}
          weekStart={weekStart}
          onSelect={setSelected}
          onEdit={handleEdit}
          onDelete={handleDelete}
          onAdd={handleAdd}
        />
        <MeetingDetail
          meeting={selected}
          weekStart={weekStart}
          onRefresh={loadMeetings}
        />
      </div>

      <MeetingModal
        open={modalOpen}
        meeting={editingMeeting}
        portal={portal}
        onClose={() => { setModalOpen(false); setEditingMeeting(null) }}
        onSave={handleModalSave}
      />
    </div>
  )
}
