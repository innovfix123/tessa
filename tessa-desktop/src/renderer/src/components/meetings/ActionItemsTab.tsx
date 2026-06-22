import { useState, useEffect, useCallback } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { useAuth } from '@/contexts/AuthContext'
import { meetingsAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { classNames } from '@/lib/utils'
import toast from 'react-hot-toast'

interface ActionItem {
  id: number
  meetingId: string
  weekKey: string
  task: string
  owner: string
  deadline: string | null
  status: string
  priority: string
  linkedKpi: string | null
  completedAt: string | null
  comment: string
  createdBy: number
  createdAt: string
  carriedFromWeek?: string
}

interface Props {
  meeting: ExpandedMeeting
  weekKey: string
}

const STATUSES = [
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'done', label: 'Done' },
  { value: 'blocked', label: 'Blocked' }
]
const PRIORITIES = [
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' }
]

function fmtDate(d: string | null): string {
  if (!d) return '—'
  try {
    const dt = new Date(d + 'T00:00:00')
    return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
  } catch { return d }
}

function fmtStatus(s: string): string {
  return STATUSES.find((x) => x.value === s)?.label || s
}
function fmtPriority(p: string): string {
  return PRIORITIES.find((x) => x.value === p)?.label || p
}

const statusStyle: Record<string, string> = {
  pending: 'bg-zinc-700/40 text-zinc-400',
  in_progress: 'bg-yellow-500/15 text-yellow-400',
  done: 'bg-emerald-500/15 text-emerald-400',
  blocked: 'bg-red-500/15 text-red-400'
}

const priorityDotColor: Record<string, string> = {
  high: 'bg-red-500',
  medium: 'bg-amber-500',
  low: 'bg-zinc-500'
}

export default function ActionItemsTab({ meeting, weekKey }: Props): JSX.Element {
  const { people, kpiDefinitions } = useAuth()
  const [items, setItems] = useState<ActionItem[]>([])
  const [loading, setLoading] = useState(true)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [commentOpenIds, setCommentOpenIds] = useState<Set<number>>(new Set())

  const [formTask, setFormTask] = useState('')
  const [formOwner, setFormOwner] = useState('')
  const [formDeadline, setFormDeadline] = useState('')
  const [formStatus, setFormStatus] = useState('pending')
  const [formPriority, setFormPriority] = useState('medium')
  const [formKpi, setFormKpi] = useState('')

  const kpiOptions = (kpiDefinitions || []).flatMap((g) =>
    (g.fields || []).map((f) => ({ key: f.key, label: `${g.label} — ${f.label}` }))
  )
  const ownerOptions = people?.length ? people.map((p) => p.name) : []
  const today = (() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  })()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.actionItems({ meeting_id: meeting.id, week_key: weekKey })
      const current = res.data?.items || []
      const carried = (res.data?.carriedForward || []).map((i: any) => ({
        ...i, carriedFromWeek: i.carriedFromWeek || i.weekKey
      }))
      setItems([...carried, ...current])
    } catch {
      toast.error('Failed to load action items')
    } finally {
      setLoading(false)
    }
  }, [meeting.id, weekKey])

  useEffect(() => { load() }, [load])

  function resetForm() {
    setEditingId(null)
    setFormTask('')
    setFormOwner(ownerOptions[0] || '')
    setFormDeadline('')
    setFormStatus('pending')
    setFormPriority('medium')
    setFormKpi('')
  }

  function startEdit(item: ActionItem) {
    setEditingId(item.id)
    setFormTask(item.task)
    setFormOwner(item.owner)
    setFormDeadline(item.deadline || '')
    setFormStatus(item.status)
    setFormPriority(item.priority)
    setFormKpi(item.linkedKpi || '')
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!formTask.trim() || !formOwner) return
    try {
      if (editingId) {
        await meetingsAPI.postActionItem({
          action: 'update', id: editingId, task: formTask.trim(), owner: formOwner,
          deadline: formDeadline || null, status: formStatus, priority: formPriority, linkedKpi: formKpi || null
        })
      } else {
        await meetingsAPI.postActionItem({
          action: 'add', meetingId: meeting.id, weekKey, task: formTask.trim(), owner: formOwner,
          deadline: formDeadline || null, status: formStatus, priority: formPriority, linkedKpi: formKpi || null
        })
      }
      resetForm()
      await load()
    } catch {
      toast.error('Failed to save action item')
    }
  }

  async function toggleDone(item: ActionItem) {
    try {
      await meetingsAPI.postActionItem({ action: 'update', id: item.id, status: item.status === 'done' ? 'pending' : 'done' })
      await load()
    } catch { toast.error('Failed to update') }
  }

  async function updateStatus(id: number, status: string) {
    try {
      await meetingsAPI.postActionItem({ action: 'update', id, status })
      await load()
    } catch { toast.error('Failed to update status') }
  }

  async function saveComment(id: number, comment: string) {
    try { await meetingsAPI.postActionItem({ action: 'update', id, comment }) } catch { toast.error('Failed to save comment') }
  }

  async function deleteItem(id: number) {
    if (!confirm('Delete this action item?')) return
    try {
      await meetingsAPI.postActionItem({ action: 'delete', id })
      await load()
    } catch { toast.error('Failed to delete') }
  }

  function toggleComment(id: number) {
    setCommentOpenIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  if (loading) return <Loader size="sm" label="Loading action items..." />

  const openCount = items.filter((i) => i.status !== 'done').length
  const overdueCount = items.filter((i) => i.status !== 'done' && i.deadline && i.deadline < today).length
  const completedThisWeek = items.filter((i) => i.status === 'done' && i.weekKey === weekKey).length
  const carriedCount = items.filter((i) => !!i.carriedFromWeek).length

  return (
    <div className="space-y-4">
      <div>
        <h4 className="section-title">Action Items</h4>
        <p className="muted">Track follow-ups and assigned tasks</p>
      </div>

      {items.length === 0 ? (
        <div className="text-sm text-zinc-500 text-center py-12">No action items assigned yet.</div>
      ) : (
        <>
        {/* Summary — only shown when items exist, matching act-summary */}
        <div className="grid grid-cols-4 gap-2">
          {[
            { label: 'Open Actions', value: openCount, cls: '' },
            { label: 'Overdue', value: overdueCount, cls: overdueCount > 0 ? 'border-red-500/30 bg-red-500/5' : '' },
            { label: 'Completed This Week', value: completedThisWeek, cls: '' },
            { label: 'Carried Forward', value: carriedCount, cls: '' }
          ].map((s) => (
            <div key={s.label} className={classNames(
              'rounded-lg border border-zinc-800 bg-surface-3 p-3 text-center',
              s.cls
            )}>
              <div className="text-lg font-bold text-zinc-100">{s.value}</div>
              <div className="text-[11px] text-zinc-500">{s.label}</div>
            </div>
          ))}
        </div>

        {/* Table — matching act-table with 9 columns */}
        (
        <div className="overflow-x-auto rounded-lg border border-zinc-800">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-zinc-800 bg-surface-3">
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500 w-10">Done</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Action</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Owner</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Created</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Deadline</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Priority</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">Status</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-500">KPI Link</th>
                <th className="px-3 py-2 w-16"></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const isOverdue = item.status !== 'done' && item.deadline && item.deadline < today
                const isCarried = !!item.carriedFromWeek

                return (
                  <tr
                    key={item.id}
                    className={classNames(
                      'border-b border-zinc-800/50 hover:bg-surface-3 transition-colors',
                      isCarried && 'bg-indigo-500/[0.03]',
                      isOverdue && 'bg-red-500/[0.04]',
                      isOverdue && 'shadow-[inset_3px_0_0_0_rgba(239,68,68,0.5)]'
                    )}
                  >
                    {/* Done checkbox */}
                    <td className="px-3 py-2.5">
                      <input
                        type="checkbox"
                        checked={item.status === 'done'}
                        onChange={() => toggleDone(item)}
                        className="h-4 w-4 cursor-pointer accent-brand-600"
                      />
                    </td>

                    {/* Action + carried label + comment */}
                    <td className="px-3 py-2.5 max-w-[280px]">
                      <div className={classNames(
                        'text-sm',
                        item.status === 'done' ? 'text-zinc-500 line-through' : 'text-zinc-200'
                      )}>
                        {item.task}
                      </div>
                      {isCarried && (
                        <div className="text-[10px] uppercase tracking-wider text-indigo-400 mt-0.5">
                          Carried from {fmtDate(item.carriedFromWeek!)}
                        </div>
                      )}
                      {item.comment && (
                        <div className="mt-1 text-xs text-zinc-400 bg-surface-4 border-l-2 border-zinc-700 px-2 py-1 rounded-r">
                          {item.comment}
                        </div>
                      )}
                      <button
                        onClick={() => toggleComment(item.id)}
                        className="text-[11px] text-blue-400 hover:underline mt-0.5"
                      >
                        {item.comment ? 'Edit comment' : 'Add comment'}
                      </button>
                      {commentOpenIds.has(item.id) && (
                        <textarea
                          defaultValue={item.comment || ''}
                          onBlur={(e) => saveComment(item.id, e.target.value)}
                          placeholder="Add comment..."
                          className="textarea-field text-xs mt-1"
                          rows={2}
                        />
                      )}
                    </td>

                    {/* Owner */}
                    <td className="px-3 py-2.5 text-zinc-400 text-xs">{item.owner || '—'}</td>

                    {/* Created Week */}
                    <td className="px-3 py-2.5 text-zinc-500 text-xs">{fmtDate(item.weekKey)}</td>

                    {/* Deadline */}
                    <td className="px-3 py-2.5 text-xs">
                      <span className={isOverdue ? 'text-red-400 font-medium' : 'text-zinc-400'}>
                        {fmtDate(item.deadline)}
                      </span>
                      {isOverdue && (
                        <span className="ml-1 inline-block rounded-full bg-red-500/20 text-red-400 text-[10px] px-1.5 py-0.5 font-medium">
                          ! Overdue
                        </span>
                      )}
                    </td>

                    {/* Priority */}
                    <td className="px-3 py-2.5 text-xs">
                      <span className="inline-flex items-center gap-1.5">
                        <span className={`h-2 w-2 rounded-full ${priorityDotColor[item.priority] || priorityDotColor.medium}`} />
                        <span className="text-zinc-400">{fmtPriority(item.priority)}</span>
                      </span>
                    </td>

                    {/* Status */}
                    <td className="px-3 py-2.5">
                      <span className={classNames(
                        'inline-block rounded-full px-2 py-0.5 text-[11px] font-medium',
                        statusStyle[item.status] || statusStyle.pending
                      )}>
                        {fmtStatus(item.status)}
                      </span>
                      {item.status === 'done' && item.completedAt && (
                        <div className="text-[10px] text-zinc-600 mt-0.5">
                          Completed: {fmtDate(item.completedAt)}
                        </div>
                      )}
                      <select
                        value={item.status}
                        onChange={(e) => updateStatus(item.id, e.target.value)}
                        className="block mt-1 text-[11px] bg-transparent border border-zinc-700 rounded px-1 py-0.5 text-zinc-500 cursor-pointer"
                      >
                        {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                      </select>
                    </td>

                    {/* KPI Link */}
                    <td className="px-3 py-2.5 text-xs">
                      {item.linkedKpi ? (
                        <span className="inline-block rounded-full bg-blue-500/10 text-blue-400 px-2 py-0.5 text-[10px]">
                          {kpiOptions.find((k) => k.key === item.linkedKpi)?.label || item.linkedKpi}
                        </span>
                      ) : (
                        <span className="text-zinc-600 text-[11px]">Not linked</span>
                      )}
                    </td>

                    {/* Edit / Delete */}
                    <td className="px-3 py-2.5">
                      <div className="flex gap-1">
                        <button onClick={() => startEdit(item)} className="text-[11px] text-zinc-500 hover:text-zinc-200">Edit</button>
                        <button onClick={() => deleteItem(item.id)} className="text-[11px] text-zinc-500 hover:text-red-400">Delete</button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        </>
      )}

      {/* Add/Edit form — matching act-form with act-form-grid (6-column grid) */}
      <form onSubmit={handleSubmit} className="rounded-lg bg-surface-1 border border-zinc-800 p-3">
        <div className="grid grid-cols-6 gap-2">
          <div className="col-span-2">
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Action</label>
            <input value={formTask} onChange={(e) => setFormTask(e.target.value)} placeholder="What needs to be done?" className="input-field text-xs" required />
          </div>
          <div>
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Owner</label>
            <select value={formOwner} onChange={(e) => setFormOwner(e.target.value)} className="select-field text-xs" required>
              <option value="">Select owner</option>
              {ownerOptions.map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Deadline</label>
            <input type="date" value={formDeadline} onChange={(e) => setFormDeadline(e.target.value)} className="input-field text-xs" />
          </div>
          <div>
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Status</label>
            <select value={formStatus} onChange={(e) => setFormStatus(e.target.value)} className="select-field text-xs">
              {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Priority</label>
            <select value={formPriority} onChange={(e) => setFormPriority(e.target.value)} className="select-field text-xs">
              {PRIORITIES.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
            </select>
          </div>
          <div className="col-span-6">
            <label className="block text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mb-1">Linked KPI</label>
            <select value={formKpi} onChange={(e) => setFormKpi(e.target.value)} className="select-field text-xs">
              <option value="">Not linked</option>
              {kpiOptions.map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}
            </select>
          </div>
        </div>
        <div className="flex items-center gap-2 mt-3">
          <button type="submit" className="btn-primary text-xs">
            {editingId ? 'Save Changes' : 'Add Action Item'}
          </button>
          {editingId && (
            <button type="button" onClick={resetForm} className="btn-secondary text-xs">Cancel</button>
          )}
        </div>
      </form>
    </div>
  )
}
