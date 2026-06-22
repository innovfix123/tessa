import { useState, useEffect, useCallback } from 'react'
import { leaveAPI } from '@/api/client'
import { Modal, Loader, EmptyState } from '@/components/ui'
import type { LeaveType, LeaveRequest } from '@/lib/types'
import { classNames, formatDate, prettyDate } from '@/lib/utils'
import { CalendarDays, Plus } from 'lucide-react'
import toast from 'react-hot-toast'

type StatusFilter = '' | 'pending' | 'approved' | 'rejected' | 'cancelled'

const STATUS_COLORS: Record<string, string> = {
  pending: '#f59e0b',
  approved: '#22c55e',
  rejected: '#ef4444',
  cancelled: '#6b7280'
}

function statusBadge(status: string): JSX.Element {
  const color = STATUS_COLORS[status] || '#6b7280'
  return (
    <span
      className="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize"
      style={{ backgroundColor: color + '20', color }}
    >
      {status}
    </span>
  )
}

function todayStr(): string {
  return formatDate(new Date())
}

export default function Leave(): JSX.Element {
  const [loading, setLoading] = useState(true)
  const [leaveTypes, setLeaveTypes] = useState<LeaveType[]>([])
  const [requests, setRequests] = useState<LeaveRequest[]>([])
  const [teamPending, setTeamPending] = useState<LeaveRequest[]>([])
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('')
  const [showModal, setShowModal] = useState(false)

  // Apply-leave form state
  const [formType, setFormType] = useState('')
  const [formStart, setFormStart] = useState(todayStr)
  const [formEnd, setFormEnd] = useState(todayStr)
  const [formReason, setFormReason] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (statusFilter) params.status = statusFilter

      const [reqRes, typesRes, teamRes] = await Promise.allSettled([
        leaveAPI.requests(params),
        leaveAPI.types(),
        leaveAPI.teamPending()
      ])

      if (typesRes.status === 'fulfilled') {
        const types: LeaveType[] = typesRes.value.data?.leave_types || []
        setLeaveTypes(types)
        if (!formType && types.length > 0) {
          setFormType(types[0].slug)
        }
      }
      if (reqRes.status === 'fulfilled') {
        setRequests(reqRes.value.data?.leave_requests || [])
      }
      if (teamRes.status === 'fulfilled') {
        setTeamPending(teamRes.value.data?.pending_requests || [])
      }
      // team-pending may 403 for non-managers -- silently ignored via allSettled
    } catch {
      toast.error('Failed to load leave data')
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => {
    load()
  }, [load])

  // ── Actions ──

  async function handleSubmit(): Promise<void> {
    if (!formType) {
      toast.error('Please select a leave type')
      return
    }
    if (!formStart || !formEnd) {
      toast.error('Please select start and end dates')
      return
    }
    if (formEnd < formStart) {
      toast.error('End date cannot be before start date')
      return
    }
    setSubmitting(true)
    try {
      await leaveAPI.submit({
        leave_type: formType,
        start_date: formStart,
        end_date: formEnd,
        reason: formReason
      })
      toast.success('Leave request submitted')
      setShowModal(false)
      resetForm()
      load()
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Failed to submit leave request'
      toast.error(msg)
    } finally {
      setSubmitting(false)
    }
  }

  function resetForm(): void {
    setFormType(leaveTypes[0]?.slug || '')
    setFormStart(todayStr())
    setFormEnd(todayStr())
    setFormReason('')
  }

  async function handleReview(id: number, action: 'approve' | 'reject'): Promise<void> {
    if (action === 'reject') {
      const note = window.prompt('Rejection reason (optional):')
      try {
        await leaveAPI.review(id, { action: 'reject', note: note || '' })
        toast.success('Leave request rejected')
        load()
      } catch {
        toast.error('Failed to reject request')
      }
      return
    }
    try {
      await leaveAPI.review(id, { action: 'approve' })
      toast.success('Leave request approved')
      load()
    } catch {
      toast.error('Failed to approve request')
    }
  }

  async function handleCancel(id: number): Promise<void> {
    if (!window.confirm('Cancel this leave request?')) return
    try {
      await leaveAPI.cancel(id)
      toast.success('Leave request cancelled')
      load()
    } catch {
      toast.error('Failed to cancel request')
    }
  }

  // ── Derived data ──

  const autoApprovedTypes = leaveTypes.filter((t) => !t.requires_approval)
  const managerApprovalTypes = leaveTypes.filter((t) => t.requires_approval)
  const selectedType = leaveTypes.find((t) => t.slug === formType)

  if (loading && requests.length === 0 && leaveTypes.length === 0) {
    return <Loader label="Loading leave..." />
  }

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-bold text-zinc-100">Leave Management</h1>
        <p className="text-sm text-zinc-500 mt-1">Apply for leave, track requests, and manage team approvals.</p>
      </div>

      {/* Policy note */}
      <div className="rounded-lg border border-blue-500/30 bg-blue-950/30 px-5 py-4">
        <h3 className="text-sm font-semibold text-blue-300 mb-2">Leave Policy</h3>
        <div className="text-sm text-blue-200/80 space-y-1">
          {autoApprovedTypes.length > 0 && (
            <p>
              <span className="font-medium text-blue-200">Auto-approved:</span>{' '}
              {autoApprovedTypes.map((t) => t.name).join(', ')}
            </p>
          )}
          {managerApprovalTypes.length > 0 && (
            <p>
              <span className="font-medium text-blue-200">Requires manager approval:</span>{' '}
              {managerApprovalTypes.map((t) => t.name).join(', ')}
            </p>
          )}
          <p className="text-blue-300/70 mt-2 text-xs">No pay cuts. Be responsible for your work.</p>
        </div>
      </div>

      {/* Leave balance grid */}
      {leaveTypes.length > 0 && (
        <div>
          <h2 className="text-sm font-semibold text-zinc-400 uppercase tracking-wider mb-3">Leave Types</h2>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
            {leaveTypes.map((lt) => (
              <div
                key={lt.id}
                className="rounded-lg border border-zinc-800 bg-surface-2 px-4 py-3"
              >
                <div className="text-sm font-medium text-zinc-200">{lt.name}</div>
                <div className="mt-1.5">
                  {lt.requires_approval ? (
                    <span className="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold bg-amber-500/15 text-amber-400">
                      Manager approval
                    </span>
                  ) : (
                    <span className="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-500/15 text-emerald-400">
                      Auto-approved
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Actions row */}
      <div className="flex items-center justify-between gap-4">
        <button
          onClick={() => { resetForm(); setShowModal(true) }}
          className="inline-flex items-center gap-2 rounded-lg bg-brand-600 hover:bg-brand-500 px-4 py-2 text-sm font-medium text-white transition-colors"
        >
          <Plus className="h-4 w-4" />
          Apply Leave
        </button>

        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
          className="rounded-lg border border-zinc-700 bg-surface-2 px-3 py-2 text-sm text-zinc-300 focus:outline-none focus:ring-1 focus:ring-brand-500"
        >
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      {/* Team Pending Requests (managers only) */}
      {teamPending.length > 0 && (
        <div>
          <h2 className="text-sm font-semibold text-zinc-400 uppercase tracking-wider mb-3">
            Team Requests Pending Approval
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {teamPending.map((req) => (
              <div
                key={req.id}
                className="rounded-lg border border-zinc-800 bg-surface-2 p-4 space-y-3"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <div className="text-sm font-medium text-zinc-200">
                      {req.user?.name || 'Team member'}
                    </div>
                    <div className="text-xs text-zinc-500 mt-0.5">
                      {req.leave_type?.name || 'Leave'}
                    </div>
                  </div>
                  {statusBadge(req.status)}
                </div>
                <div className="text-xs text-zinc-400">
                  {prettyDate(req.start_date)} - {prettyDate(req.end_date)}
                  <span className="ml-2 text-zinc-500">({req.total_days} day{req.total_days !== 1 ? 's' : ''})</span>
                </div>
                {req.reason && (
                  <div className="text-xs text-zinc-500 italic">{req.reason}</div>
                )}
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={() => handleReview(req.id, 'approve')}
                    className="flex-1 rounded-md bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white transition-colors"
                  >
                    Approve
                  </button>
                  <button
                    onClick={() => handleReview(req.id, 'reject')}
                    className="flex-1 rounded-md bg-red-600 hover:bg-red-500 px-3 py-1.5 text-xs font-medium text-white transition-colors"
                  >
                    Reject
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* My Requests */}
      <div>
        <h2 className="text-sm font-semibold text-zinc-400 uppercase tracking-wider mb-3">
          My Leave Requests
        </h2>
        {requests.length === 0 ? (
          <EmptyState
            icon={CalendarDays}
            title="No leave requests"
            description={statusFilter ? `No ${statusFilter} requests found.` : 'You have not applied for any leave yet.'}
          />
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {requests.map((req) => (
              <div
                key={req.id}
                className="rounded-lg border border-zinc-800 bg-surface-2 p-4 space-y-2.5"
              >
                <div className="flex items-start justify-between">
                  <div className="text-sm font-medium text-zinc-200">
                    {req.leave_type?.name || 'Leave'}
                  </div>
                  {statusBadge(req.status)}
                </div>
                <div className="text-xs text-zinc-400">
                  {prettyDate(req.start_date)} - {prettyDate(req.end_date)}
                  <span className="ml-2 text-zinc-500">({req.total_days} day{req.total_days !== 1 ? 's' : ''})</span>
                </div>
                {req.reason && (
                  <div className="text-xs text-zinc-500">{req.reason}</div>
                )}
                {req.reviewer_note && (
                  <div className="rounded-md bg-surface-1 px-3 py-2 text-xs text-zinc-400">
                    <span className="font-medium text-zinc-300">Reviewer note:</span> {req.reviewer_note}
                    {req.reviewer?.name && (
                      <span className="text-zinc-500 ml-1">-- {req.reviewer.name}</span>
                    )}
                  </div>
                )}
                {(req.status === 'pending' || req.status === 'approved') && (
                  <button
                    onClick={() => handleCancel(req.id)}
                    className="rounded-md border border-zinc-700 hover:border-red-500/50 hover:bg-red-500/10 px-3 py-1.5 text-xs font-medium text-zinc-400 hover:text-red-400 transition-colors"
                  >
                    Cancel Request
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Apply Leave Modal */}
      <Modal
        open={showModal}
        onClose={() => setShowModal(false)}
        title="Apply for Leave"
        footer={
          <>
            <button
              onClick={() => setShowModal(false)}
              className="rounded-lg border border-zinc-700 px-4 py-2 text-sm text-zinc-400 hover:bg-surface-3 transition-colors"
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={submitting}
              className="rounded-lg bg-brand-600 hover:bg-brand-500 disabled:opacity-50 px-4 py-2 text-sm font-medium text-white transition-colors"
            >
              {submitting ? 'Submitting...' : 'Submit Request'}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          {/* Leave Type */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Leave Type</label>
            <select
              value={formType}
              onChange={(e) => setFormType(e.target.value)}
              className="w-full rounded-lg border border-zinc-700 bg-surface-3 px-3 py-2 text-sm text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500"
            >
              {leaveTypes.map((lt) => (
                <option key={lt.slug} value={lt.slug}>
                  {lt.name}{!lt.requires_approval ? ' (auto-approved)' : ''}
                </option>
              ))}
            </select>
            {selectedType && (
              <p className={classNames(
                'text-xs mt-1.5',
                selectedType.requires_approval ? 'text-amber-400' : 'text-emerald-400'
              )}>
                {selectedType.requires_approval
                  ? 'This leave type requires manager approval.'
                  : 'This leave type is auto-approved. No manager action needed.'}
              </p>
            )}
          </div>

          {/* Start Date */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Start Date</label>
            <input
              type="date"
              value={formStart}
              onChange={(e) => setFormStart(e.target.value)}
              className="w-full rounded-lg border border-zinc-700 bg-surface-3 px-3 py-2 text-sm text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500"
            />
          </div>

          {/* End Date */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">End Date</label>
            <input
              type="date"
              value={formEnd}
              onChange={(e) => setFormEnd(e.target.value)}
              className="w-full rounded-lg border border-zinc-700 bg-surface-3 px-3 py-2 text-sm text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500"
            />
          </div>

          {/* Reason */}
          <div>
            <label className="block text-sm font-medium text-zinc-300 mb-1">Reason (optional)</label>
            <textarea
              value={formReason}
              onChange={(e) => setFormReason(e.target.value)}
              rows={3}
              placeholder="Brief reason for your leave..."
              className="w-full rounded-lg border border-zinc-700 bg-surface-3 px-3 py-2 text-sm text-zinc-200 placeholder-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500 resize-none"
            />
          </div>
        </div>
      </Modal>
    </div>
  )
}
