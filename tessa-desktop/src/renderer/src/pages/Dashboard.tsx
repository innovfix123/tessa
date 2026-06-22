import { useState, useEffect, useCallback } from 'react'
import { dashboardAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { classNames } from '@/lib/utils'
import toast from 'react-hot-toast'

interface UserRow {
  userId: number
  userName: string
  role: string
  project: string
  reportingManager: string
  isOwnTeam: boolean
  allClear: boolean
  tessaSignIn: { signedIn: boolean; signedInAt: string | null }
  signoff: { signedOff: boolean; signedOffAt: string | null }
}

interface DashData {
  date: string
  dayName: string
  summary: { clearCount: number; totalCount: number; signedInCount: number }
  users: UserRow[]
}

function signInFirst(a: UserRow, b: UserRow): number {
  const aIn = a.tessaSignIn?.signedIn === true
  const bIn = b.tessaSignIn?.signedIn === true
  if (aIn && !bIn) return -1
  if (!aIn && bIn) return 1
  return (a.userName || '').localeCompare(b.userName || '')
}

function formatSignInTime(iso: string | null): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleTimeString('en-IN', {
      hour: 'numeric', minute: '2-digit', hour12: true
    })
  } catch { return '' }
}

export default function Dashboard(): JSX.Element {
  const [data, setData] = useState<DashData | null>(null)
  const [loading, setLoading] = useState(true)
  const [showOthers, setShowOthers] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const today = new Date()
      const dateStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
      const res = await dashboardAPI.status()
      setData(res.data)
    } catch {
      toast.error('Failed to load dashboard')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  if (loading) return <Loader label="Loading dashboard..." />
  if (!data) return <div className="text-sm text-zinc-500 text-center py-12">No dashboard data available.</div>

  const ownTeam = (data.users || []).filter((u) => u.isOwnTeam).sort(signInFirst)
  const others = (data.users || []).filter((u) => !u.isOwnTeam).sort(signInFirst)
  const todayFmt = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: '2-digit', month: 'short' })

  function Pill({ user }: { user: UserRow }) {
    const signedIn = user.tessaSignIn?.signedIn === true
    const timeStr = formatSignInTime(user.tessaSignIn?.signedInAt || null)
    const tooltip = signedIn ? `Signed in at ${timeStr}` : 'Not signed in today'

    return (
      <span
        className="inline-flex items-center gap-2 rounded-full bg-surface-3 border border-zinc-800 px-3 py-1.5 text-sm cursor-default transition-colors hover:border-zinc-700"
        title={tooltip}
      >
        <span
          className={classNames(
            'h-2.5 w-2.5 rounded-full shrink-0',
            signedIn ? 'bg-emerald-400' : 'bg-red-400'
          )}
        />
        <span className="text-zinc-300">{user.userName}</span>
      </span>
    )
  }

  return (
    <div className="max-w-4xl">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-xl font-bold text-zinc-100">Dashboard</h2>
          <div className="text-sm text-zinc-500 mt-0.5">{todayFmt}</div>
        </div>
        <div className="text-sm text-zinc-400">
          {data.summary?.signedInCount ?? 0} of {data.summary?.totalCount ?? 0} signed in today.
        </div>
      </div>

      {/* Own Team */}
      {ownTeam.length > 0 && (
        <div className="flex flex-wrap gap-2 mb-4">
          {ownTeam.map((u) => <Pill key={u.userId} user={u} />)}
        </div>
      )}

      {/* Others toggle */}
      {others.length > 0 && (
        <>
          <button
            onClick={() => setShowOthers((v) => !v)}
            className="text-sm text-zinc-500 hover:text-zinc-300 transition-colors mb-3"
          >
            {showOthers ? 'Hide others' : `Show all others (${others.length})`}
          </button>

          {showOthers && (
            <div className="flex flex-wrap gap-2">
              {others.map((u) => <Pill key={u.userId} user={u} />)}
            </div>
          )}
        </>
      )}

      {(data.users || []).length === 0 && (
        <div className="text-sm text-zinc-500 text-center py-12">No dashboard data available.</div>
      )}
    </div>
  )
}
