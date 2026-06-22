import { useState, useEffect, useCallback } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { meetingsAPI } from '@/api/client'
import { Loader, Badge } from '@/components/ui'
import { Check, X, UserIcon } from 'lucide-react'
import { addDays, formatDate } from '@/lib/utils'
import toast from 'react-hot-toast'

interface AttendanceRecord {
  userId: number
  userName: string
  status: 'present' | 'absent'
  source: string
}

interface Props {
  meeting: ExpandedMeeting
  weekKey: string
}

const DAY_OFFSETS: Record<string, number> = {
  Monday: 0,
  Tuesday: 1,
  Wednesday: 2,
  Thursday: 3,
  Friday: 4
}

function occurrenceDateFromWeek(weekKey: string, day: string): string {
  const monday = new Date(weekKey + 'T00:00:00')
  const offset = DAY_OFFSETS[day] ?? 0
  return formatDate(addDays(monday, offset))
}

export default function AttendanceTab({ meeting, weekKey }: Props): JSX.Element {
  const [records, setRecords] = useState<AttendanceRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [toggling, setToggling] = useState<number | null>(null)

  const occurrenceDate = occurrenceDateFromWeek(weekKey, meeting.day)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.attendance({
        meeting_id: meeting.id,
        date: occurrenceDate
      })
      setRecords(res.data?.attendance ?? [])
    } catch {
      toast.error('Failed to load attendance')
    } finally {
      setLoading(false)
    }
  }, [meeting.id, occurrenceDate])

  useEffect(() => {
    load()
  }, [load])

  async function handleToggle(userId: number, currentStatus: string) {
    const newStatus = currentStatus === 'present' ? 'absent' : 'present'
    setToggling(userId)
    try {
      await meetingsAPI.overrideAttendance({
        meetingId: meeting.id,
        date: occurrenceDate,
        userId,
        status: newStatus
      })
      setRecords((prev) =>
        prev.map((r) => (r.userId === userId ? { ...r, status: newStatus, source: 'manual' } : r))
      )
    } catch {
      toast.error('Failed to update attendance')
    } finally {
      setToggling(null)
    }
  }

  if (loading) return <Loader />

  const presentCount = records.filter((r) => r.status === 'present').length
  const totalCount = records.length

  if (totalCount === 0) {
    return (
      <div className="text-center py-12 text-zinc-500 text-sm">
        No attendance data for {meeting.day}, {occurrenceDate}.
        <br />
        Attendance is auto-recorded from Slack Huddle AI notes.
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-zinc-300">
          {meeting.day}, {occurrenceDate}
        </h3>
        <Badge
          variant={
            presentCount === totalCount
              ? 'bg-emerald-900/50 text-emerald-400 border-emerald-700'
              : 'bg-amber-900/50 text-amber-400 border-amber-700'
          }
        >
          {presentCount}/{totalCount} present
        </Badge>
      </div>

      <div className="divide-y divide-zinc-800 border border-zinc-800 rounded-lg overflow-hidden">
        {records.map((r) => (
          <div key={r.userId} className="flex items-center justify-between px-4 py-3 bg-zinc-900">
            <div className="flex items-center gap-3">
              <UserIcon className="h-4 w-4 text-zinc-500" />
              <span className="text-sm text-zinc-200">{r.userName}</span>
              {r.source === 'manual' && (
                <span className="text-[10px] text-zinc-600">(manual)</span>
              )}
            </div>
            <div className="flex items-center gap-2">
              {r.status === 'present' ? (
                <Badge variant="bg-emerald-900/50 text-emerald-400 border-emerald-700">
                  <Check className="h-3 w-3 mr-1" />
                  Present
                </Badge>
              ) : (
                <Badge variant="bg-red-900/50 text-red-400 border-red-700">
                  <X className="h-3 w-3 mr-1" />
                  Absent
                </Badge>
              )}
              {meeting.canEdit && (
                <button
                  onClick={() => handleToggle(r.userId, r.status)}
                  disabled={toggling === r.userId}
                  className="text-xs text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-50"
                >
                  {toggling === r.userId ? '...' : 'Toggle'}
                </button>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
