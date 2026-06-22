import { useState, useEffect } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { useAuth } from '@/contexts/AuthContext'
import { meetingsAPI, templatesAPI } from '@/api/client'
import { Modal } from '@/components/ui'
import { Loader2 } from 'lucide-react'
import { classNames } from '@/lib/utils'
import toast from 'react-hot-toast'

interface Props {
  open: boolean
  meeting: ExpandedMeeting | null
  portal: string
  onClose: () => void
  onSave: () => void
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']

function timeSlots(): string[] {
  const slots: string[] = []
  for (let h = 8; h <= 20; h++) {
    for (const min of ['00', '30']) {
      const suffix = h >= 12 ? 'PM' : 'AM'
      const h12 = h % 12 === 0 ? 12 : h % 12
      slots.push(`${String(h12).padStart(2, '0')}:${min} ${suffix}`)
    }
  }
  return slots
}

const TIME_SLOTS = timeSlots()

export default function MeetingModal({ open, meeting, portal, onClose, onSave }: Props): JSX.Element {
  const { user, people } = useAuth()

  const [title, setTitle] = useState('')
  const [ownerId, setOwnerId] = useState<number>(0)
  const [recurrence, setRecurrence] = useState('weekly')
  const [day, setDay] = useState('Monday')
  const [time, setTime] = useState('10:00 AM')
  const [templateId, setTemplateId] = useState<string>('')
  const [selectedAttendees, setSelectedAttendees] = useState<Set<number>>(new Set())
  const [templates, setTemplates] = useState<any[]>([])
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!open) return

    templatesAPI.list().then((res) => {
      setTemplates(res.data?.items || res.data || [])
    }).catch(() => {})

    if (meeting) {
      setTitle(meeting.title)
      setOwnerId(meeting.ownerId || user?.id || 0)
      setRecurrence(meeting.recurrence)
      setDay(meeting.day)
      setTime(meeting.time)
      setTemplateId(meeting.agendaTemplateId ? String(meeting.agendaTemplateId) : '')
      setSelectedAttendees(new Set(meeting.attendeeIds || []))
    } else {
      setTitle('')
      setOwnerId(user?.id || 0)
      setRecurrence('weekly')
      setDay('Monday')
      setTime('10:00 AM')
      setTemplateId('')
      setSelectedAttendees(new Set())
    }
  }, [open, meeting, user])

  function toggleAttendee(id: number) {
    setSelectedAttendees((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!title.trim()) return

    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        action: meeting ? 'update' : 'add',
        portal,
        title: title.trim(),
        ownerId,
        dayOfWeek: day,
        time,
        recurrence,
        attendees: Array.from(selectedAttendees)
      }

      if (templateId) payload.agendaTemplateId = Number(templateId)
      if (meeting) payload.id = meeting.dbId

      await meetingsAPI.create(payload)
      toast.success(meeting ? 'Meeting updated' : 'Meeting created')
      onSave()
    } catch (err: any) {
      toast.error(err?.response?.data?.error || 'Failed to save meeting')
    } finally {
      setSaving(false)
    }
  }

  const allTimeSlots = TIME_SLOTS.includes(time) ? TIME_SLOTS : [time, ...TIME_SLOTS]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={meeting ? 'Edit Meeting' : 'Add Meeting'}
      width="max-w-xl"
      footer={
        <>
          <button onClick={onClose} className="btn-secondary" disabled={saving}>Cancel</button>
          <button onClick={handleSubmit} className="btn-primary" disabled={saving}>
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : (meeting ? 'Save Changes' : 'Create Meeting')}
          </button>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Title */}
        <div>
          <label className="label-text">Meeting Title</label>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="e.g. Weekly Sync"
            className="input-field"
            required
            autoFocus
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          {/* Owner */}
          <div>
            <label className="label-text">Owner</label>
            <select value={ownerId} onChange={(e) => setOwnerId(Number(e.target.value))} className="select-field">
              {(people || []).map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </div>

          {/* Recurrence */}
          <div>
            <label className="label-text">Recurrence</label>
            <select value={recurrence} onChange={(e) => setRecurrence(e.target.value)} className="select-field">
              <option value="daily_weekdays">Daily (Mon–Fri)</option>
              <option value="weekly">Recurring Weekly</option>
              <option value="none">One-time</option>
            </select>
          </div>

          {/* Day */}
          <div>
            <label className="label-text">Day</label>
            <select
              value={day}
              onChange={(e) => setDay(e.target.value)}
              className="select-field"
              disabled={recurrence === 'daily_weekdays'}
            >
              {DAYS.map((d) => <option key={d} value={d}>{d}</option>)}
            </select>
          </div>

          {/* Time */}
          <div>
            <label className="label-text">Time</label>
            <select value={time} onChange={(e) => setTime(e.target.value)} className="select-field">
              {allTimeSlots.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
        </div>

        {/* Template */}
        <div>
          <label className="label-text">Agenda Template</label>
          <select value={templateId} onChange={(e) => setTemplateId(e.target.value)} className="select-field">
            <option value="">Custom (no template)</option>
            {templates.map((t: any) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </div>

        {/* Attendees */}
        {(people || []).length > 0 && (
          <div>
            <label className="label-text">Attendees</label>
            <div className="flex flex-wrap gap-1.5 mt-1">
              {(people || []).map((p) => {
                const active = selectedAttendees.has(p.id)
                return (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => toggleAttendee(p.id)}
                    className={classNames(
                      'px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                      active
                        ? 'bg-brand-600/20 text-brand-400 border-brand-500/30'
                        : 'bg-zinc-800 text-zinc-400 border-zinc-700 hover:border-zinc-600'
                    )}
                  >
                    {p.name}
                  </button>
                )
              })}
            </div>
          </div>
        )}
      </form>
    </Modal>
  )
}
