import { useState, useEffect, useCallback } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { meetingsAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { Save, Loader2, Sparkles } from 'lucide-react'
import toast from 'react-hot-toast'

interface Props {
  meeting: ExpandedMeeting
  weekKey: string
  onSaved: () => void
}

export default function NotesTab({ meeting, weekKey, onSaved }: Props): JSX.Element {
  const [content, setContent] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [autoFilling, setAutoFilling] = useState(false)
  const [saveStatus, setSaveStatus] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.notes({
        meeting_id: meeting.id,
        week_key: weekKey
      })
      setContent(res.data?.note || '')
    } catch {
      toast.error('Failed to load notes')
    } finally {
      setLoading(false)
    }
  }, [meeting.id, weekKey])

  useEffect(() => { load() }, [load])

  async function handleSave() {
    setSaving(true)
    setSaveStatus('')
    try {
      await meetingsAPI.saveNote({
        action: 'save',
        meetingId: meeting.id,
        weekKey,
        content
      })
      setSaveStatus('Saved successfully')
      setTimeout(() => setSaveStatus(''), 2000)

      // AI auto-fill agenda if notes are non-empty
      if (content.trim()) {
        setAutoFilling(true)
        try {
          await meetingsAPI.postAgendaSection({
            action: 'auto_fill',
            meetingId: meeting.id,
            weekKey
          })
          onSaved()
          toast.success('Agenda answers generated from notes')
        } catch {
          // auto-fill is best-effort
        } finally {
          setAutoFilling(false)
        }
      }
    } catch {
      toast.error('Failed to save notes')
      setSaveStatus('Failed to save')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Loader size="sm" label="Loading notes..." />

  return (
    <div className="space-y-4">
      <div>
        <h4 className="section-title">Minutes of Meeting</h4>
        <p className="muted">Document key discussions, decisions, and outcomes</p>
      </div>

      <textarea
        value={content}
        onChange={(e) => setContent(e.target.value)}
        placeholder="Write meeting minutes here..."
        className="textarea-field min-h-[300px] font-mono text-[13px]"
        rows={12}
      />

      <div className="flex items-center gap-3">
        <button
          onClick={handleSave}
          disabled={saving}
          className="btn-primary"
        >
          {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          Save Minutes
        </button>

        {autoFilling && (
          <span className="flex items-center gap-1.5 text-xs text-brand-400">
            <Sparkles className="h-3.5 w-3.5 animate-pulse" />
            Generating agenda answers from notes...
          </span>
        )}

        {saveStatus && (
          <span className="text-xs text-emerald-400">{saveStatus}</span>
        )}
      </div>
    </div>
  )
}
