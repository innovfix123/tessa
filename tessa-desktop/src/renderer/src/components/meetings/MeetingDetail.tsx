import { useState } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { useAuth } from '@/contexts/AuthContext'
import { Badge } from '@/components/ui'
import AgendaTab from './AgendaTab'
import ActionItemsTab from './ActionItemsTab'
import NotesTab from './NotesTab'
import PreviousMinutesTab from './PreviousMinutesTab'
import AttendanceTab from './AttendanceTab'
import { ClipboardList, CalendarDays, Star, User as UserIcon } from 'lucide-react'
import { classNames, weekKey, formatDate, addDays } from '@/lib/utils'

interface Props {
  meeting: ExpandedMeeting | null
  weekStart: Date
  onRefresh: () => Promise<void>
}

const BASE_TABS = [
  { key: 'agenda', label: 'Agenda', icon: '📄' },
  { key: 'actions', label: 'Action Items', icon: '☑' },
  { key: 'notes', label: 'Minutes of Meeting', icon: '📝' },
  { key: 'attendance', label: 'Attendance', icon: '👥' }
]

export default function MeetingDetail({ meeting, weekStart, onRefresh }: Props): JSX.Element {
  const { user } = useAuth()
  const [activeTab, setActiveTab] = useState('agenda')
  const [agendaKey, setAgendaKey] = useState(0)

  const hasPreviousMinutes = ['ceo', 'coo', 'cmo', 'cfo'].includes(user?.role || '')

  const tabs = hasPreviousMinutes
    ? [BASE_TABS[0], { key: 'lastMom', label: 'Previous Minutes', icon: '📅' }, ...BASE_TABS.slice(1)]
    : BASE_TABS

  if (!meeting) {
    return (
      <section className="flex-1 flex flex-col items-center justify-center text-center p-8">
        <ClipboardList className="h-14 w-14 text-zinc-700 mb-3" />
        <h3 className="text-base font-medium text-zinc-400">Select a Meeting</h3>
        <p className="mt-1 text-sm text-zinc-500 max-w-sm">
          Choose a meeting from the sidebar to view agenda, action items, and minutes.
        </p>
      </section>
    )
  }

  const wk = weekKey(weekStart)
  function fmtWeekDate(d: Date): string {
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
  }
  const weekRange = `${fmtWeekDate(weekStart)} — ${fmtWeekDate(addDays(weekStart, 4))}`

  function handleNotesSaved() {
    setAgendaKey((k) => k + 1)
  }

  return (
    <section className="flex-1 flex flex-col overflow-hidden">
      {/* Header — matching web portal: title, day+time, owner star, individual attendee badges, week range */}
      <div className="px-5 py-4 border-b border-zinc-800">
        <h2 className="text-lg font-semibold text-zinc-100">{meeting.title}</h2>
        <div className="flex flex-wrap items-center gap-1.5 mt-2">
          <Badge>
            <CalendarDays className="h-3 w-3 mr-1" />
            {meeting.day}, {meeting.time}
          </Badge>
          <Badge>
            <Star className="h-3 w-3 mr-1 text-amber-400" />
            {meeting.owner}
          </Badge>
          {meeting.attendees.map((name, i) => (
            <Badge key={i}>
              <UserIcon className="h-3 w-3 mr-1" />
              {name}
            </Badge>
          ))}
          <Badge className="text-zinc-500">
            <CalendarDays className="h-3 w-3 mr-1" />
            {weekRange}
          </Badge>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-zinc-800 px-5">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={classNames(
              'px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px',
              activeTab === tab.key
                ? 'border-brand-500 text-brand-400'
                : 'border-transparent text-zinc-500 hover:text-zinc-300'
            )}
          >
            <span className="mr-1.5">{tab.icon}</span>
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab content */}
      <div className="flex-1 overflow-y-auto p-5">
        {activeTab === 'agenda' && (
          <AgendaTab key={agendaKey} meeting={meeting} weekKey={wk} />
        )}
        {activeTab === 'lastMom' && (
          <PreviousMinutesTab meeting={meeting} weekKey={wk} />
        )}
        {activeTab === 'actions' && (
          <ActionItemsTab meeting={meeting} weekKey={wk} />
        )}
        {activeTab === 'notes' && (
          <NotesTab meeting={meeting} weekKey={wk} onSaved={handleNotesSaved} />
        )}
        {activeTab === 'attendance' && (
          <AttendanceTab meeting={meeting} weekKey={wk} />
        )}
      </div>
    </section>
  )
}
