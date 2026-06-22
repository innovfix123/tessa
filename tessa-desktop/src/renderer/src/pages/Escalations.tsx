import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { escalationsAPI } from '@/api/client'
import { Loader, EmptyState } from '@/components/ui'
import type { Escalation } from '@/lib/types'
import { classNames, prettyDateTime } from '@/lib/utils'
import toast from 'react-hot-toast'
import { ThumbsUp } from 'lucide-react'

// ── Severity color map (matches portal.js) ──

const SEVERITY_COLORS: Record<string, string> = {
  P0: '#ef4444',
  P1: '#f97316',
  P2: '#eab308',
  P3: '#3b82f6'
}

// ── Status labels ──

const STATUS_LABELS: Record<string, { label: string; bg: string; text: string }> = {
  open: { label: 'Open', bg: '#dc2626', text: '#fff' },
  in_progress: { label: 'In Progress', bg: '#f59e0b', text: '#000' },
  escalated: { label: 'Escalated', bg: '#7c3aed', text: '#fff' },
  resolved: { label: 'Resolved', bg: '#22c55e', text: '#fff' },
  closed: { label: 'Closed', bg: '#6b7280', text: '#fff' }
}

// ── Category formatting (snake_case to Title Case) ──

function formatCategory(cat: string): string {
  if (!cat) return ''
  return cat
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
}

// ── Escalation categories for the create form ──

const CATEGORIES = [
  'app_crash',
  'bug',
  'payment',
  'creator',
  'user_complaint',
  'other'
]

const SEVERITIES = ['P0', 'P1', 'P2', 'P3'] as const

export default function Escalations(): JSX.Element {
  const { roleName } = useAuth()

  // Role-based config (mirrors portal getEscalationsConfig)
  const roleSlug = roleName.toLowerCase().replace(/\s+/g, '_')
  const canCreate = roleSlug === 'ops' || roleSlug === 'operations'
  const canEscalateToCeo = roleSlug === 'coo'

  const [escalations, setEscalations] = useState<Escalation[]>([])
  const [loading, setLoading] = useState(true)

  // Create form state
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [severity, setSeverity] = useState<string>('P1')
  const [category, setCategory] = useState<string>('bug')
  const [creating, setCreating] = useState(false)

  // Resolution notes state (keyed by escalation id)
  const [notes, setNotes] = useState<Record<number, string>>({})
  const [savingNote, setSavingNote] = useState<number | null>(null)
  const [updatingStatus, setUpdatingStatus] = useState<number | null>(null)

  // ── Fetch escalations ──

  const fetchEscalations = useCallback(async () => {
    try {
      const res = await escalationsAPI.list()
      const d = res.data || {}
      const items = d.items || d.escalations || (Array.isArray(d) ? d : [])
      setEscalations(items)
    } catch {
      // API may 403 for roles without access — show empty, don't error
      setEscalations([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchEscalations()
  }, [fetchEscalations])

  // ── Create escalation ──

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!title.trim()) {
      toast.error('Title is required')
      return
    }
    setCreating(true)
    try {
      await escalationsAPI.create({
        action: 'create',
        title: title.trim(),
        description: description.trim(),
        severity,
        category
      })
      toast.success('Escalation created')
      setTitle('')
      setDescription('')
      setSeverity('P1')
      setCategory('bug')
      await fetchEscalations()
    } catch {
      toast.error('Failed to create escalation')
    } finally {
      setCreating(false)
    }
  }

  // ── Update status ──

  const handleStatusUpdate = async (id: number, status: string) => {
    setUpdatingStatus(id)
    try {
      await escalationsAPI.create({
        action: 'update_status',
        id,
        status
      })
      toast.success(`Status updated to ${STATUS_LABELS[status]?.label ?? status}`)
      await fetchEscalations()
    } catch {
      toast.error('Failed to update status')
    } finally {
      setUpdatingStatus(null)
    }
  }

  // ── Add note ──

  const handleAddNote = async (id: number) => {
    const note = (notes[id] ?? '').trim()
    if (!note) {
      toast.error('Please enter a note')
      return
    }
    setSavingNote(id)
    try {
      await escalationsAPI.create({
        action: 'add_note',
        id,
        note
      })
      toast.success('Note saved')
      setNotes((prev) => ({ ...prev, [id]: '' }))
      await fetchEscalations()
    } catch {
      toast.error('Failed to save note')
    } finally {
      setSavingNote(null)
    }
  }

  // ── Severity badge ──

  function SeverityBadge({ sev }: { sev: string }) {
    const color = SEVERITY_COLORS[sev] ?? '#6b7280'
    return (
      <span
        className="inline-block px-2 py-0.5 rounded text-xs font-bold"
        style={{ backgroundColor: color, color: '#fff' }}
      >
        {sev}
      </span>
    )
  }

  // ── Status badge ──

  function StatusBadge({ status }: { status: string }) {
    const info = STATUS_LABELS[status] ?? { label: status, bg: '#6b7280', text: '#fff' }
    return (
      <span
        className="inline-block px-2 py-0.5 rounded text-xs font-semibold"
        style={{ backgroundColor: info.bg, color: info.text }}
      >
        {info.label}
      </span>
    )
  }

  // ── Loading ──

  if (loading) return <Loader />

  // ── canCreate mode (OPS) — two-column: form + list ──

  if (canCreate) {
    return (
      <div className="space-y-6">
        <h2 className="page-title">Escalations</h2>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* LEFT — Create Form */}
          <div className="card">
            <h3 className="text-lg font-semibold text-zinc-100 mb-4">
              Raise Escalation
            </h3>
            <form onSubmit={handleCreate} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-zinc-400 mb-1">
                  Title
                </label>
                <input
                  type="text"
                  className="input-field"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Escalation title"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-zinc-400 mb-1">
                  Description
                </label>
                <textarea
                  className="textarea-field"
                  rows={3}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Describe the issue..."
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-zinc-400 mb-1">
                    Severity
                  </label>
                  <select
                    className="select-field"
                    value={severity}
                    onChange={(e) => setSeverity(e.target.value)}
                  >
                    {SEVERITIES.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-zinc-400 mb-1">
                    Category
                  </label>
                  <select
                    className="select-field"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                  >
                    {CATEGORIES.map((c) => (
                      <option key={c} value={c}>
                        {formatCategory(c)}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <button
                type="submit"
                className="btn-primary"
                disabled={creating}
              >
                {creating ? 'Submitting...' : 'Submit Escalation'}
              </button>
            </form>
          </div>

          {/* RIGHT — Active Escalations List */}
          <div className="space-y-4">
            <h3 className="text-lg font-semibold text-zinc-100">
              Active Escalations
            </h3>
            {escalations.length === 0 ? (
              <EmptyState
                icon={ThumbsUp}
                title="No active escalations"
                description="All clear! No escalations have been raised."
              />
            ) : (
              escalations.map((esc) => (
                <div key={esc.id} className="card">
                  <div className="flex items-start justify-between gap-3 mb-2">
                    <h4 className="text-sm font-semibold text-zinc-100">
                      {esc.title}
                    </h4>
                    <div className="flex items-center gap-2 shrink-0">
                      <SeverityBadge sev={esc.severity} />
                      <StatusBadge status={esc.status} />
                    </div>
                  </div>
                  {esc.description && (
                    <p className="text-sm text-zinc-400 mb-2">{esc.description}</p>
                  )}
                  <div className="flex flex-wrap gap-3 text-xs text-zinc-500">
                    <span>Category: {formatCategory(esc.category)}</span>
                    {esc.raised_by_name && (
                      <span>Raised by: {esc.raised_by_name}</span>
                    )}
                    <span>{prettyDateTime(esc.created_at)}</span>
                  </div>
                  {esc.resolution_note && (
                    <p className="mt-2 text-xs text-zinc-400 italic">
                      Note: {esc.resolution_note}
                    </p>
                  )}
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    )
  }

  // ── Non-creator mode (COO / CEO / others) — escalation cards with actions ──

  return (
    <div className="space-y-6">
      <h2 className="page-title">Escalations</h2>

      {escalations.length === 0 ? (
        <EmptyState
          icon={ThumbsUp}
          title="No escalations assigned"
          description="No escalations require your attention right now."
        />
      ) : (
        <div className="space-y-4">
          {escalations.map((esc) => (
            <div key={esc.id} className="card">
              {/* Header */}
              <div className="flex items-start justify-between gap-3 mb-2">
                <h4 className="text-sm font-semibold text-zinc-100">
                  {esc.title}
                </h4>
                <div className="flex items-center gap-2 shrink-0">
                  <SeverityBadge sev={esc.severity} />
                  <StatusBadge status={esc.status} />
                </div>
              </div>

              {esc.description && (
                <p className="text-sm text-zinc-400 mb-2">{esc.description}</p>
              )}

              <div className="flex flex-wrap gap-3 text-xs text-zinc-500 mb-3">
                <span>Category: {formatCategory(esc.category)}</span>
                {esc.raised_by_name && (
                  <span>Raised by: {esc.raised_by_name}</span>
                )}
                <span>{prettyDateTime(esc.created_at)}</span>
              </div>

              {esc.resolution_note && (
                <p className="mb-3 text-xs text-zinc-400 italic">
                  Note: {esc.resolution_note}
                </p>
              )}

              {/* Status update buttons */}
              {esc.status !== 'resolved' && esc.status !== 'closed' && (
                <div className="flex flex-wrap gap-2 mb-3">
                  {esc.status !== 'in_progress' && (
                    <button
                      className="btn-secondary"
                      disabled={updatingStatus === esc.id}
                      onClick={() => handleStatusUpdate(esc.id, 'in_progress')}
                    >
                      In Progress
                    </button>
                  )}
                  <button
                    className="btn-primary"
                    disabled={updatingStatus === esc.id}
                    onClick={() => handleStatusUpdate(esc.id, 'resolved')}
                  >
                    Resolved
                  </button>
                  {canEscalateToCeo && esc.status !== 'escalated' && (
                    <button
                      className="btn-danger"
                      disabled={updatingStatus === esc.id}
                      onClick={() => handleStatusUpdate(esc.id, 'escalated')}
                    >
                      Escalate to CEO
                    </button>
                  )}
                </div>
              )}

              {/* Resolution note textarea */}
              <div className="flex gap-2">
                <textarea
                  className="textarea-field flex-1"
                  rows={2}
                  placeholder="Add a resolution note..."
                  value={notes[esc.id] ?? ''}
                  onChange={(e) =>
                    setNotes((prev) => ({ ...prev, [esc.id]: e.target.value }))
                  }
                />
                <button
                  className="btn-ghost self-end"
                  disabled={savingNote === esc.id}
                  onClick={() => handleAddNote(esc.id)}
                >
                  {savingNote === esc.id ? 'Saving...' : 'Save Note'}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
