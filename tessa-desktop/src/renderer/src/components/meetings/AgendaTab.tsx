import { useState, useEffect, useCallback } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { meetingsAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { Plus, X, RotateCcw, Sparkles } from 'lucide-react'
import toast from 'react-hot-toast'

interface Section {
  id: number; title: string; sortOrder: number
  points: Point[]
}
interface Point {
  id: number; question: string; answer?: string; sortOrder: number; sectionId?: number | null
}

interface Props {
  meeting: ExpandedMeeting
  weekKey: string
}

export default function AgendaTab({ meeting, weekKey }: Props): JSX.Element {
  const [sections, setSections] = useState<Section[]>([])
  const [unsectioned, setUnsectioned] = useState<Point[]>([])
  const [loading, setLoading] = useState(true)
  const [newSection, setNewSection] = useState('')
  const [pointInputs, setPointInputs] = useState<Record<number, string>>({})

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.agendaSections({
        meeting_id: meeting.id,
        week_key: weekKey
      })
      setSections(res.data?.sections || [])
      setUnsectioned(res.data?.unsectioned || [])
    } catch {
      toast.error('Failed to load agenda')
    } finally {
      setLoading(false)
    }
  }, [meeting.id, weekKey])

  useEffect(() => { load() }, [load])

  async function addSection(e: React.FormEvent) {
    e.preventDefault()
    if (!newSection.trim()) return
    try {
      await meetingsAPI.postAgendaSection({
        action: 'add_section',
        meetingId: meeting.id,
        weekKey,
        title: newSection.trim()
      })
      setNewSection('')
      await load()
    } catch {
      toast.error('Failed to add section')
    }
  }

  async function deleteSection(id: number) {
    if (!confirm('Delete this section? Discussion points will be kept as unsectioned.')) return
    try {
      await meetingsAPI.postAgendaSection({ action: 'delete_section', id })
      await load()
    } catch {
      toast.error('Failed to delete section')
    }
  }

  async function addPoint(sectionId: number) {
    const q = (pointInputs[sectionId] || '').trim()
    if (!q) return
    try {
      await meetingsAPI.postAgendaSection({ action: 'add_point', sectionId, question: q })
      setPointInputs((p) => ({ ...p, [sectionId]: '' }))
      await load()
    } catch {
      toast.error('Failed to add point')
    }
  }

  async function deletePoint(id: number) {
    if (!confirm('Remove this discussion point?')) return
    try {
      await meetingsAPI.postAgendaSection({ action: 'delete_point', id })
      await load()
    } catch {
      toast.error('Failed to delete point')
    }
  }

  async function resetFromTemplate() {
    if (!meeting.agendaTemplateId) return
    if (!confirm('This will erase the current agenda for this date and apply the template. Continue?')) return
    try {
      await meetingsAPI.postAgendaSection({
        action: 'clear_agenda',
        meetingId: meeting.id,
        weekKey
      })
      await load()
      toast.success('Agenda reset from template')
    } catch {
      toast.error('Failed to reset agenda')
    }
  }

  if (loading) return <Loader size="sm" label="Loading agenda..." />

  const isEmpty = sections.length === 0 && unsectioned.length === 0

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h4 className="section-title">Agenda</h4>
          <p className="muted">Responses are auto-saved when you save meeting notes</p>
        </div>
        {meeting.agendaTemplateId && (
          <button onClick={resetFromTemplate} className="btn-ghost text-xs">
            <RotateCcw className="h-3.5 w-3.5" /> Reset from Template
          </button>
        )}
      </div>

      {isEmpty ? (
        <div className="text-center py-8 text-sm text-zinc-500">
          No agenda sections yet. Add a section below to get started.
        </div>
      ) : (
        <div className="space-y-4">
          {sections.map((s) => (
            <div key={s.id} className="card">
              <div className="flex items-center justify-between mb-3">
                <h5 className="text-sm font-semibold text-zinc-200">{s.title}</h5>
                <button
                  onClick={() => deleteSection(s.id)}
                  className="p-1 text-zinc-600 hover:text-red-400 rounded"
                  title="Delete section"
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </div>

              <div className="space-y-2">
                {s.points.map((p) => (
                  <div key={p.id} className="rounded-md bg-surface-0 border border-zinc-800/50 p-3">
                    <div className="flex items-start justify-between gap-2">
                      <span className="text-sm text-zinc-300">{p.question}</span>
                      <button
                        onClick={() => deletePoint(p.id)}
                        className="p-0.5 text-zinc-600 hover:text-red-400 rounded shrink-0"
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </div>
                    {p.answer?.trim() ? (
                      <div className="mt-2 text-xs text-zinc-400 bg-surface-3 rounded-md p-2 flex items-start gap-1.5">
                        <Sparkles className="h-3 w-3 text-brand-400 mt-0.5 shrink-0" />
                        <span>{p.answer}</span>
                      </div>
                    ) : (
                      <p className="mt-2 text-xs text-zinc-600 italic">
                        Answers auto-fill when you save meeting notes
                      </p>
                    )}
                  </div>
                ))}
              </div>

              {/* Add point form */}
              <div className="flex gap-2 mt-3">
                <input
                  type="text"
                  value={pointInputs[s.id] || ''}
                  onChange={(e) => setPointInputs((p) => ({ ...p, [s.id]: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPoint(s.id) } }}
                  placeholder="Add discussion point..."
                  className="input-field text-xs flex-1"
                />
                <button onClick={() => addPoint(s.id)} className="btn-secondary text-xs px-2.5">
                  <Plus className="h-3 w-3" />
                </button>
              </div>
            </div>
          ))}

          {unsectioned.length > 0 && (
            <div className="card">
              <h5 className="text-sm font-semibold text-zinc-400 mb-3">Other Discussion Points</h5>
              <div className="space-y-2">
                {unsectioned.map((p) => (
                  <div key={p.id} className="rounded-md bg-surface-0 border border-zinc-800/50 p-3">
                    <div className="flex items-start justify-between gap-2">
                      <span className="text-sm text-zinc-300">{p.question}</span>
                      <button onClick={() => deletePoint(p.id)} className="p-0.5 text-zinc-600 hover:text-red-400 rounded shrink-0">
                        <X className="h-3 w-3" />
                      </button>
                    </div>
                    {p.answer?.trim() ? (
                      <div className="mt-2 text-xs text-zinc-400 bg-surface-3 rounded-md p-2 flex items-start gap-1.5">
                        <Sparkles className="h-3 w-3 text-brand-400 mt-0.5 shrink-0" />
                        <span>{p.answer}</span>
                      </div>
                    ) : (
                      <p className="mt-2 text-xs text-zinc-600 italic">Answers auto-fill when you save meeting notes</p>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Add section form */}
      <form onSubmit={addSection} className="flex gap-2">
        <input
          type="text"
          value={newSection}
          onChange={(e) => setNewSection(e.target.value)}
          placeholder="New section title..."
          className="input-field flex-1"
          required
        />
        <button type="submit" className="btn-primary text-sm">
          <Plus className="h-4 w-4" /> Section
        </button>
      </form>
    </div>
  )
}
