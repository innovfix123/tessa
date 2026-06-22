import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { signoffAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { formatDate, classNames } from '@/lib/utils'
import toast from 'react-hot-toast'

interface SignOffItem {
  type: 'daily_report' | 'agenda' | 'notes' | 'action_items'
  status: 'complete' | 'pending'
  label: string
  detail: string
  meetingKey?: string
  recurrence?: string
}

interface SignOffData {
  items: SignOffItem[]
  signedOff: boolean
  signedOffAt: string
  canSignOff: boolean
  dayName: string
}

function todayDateKey(): string {
  const d = new Date()
  return formatDate(d)
}

function todayDateLabel(): string {
  return new Date().toLocaleDateString('en-IN', {
    weekday: 'long',
    day: '2-digit',
    month: 'short'
  })
}

function formatTime(iso: string): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleTimeString('en-IN', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    })
  } catch {
    return ''
  }
}

export default function SignOff(): JSX.Element {
  const navigate = useNavigate()
  const [data, setData] = useState<SignOffData | null>(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await signoffAPI.status({ date: todayDateKey() })
      setData(res.data)
    } catch (err: any) {
      toast.error(err?.response?.data?.message || 'Unable to load sign-off status')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const handleSignOff = async () => {
    setSubmitting(true)
    try {
      await signoffAPI.submit({})
      toast.success('Signed off successfully!')
      load()
    } catch (err: any) {
      toast.error(err?.response?.data?.message || 'Sign off failed')
    } finally {
      setSubmitting(false)
    }
  }

  const handleItemClick = (item: SignOffItem) => {
    if (item.status !== 'pending') return

    if (item.type === 'daily_report') {
      navigate('/daily-reports')
      return
    }

    if (item.type === 'agenda' || item.type === 'notes' || item.type === 'action_items') {
      navigate('/meetings')
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="p-6">
        <h1 className="page-title">Daily Sign-Off</h1>
        <div className="card p-8 text-center text-zinc-400">
          Unable to load sign-off status.
        </div>
      </div>
    )
  }

  const { items, signedOff, signedOffAt, canSignOff } = data
  const pendingCount = items.filter((it) => it.status === 'pending').length

  const summaryText =
    pendingCount > 0
      ? `${pendingCount} item${pendingCount === 1 ? '' : 's'} pending. Complete them to sign off.`
      : "All items complete! You're good to go."

  /* ── Completion screen ── */
  if (signedOff) {
    const timeStr = formatTime(signedOffAt)
    return (
      <div className="p-6">
        <h1 className="page-title">Daily Sign-Off</h1>
        <p className="text-sm text-zinc-400 -mt-2 mb-6">{todayDateLabel()}</p>

        <div className="card flex flex-col items-center justify-center py-16 text-center">
          <div className="w-16 h-16 rounded-full bg-emerald-500/20 flex items-center justify-center mb-4">
            <svg
              className="w-8 h-8 text-emerald-400"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2.5}
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h3 className="text-lg font-semibold text-zinc-100 mb-1">
            Signed off at {timeStr}
          </h3>
          <p className="text-zinc-400">All tasks completed for today. Great work!</p>
        </div>
      </div>
    )
  }

  /* ── Active sign-off view ── */
  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-1">
        <h1 className="page-title mb-0">Daily Sign-Off</h1>
        <button
          type="button"
          onClick={load}
          className="text-sm text-zinc-400 hover:text-zinc-200 transition-colors"
        >
          Refresh
        </button>
      </div>
      <p className="text-sm text-zinc-400 mb-4">{todayDateLabel()}</p>

      <p className="text-sm text-zinc-400 mb-6">
        Complete all items before signing off for the day.
      </p>

      {/* Items list */}
      <div className="card divide-y divide-zinc-700/50">
        {items.map((item, idx) => {
          const isPending = item.status === 'pending'
          const isClickable = isPending && !!item.type
          return (
            <div
              key={idx}
              onClick={isClickable ? () => handleItemClick(item) : undefined}
              className={classNames(
                'flex items-start gap-3 px-4 py-3',
                isClickable && 'cursor-pointer hover:bg-zinc-700/30 transition-colors',
                !isPending && 'opacity-60'
              )}
            >
              <span
                className={classNames(
                  'w-2.5 h-2.5 rounded-full mt-1.5 shrink-0',
                  isPending ? 'bg-red-500' : 'bg-emerald-500'
                )}
              />
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-zinc-200">{item.label}</div>
                <div className="text-xs text-zinc-400 mt-0.5">{item.detail}</div>
                {isPending && (
                  <div className="text-xs text-blue-400 mt-1">Click to complete &gt;</div>
                )}
              </div>
            </div>
          )
        })}
      </div>

      {/* Footer */}
      <div className="mt-6 flex items-center justify-between">
        <p className="text-sm text-zinc-400">{summaryText}</p>
        <button
          type="button"
          onClick={handleSignOff}
          disabled={!canSignOff || submitting}
          className={classNames(
            'btn-primary',
            (!canSignOff || submitting) && 'opacity-50 cursor-not-allowed'
          )}
        >
          {submitting ? 'Signing off...' : 'Sign Off'}
        </button>
      </div>
    </div>
  )
}
