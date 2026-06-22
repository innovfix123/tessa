import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { releasesAPI } from '@/api/client'
import { Modal, Loader, EmptyState, ConfirmDialog } from '@/components/ui'
import type { Release, ReleaseProject } from '@/lib/types'
import { classNames, prettyDate } from '@/lib/utils'
import toast from 'react-hot-toast'
import { Package, Plus, Pencil, Trash2, ChevronDown, ChevronRight, AlertTriangle } from 'lucide-react'

// ── Status config ──

const STATUSES = [
  { key: 'all', label: 'All' },
  { key: 'planned', label: 'Planned' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'testing', label: 'Testing' },
  { key: 'released', label: 'Released' },
  { key: 'delayed', label: 'Delayed' }
] as const

const STATUS_BADGE: Record<string, string> = {
  planned: 'bg-zinc-700/50 text-zinc-400 border-zinc-600/20',
  in_progress: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  testing: 'bg-purple-500/10 text-purple-400 border-purple-500/20',
  released: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
  delayed: 'bg-red-500/10 text-red-400 border-red-500/20',
  cancelled: 'bg-zinc-700/50 text-zinc-500 border-zinc-600/20'
}

const STATUS_BAR: Record<string, string> = {
  planned: 'bg-zinc-500',
  in_progress: 'bg-blue-500',
  testing: 'bg-purple-500',
  released: 'bg-emerald-500',
  delayed: 'bg-red-500',
  cancelled: 'bg-zinc-600'
}

const CHIP_ACTIVE: Record<string, string> = {
  all: 'bg-zinc-600 text-zinc-100',
  planned: 'bg-zinc-600 text-zinc-100',
  in_progress: 'bg-blue-600 text-blue-100',
  testing: 'bg-purple-600 text-purple-100',
  released: 'bg-emerald-600 text-emerald-100',
  delayed: 'bg-red-600 text-red-100'
}

const STATUS_OPTIONS = [
  { value: 'planned', label: 'Planned' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'testing', label: 'Testing' },
  { value: 'released', label: 'Released' },
  { value: 'delayed', label: 'Delayed' },
  { value: 'cancelled', label: 'Cancelled' }
]

function statusLabel(s: string): string {
  return STATUS_OPTIONS.find((o) => o.value === s)?.label ?? s
}

// ── Empty form ──

interface ReleaseForm {
  project_id: string
  version: string
  title: string
  description: string
  status: string
  progress: number
  planned_date: string
  actual_date: string
}

const EMPTY_FORM: ReleaseForm = {
  project_id: '',
  version: '',
  title: '',
  description: '',
  status: 'planned',
  progress: 0,
  planned_date: '',
  actual_date: ''
}

// ── Component ──

export default function Releases(): JSX.Element {
  const { user } = useAuth()

  // Data
  const [releases, setReleases] = useState<Release[]>([])
  const [projects, setProjects] = useState<ReleaseProject[]>([])
  const [loading, setLoading] = useState(true)

  // Filters
  const [filterProject, setFilterProject] = useState('')
  const [filterStatus, setFilterStatus] = useState('all')

  // Collapsed groups
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})

  // Modal
  const [modalOpen, setModalOpen] = useState(false)
  const [editingRelease, setEditingRelease] = useState<Release | null>(null)
  const [form, setForm] = useState<ReleaseForm>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)

  // Delete confirm
  const [deleteTarget, setDeleteTarget] = useState<Release | null>(null)
  const [deleting, setDeleting] = useState(false)

  // Permissions — managers/admins can create/edit/delete
  const canManage = ['admin', 'ceo', 'coo', 'cto', 'tech_lead', 'product_manager'].includes(
    (user?.role ?? '').toLowerCase().replace(/\s+/g, '_')
  )

  // ── Load ──

  const load = useCallback(async () => {
    try {
      const params: Record<string, string> = {}
      if (filterProject) params.project_id = filterProject
      if (filterStatus !== 'all') params.status = filterStatus
      const res = await releasesAPI.list(params)
      setReleases(res.data.releases ?? [])
      setProjects(res.data.projects ?? [])
    } catch {
      toast.error('Failed to load releases')
    } finally {
      setLoading(false)
    }
  }, [filterProject, filterStatus])

  useEffect(() => {
    setLoading(true)
    load()
  }, [load])

  // ── Group by project ──

  const grouped = releases.reduce<Record<string, Release[]>>((acc, r) => {
    const key = r.projectName || 'Unknown Project'
    if (!acc[key]) acc[key] = []
    acc[key].push(r)
    return acc
  }, {})

  const projectNames = Object.keys(grouped).sort()

  // ── Toggle collapse ──

  function toggleGroup(name: string) {
    setCollapsed((prev) => ({ ...prev, [name]: !prev[name] }))
  }

  // ── Modal helpers ──

  function openCreate() {
    setEditingRelease(null)
    setForm(EMPTY_FORM)
    setModalOpen(true)
  }

  function openEdit(r: Release) {
    setEditingRelease(r)
    setForm({
      project_id: String(r.projectId),
      version: r.version,
      title: r.title,
      description: r.description ?? '',
      status: r.status,
      progress: r.progress,
      planned_date: r.plannedDate,
      actual_date: r.actualDate ?? ''
    })
    setModalOpen(true)
  }

  function closeModal() {
    setModalOpen(false)
    setEditingRelease(null)
  }

  function updateField<K extends keyof ReleaseForm>(key: K, value: ReleaseForm[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  // ── Save ──

  async function handleSave() {
    if (!form.title.trim() || !form.version.trim() || !form.project_id || !form.planned_date) {
      toast.error('Please fill in all required fields')
      return
    }
    setSaving(true)
    try {
      const payload = {
        project_id: Number(form.project_id),
        version: form.version.trim(),
        title: form.title.trim(),
        description: form.description.trim() || null,
        status: form.status,
        progress: form.progress,
        planned_date: form.planned_date,
        actual_date: form.actual_date || null
      }
      if (editingRelease) {
        await releasesAPI.update(editingRelease.id, payload)
        toast.success('Release updated')
      } else {
        await releasesAPI.create(payload)
        toast.success('Release created')
      }
      closeModal()
      load()
    } catch {
      toast.error('Failed to save release')
    } finally {
      setSaving(false)
    }
  }

  // ── Delete ──

  async function handleDelete() {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await releasesAPI.destroy(deleteTarget.id)
      toast.success('Release deleted')
      setDeleteTarget(null)
      load()
    } catch {
      toast.error('Failed to delete release')
    } finally {
      setDeleting(false)
    }
  }

  // ── Render ──

  if (loading) return <Loader label="Loading releases..." />

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-semibold text-zinc-100">Release Tracker</h1>
        <div className="flex items-center gap-3">
          {/* Project filter */}
          <select
            value={filterProject}
            onChange={(e) => setFilterProject(e.target.value)}
            className="input-field text-sm min-w-[140px]"
          >
            <option value="">All Projects</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>

          {canManage && (
            <button onClick={openCreate} className="btn-primary flex items-center gap-1.5 text-sm">
              <Plus className="h-4 w-4" />
              New Release
            </button>
          )}
        </div>
      </div>

      {/* Status chip filters */}
      <div className="flex flex-wrap gap-2">
        {STATUSES.map((s) => (
          <button
            key={s.key}
            onClick={() => setFilterStatus(s.key)}
            className={classNames(
              'rounded-full px-3 py-1 text-xs font-medium transition-colors',
              filterStatus === s.key
                ? CHIP_ACTIVE[s.key]
                : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
            )}
          >
            {s.label}
          </button>
        ))}
      </div>

      {/* Empty state */}
      {releases.length === 0 ? (
        <EmptyState
          icon={Package}
          title="No releases found"
          description="No releases match the current filters."
          action={
            canManage ? (
              <button onClick={openCreate} className="btn-primary text-sm">
                Create Release
              </button>
            ) : undefined
          }
        />
      ) : (
        /* Grouped release cards */
        <div className="space-y-4">
          {projectNames.map((projectName) => {
            const items = grouped[projectName]
            const isCollapsed = collapsed[projectName] ?? false

            return (
              <div key={projectName} className="rounded-xl border border-zinc-800 bg-surface-1 overflow-hidden">
                {/* Group header */}
                <button
                  onClick={() => toggleGroup(projectName)}
                  className="flex w-full items-center justify-between px-5 py-3 hover:bg-zinc-800/40 transition-colors"
                >
                  <div className="flex items-center gap-2">
                    {isCollapsed
                      ? <ChevronRight className="h-4 w-4 text-zinc-500" />
                      : <ChevronDown className="h-4 w-4 text-zinc-500" />
                    }
                    <span className="text-sm font-semibold text-zinc-200">{projectName}</span>
                    <span className="rounded-full bg-zinc-800 px-2 py-0.5 text-xs text-zinc-400">
                      {items.length}
                    </span>
                  </div>
                </button>

                {/* Releases in group */}
                {!isCollapsed && (
                  <div className="divide-y divide-zinc-800/50">
                    {items.map((r) => (
                      <div key={r.id} className="px-5 py-4 hover:bg-zinc-800/20 transition-colors">
                        <div className="flex items-start justify-between gap-4">
                          {/* Left: version + title + status */}
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                              {/* Version badge */}
                              <span className="rounded bg-zinc-800 px-2 py-0.5 text-xs font-mono text-zinc-300">
                                {r.version}
                              </span>
                              {/* Title */}
                              <span className="text-sm font-medium text-zinc-100 truncate">
                                {r.title}
                              </span>
                              {/* Status badge */}
                              <span
                                className={classNames(
                                  'rounded-full border px-2 py-0.5 text-xs font-medium',
                                  STATUS_BADGE[r.status] ?? STATUS_BADGE.planned
                                )}
                              >
                                {statusLabel(r.status)}
                              </span>
                            </div>

                            {/* Dates */}
                            <div className="mt-2 flex items-center gap-4 text-xs text-zinc-500">
                              <span>Planned: {prettyDate(r.plannedDate)}</span>
                              {r.actualDate && <span>Actual: {prettyDate(r.actualDate)}</span>}
                              {r.isDelayed && r.daysOverdue && r.daysOverdue > 0 && (
                                <span className="flex items-center gap-1 text-amber-400">
                                  <AlertTriangle className="h-3 w-3" />
                                  {r.daysOverdue} day{r.daysOverdue !== 1 ? 's' : ''} overdue
                                </span>
                              )}
                            </div>

                            {/* Progress bar */}
                            <div className="mt-2 flex items-center gap-2">
                              <div className="h-1.5 flex-1 rounded-full bg-zinc-800">
                                <div
                                  className={classNames(
                                    'h-1.5 rounded-full transition-all',
                                    STATUS_BAR[r.status] ?? STATUS_BAR.planned
                                  )}
                                  style={{ width: `${Math.min(r.progress, 100)}%` }}
                                />
                              </div>
                              <span className="text-xs text-zinc-500 tabular-nums w-8 text-right">
                                {r.progress}%
                              </span>
                            </div>
                          </div>

                          {/* Right: actions */}
                          {canManage && (
                            <div className="flex items-center gap-1 shrink-0">
                              <button
                                onClick={() => openEdit(r)}
                                className="btn-icon"
                                title="Edit release"
                              >
                                <Pencil className="h-3.5 w-3.5" />
                              </button>
                              <button
                                onClick={() => setDeleteTarget(r)}
                                className="btn-icon text-red-400 hover:text-red-300"
                                title="Delete release"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </button>
                            </div>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editingRelease ? 'Edit Release' : 'New Release'}
        width="max-w-lg"
        footer={
          <>
            <button onClick={closeModal} className="btn-secondary" disabled={saving}>
              Cancel
            </button>
            <button onClick={handleSave} className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : editingRelease ? 'Update' : 'Create'}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          {/* Project */}
          <div>
            <label className="label">Project <span className="text-red-400">*</span></label>
            <select
              value={form.project_id}
              onChange={(e) => updateField('project_id', e.target.value)}
              className="input-field w-full"
            >
              <option value="">Select project</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </div>

          {/* Version */}
          <div>
            <label className="label">Version <span className="text-red-400">*</span></label>
            <input
              type="text"
              value={form.version}
              onChange={(e) => updateField('version', e.target.value)}
              placeholder="e.g. 2.1.0"
              className="input-field w-full"
            />
          </div>

          {/* Title */}
          <div>
            <label className="label">Title <span className="text-red-400">*</span></label>
            <input
              type="text"
              value={form.title}
              onChange={(e) => updateField('title', e.target.value)}
              placeholder="Release title"
              className="input-field w-full"
            />
          </div>

          {/* Description */}
          <div>
            <label className="label">Description</label>
            <textarea
              value={form.description}
              onChange={(e) => updateField('description', e.target.value)}
              placeholder="Release notes or description"
              rows={3}
              className="input-field w-full resize-none"
            />
          </div>

          {/* Status */}
          <div>
            <label className="label">Status</label>
            <select
              value={form.status}
              onChange={(e) => updateField('status', e.target.value)}
              className="input-field w-full"
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          {/* Progress */}
          <div>
            <label className="label">Progress: {form.progress}%</label>
            <input
              type="range"
              min={0}
              max={100}
              step={1}
              value={form.progress}
              onChange={(e) => updateField('progress', Number(e.target.value))}
              className="w-full accent-brand-500"
            />
          </div>

          {/* Planned Date */}
          <div>
            <label className="label">Planned Date <span className="text-red-400">*</span></label>
            <input
              type="date"
              value={form.planned_date}
              onChange={(e) => updateField('planned_date', e.target.value)}
              className="input-field w-full"
            />
          </div>

          {/* Actual Date */}
          <div>
            <label className="label">Actual Date</label>
            <input
              type="date"
              value={form.actual_date}
              onChange={(e) => updateField('actual_date', e.target.value)}
              className="input-field w-full"
            />
          </div>
        </div>
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        title="Delete Release"
        message={`Delete "${deleteTarget?.title}" (${deleteTarget?.version})? This action cannot be undone.`}
        confirmLabel="Delete"
        danger
        loading={deleting}
      />
    </div>
  )
}
