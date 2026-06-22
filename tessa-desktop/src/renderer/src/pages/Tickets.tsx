import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { ticketsAPI } from '@/api/client'
import { Modal, Loader, EmptyState } from '@/components/ui'
import type { Ticket } from '@/lib/types'
import { classNames, prettyDateTime } from '@/lib/utils'
import { Ticket as TicketIcon, Plus } from 'lucide-react'
import toast from 'react-hot-toast'

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'closed', label: 'Closed' }
]

const CATEGORY_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'technical', label: 'Technical' },
  { value: 'ai', label: 'AI' }
]

const STATUS_COLORS: Record<string, string> = {
  open: '#ef4444',
  in_progress: '#f59e0b',
  resolved: '#22c55e',
  closed: '#6b7280'
}

const STATUS_LABELS: Record<string, string> = {
  open: 'Open',
  in_progress: 'In Progress',
  resolved: 'Resolved',
  closed: 'Closed'
}

const PRIORITY_COLORS: Record<string, string> = {
  high: '#ef4444',
  medium: '#f59e0b',
  low: '#3b82f6'
}

const ACTION_ROLES = ['tech_lead', 'ceo', 'coo']

export default function Tickets(): JSX.Element {
  const { user } = useAuth()

  const [tickets, setTickets] = useState<Ticket[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')

  // New ticket modal
  const [modalOpen, setModalOpen] = useState(false)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({
    title: '',
    description: '',
    category: 'technical' as 'technical' | 'ai',
    priority: 'medium' as 'low' | 'medium' | 'high'
  })

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (statusFilter) params.status = statusFilter
      if (categoryFilter) params.category = categoryFilter
      const res = await ticketsAPI.list(params)
      setTickets(res.data?.tickets || [])
    } catch {
      toast.error('Failed to load tickets')
    } finally {
      setLoading(false)
    }
  }, [statusFilter, categoryFilter])

  useEffect(() => {
    load()
  }, [load])

  function openNewTicketModal() {
    setForm({ title: '', description: '', category: 'technical', priority: 'medium' })
    setModalOpen(true)
  }

  async function handleCreate() {
    if (!form.title.trim()) {
      toast.error('Title is required')
      return
    }
    setCreating(true)
    try {
      await ticketsAPI.create({
        title: form.title.trim(),
        description: form.description.trim(),
        category: form.category,
        priority: form.priority
      })
      toast.success('Ticket created')
      setModalOpen(false)
      load()
    } catch {
      toast.error('Failed to create ticket')
    } finally {
      setCreating(false)
    }
  }

  async function handleStatusAction(ticket: Ticket, newStatus: string) {
    try {
      await ticketsAPI.update(ticket.id, { status: newStatus })
      toast.success(`Ticket ${newStatus === 'in_progress' ? 'started' : newStatus}`)
      load()
    } catch {
      toast.error('Failed to update ticket')
    }
  }

  function canPerformAction(ticket: Ticket): boolean {
    if (!user) return false
    if (ticket.assigneeId === user.id) return true
    const role = (user.role || '').toLowerCase().replace(/\s+/g, '_')
    return ACTION_ROLES.includes(role)
  }

  function getActionButton(ticket: Ticket): { label: string; nextStatus: string } | null {
    switch (ticket.status) {
      case 'open':
        return { label: 'Start', nextStatus: 'in_progress' }
      case 'in_progress':
        return { label: 'Resolve', nextStatus: 'resolved' }
      case 'resolved':
        return { label: 'Close', nextStatus: 'closed' }
      default:
        return null
    }
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <h3 className="text-lg font-semibold text-zinc-100">Tickets</h3>

        <div className="flex items-center gap-2 flex-wrap">
          {/* Status filter */}
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-lg border border-zinc-700 bg-surface-2 px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:ring-1 focus:ring-brand-500"
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>

          {/* Category filter */}
          <select
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
            className="rounded-lg border border-zinc-700 bg-surface-2 px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:ring-1 focus:ring-brand-500"
          >
            {CATEGORY_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>

          {/* New Ticket button */}
          <button
            onClick={openNewTicketModal}
            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-500 transition-colors"
          >
            <Plus className="h-3.5 w-3.5" />
            New Ticket
          </button>
        </div>
      </div>

      {/* Content */}
      {loading ? (
        <Loader size="sm" label="Loading tickets..." />
      ) : tickets.length === 0 ? (
        <EmptyState
          icon={TicketIcon}
          title="No tickets found"
          description="Adjust your filters or create a new ticket."
        />
      ) : (
        <div className="space-y-3">
          {tickets.map((ticket) => {
            const action = getActionButton(ticket)
            const showAction = action && canPerformAction(ticket)
            const pc = PRIORITY_COLORS[ticket.priority] || '#f59e0b'

            return (
              <div
                key={ticket.id}
                className={classNames(
                  'rounded-lg border border-zinc-800 bg-surface-2 px-5 py-4',
                  ticket.status === 'closed' && 'opacity-60'
                )}
              >
                {/* Row 1: Title + status badge */}
                <div className="flex items-start justify-between gap-3">
                  <h4 className="text-[15px] font-semibold text-zinc-100">{ticket.title}</h4>
                  <span
                    className="shrink-0 rounded px-2.5 py-0.5 text-[11px] font-bold text-white"
                    style={{ backgroundColor: STATUS_COLORS[ticket.status] || '#6b7280' }}
                  >
                    {STATUS_LABELS[ticket.status] || ticket.status}
                  </span>
                </div>

                {/* Row 2: Full description */}
                {ticket.description && (
                  <p className="mt-2 text-[13px] leading-relaxed text-zinc-400 whitespace-pre-line">
                    {ticket.description}
                  </p>
                )}

                {/* Row 3: Priority badge + Category badge + meta */}
                <div className="mt-3 flex items-center gap-2 flex-wrap text-[12px] text-zinc-500">
                  <span
                    className="rounded px-2 py-0.5 text-[11px] font-semibold border"
                    style={{ color: pc, borderColor: pc }}
                  >
                    {ticket.priority.charAt(0).toUpperCase() + ticket.priority.slice(1)}
                  </span>
                  <span className="rounded px-2 py-0.5 text-[11px] font-semibold border border-zinc-600 text-zinc-300 bg-zinc-800">
                    {ticket.category === 'ai' ? 'AI' : 'Technical'}
                  </span>
                  {ticket.reporterName && (
                    <span className="ml-1">By: {ticket.reporterName}</span>
                  )}
                  {ticket.assigneeName && (
                    <span>Assigned: {ticket.assigneeName}</span>
                  )}
                  {ticket.createdAt && (
                    <span>{prettyDateTime(ticket.createdAt)}</span>
                  )}
                </div>

                {/* Row 4: Action button */}
                {showAction && (
                  <div className="mt-3">
                    <button
                      onClick={() => handleStatusAction(ticket, action.nextStatus)}
                      className="rounded border border-zinc-700 bg-surface-3 px-4 py-1.5 text-[12px] font-medium text-zinc-200 hover:bg-zinc-700 transition-colors"
                    >
                      {action.label}
                    </button>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* New Ticket Modal */}
      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="New Ticket"
        footer={
          <>
            <button
              onClick={() => setModalOpen(false)}
              className="rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800 transition-colors"
            >
              Cancel
            </button>
            <button
              onClick={handleCreate}
              disabled={creating}
              className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50 transition-colors"
            >
              {creating ? 'Creating...' : 'Create Ticket'}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          {/* Title */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Title</label>
            <input
              type="text"
              value={form.title}
              onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
              placeholder="Ticket title"
              className="input-field w-full"
              autoFocus
            />
          </div>

          {/* Description */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Description</label>
            <textarea
              value={form.description}
              onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
              placeholder="Describe the issue..."
              rows={4}
              className="textarea-field w-full"
            />
          </div>

          {/* Category */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Category</label>
            <select
              value={form.category}
              onChange={(e) =>
                setForm((f) => ({ ...f, category: e.target.value as 'technical' | 'ai' }))
              }
              className="rounded-lg border border-zinc-700 bg-surface-2 px-3 py-2 text-sm text-zinc-300 w-full focus:outline-none focus:ring-1 focus:ring-brand-500"
            >
              <option value="technical">Technical</option>
              <option value="ai">AI</option>
            </select>
          </div>

          {/* Priority */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Priority</label>
            <select
              value={form.priority}
              onChange={(e) =>
                setForm((f) => ({ ...f, priority: e.target.value as 'low' | 'medium' | 'high' }))
              }
              className="rounded-lg border border-zinc-700 bg-surface-2 px-3 py-2 text-sm text-zinc-300 w-full focus:outline-none focus:ring-1 focus:ring-brand-500"
            >
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="low">Low</option>
            </select>
          </div>
        </div>
      </Modal>
    </div>
  )
}
