import { useState } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { authAPI } from '@/api/client'
import { FEATURE_LABELS, FEATURE_ROUTES } from '@/lib/constants'
import { Avatar } from '@/components/ui'
import {
  LayoutDashboard, CheckSquare, CalendarDays, Calendar,
  FileText, TrendingUp, BarChart3, AlertTriangle, CheckCircle,
  Users, ClipboardList, Rocket, Code, Ticket, Receipt,
  BarChart, Sun, Target, User, CalendarOff, Kanban, LogOut,
  DollarSign, KeyRound
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

function TessaIcon({ className }: { className?: string }) {
  return (
    <svg className={className} width="16" height="16" viewBox="0 0 60 60" fill="none">
      <path d="M2 5 L58 5 L58 13 L40 13 L36 19 L36 55 L24 55 L24 19 L20 13 L2 13 Z" fill="#3b82f6"/>
    </svg>
  )
}

const FEATURE_ICONS: Record<string, LucideIcon> = {
  dashboard: LayoutDashboard,
  tasks: CheckSquare,
  meetings: CalendarDays,
  calendar: Calendar,
  daily: FileText,
  kpi: TrendingUp,
  mkpi: BarChart3,
  escalations: AlertTriangle,
  signoff: CheckCircle,
  org: Users,
  templates: ClipboardList,
  releases: Rocket,
  scripts: Code,
  tickets: Ticket,
  invoices: Receipt,
  meta_ads: BarChart,
  google_ads: Sun,
  mission: Target,
  employees: Users,
  profile: User,
  leave: CalendarOff,
  agile: Kanban,
  revenue: DollarSign
}

export default function Sidebar(): JSX.Element {
  const { user, features, roleName, logout } = useAuth()

  return (
    <aside className="flex h-screen w-60 flex-col border-r border-zinc-800 bg-surface-2">
      {/* Brand */}
      <div className="flex h-14 items-center gap-2.5 border-b border-zinc-800 px-4">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 font-bold text-white text-sm">
          T
        </div>
        <div className="min-w-0">
          <h1 className="text-sm font-semibold text-zinc-100 leading-tight">Tessa</h1>
          <p className="text-[10px] text-zinc-500 leading-tight">InnovFix Portal</p>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto px-2 py-3">
        <ul className="space-y-0.5">
          {features.map((feature) => {
            const Icon = FEATURE_ICONS[feature]
            const route = FEATURE_ROUTES[feature]
            const label = FEATURE_LABELS[feature] ?? feature
            const isTessa = feature === 'tessa'
            if (!isTessa && (!Icon || !route)) return null

            return (
              <li key={feature}>
                <NavLink
                  to={route || '/tessa'}
                  end={feature === 'dashboard'}
                  className={({ isActive }) =>
                    `flex items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] font-medium transition-colors ${
                      isActive
                        ? 'bg-brand-600/10 text-brand-400'
                        : 'text-zinc-400 hover:bg-zinc-800/70 hover:text-zinc-200'
                    }`
                  }
                >
                  {isTessa ? (
                    <TessaIcon className="h-[16px] w-[16px] shrink-0" />
                  ) : (
                    Icon && <Icon className="h-[16px] w-[16px] shrink-0" />
                  )}
                  {label}
                </NavLink>
              </li>
            )
          })}
        </ul>
      </nav>

      {/* User */}
      <div className="border-t border-zinc-800 p-3 space-y-2">
        <div className="flex items-center gap-2.5">
          <Avatar name={user?.name || '?'} size="sm" />
          <div className="flex-1 min-w-0">
            <p className="truncate text-[13px] font-medium text-zinc-200">{user?.name}</p>
            <p className="truncate text-[10px] text-zinc-500 uppercase tracking-wider">
              {roleName || user?.role}
            </p>
          </div>
          <button
            onClick={logout}
            className="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-800 hover:text-red-400 transition-colors"
            title="Sign out"
          >
            <LogOut className="h-3.5 w-3.5" />
          </button>
        </div>
        <ChangePasswordButton />
      </div>
    </aside>
  )
}

/* ── Change Password — sourced from portal.js initChangePassword() lines 6535-6620 ── */

function ChangePasswordButton() {
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [newPw, setNewPw] = useState('')
  const [confirm, setConfirm] = useState('')
  const [status, setStatus] = useState('')
  const [isError, setIsError] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  function reset() {
    setCurrent('')
    setNewPw('')
    setConfirm('')
    setStatus('')
    setIsError(false)
  }

  function close() {
    setOpen(false)
    reset()
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (newPw !== confirm) {
      setStatus('New password and confirmation do not match.')
      setIsError(true)
      return
    }
    if (newPw.length < 8) {
      setStatus('New password must be at least 8 characters.')
      setIsError(true)
      return
    }
    setStatus('Saving...')
    setIsError(false)
    setSubmitting(true)
    try {
      const res = await authAPI.changePassword(current, newPw)
      if (res.data?.ok) {
        setStatus('Password changed successfully.')
        setIsError(false)
        setTimeout(close, 1500)
      } else {
        const data = res.data || {}
        let errMsg = 'Failed to change password.'
        if (data.errors) {
          const first = Object.keys(data.errors)[0]
          if (first && data.errors[first]?.[0]) errMsg = data.errors[first][0]
        } else if (data.message) {
          errMsg = data.message
        }
        setStatus(errMsg)
        setIsError(true)
      }
    } catch (err: any) {
      const data = err?.response?.data
      let errMsg = 'Unable to change password. Please try again.'
      if (data?.errors) {
        const first = Object.keys(data.errors)[0]
        if (first && data.errors[first]?.[0]) errMsg = data.errors[first][0]
      } else if (data?.message) {
        errMsg = data.message
      }
      setStatus(errMsg)
      setIsError(true)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <button
        onClick={() => setOpen(true)}
        className="flex w-full items-center justify-center gap-1.5 rounded-md border border-zinc-700 bg-zinc-800/50 px-2 py-1.5 text-[11px] font-medium text-zinc-400 hover:bg-zinc-800 hover:text-zinc-300 transition-colors"
      >
        <KeyRound className="h-3 w-3" />
        Change Password
      </button>

      {/* Modal overlay */}
      {open && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
          onClick={(e) => { if (e.target === e.currentTarget) close() }}
        >
          <div className="w-full max-w-sm rounded-lg border border-zinc-800 bg-surface-1 shadow-xl">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-zinc-800 px-5 py-3">
              <h3 className="text-[14px] font-semibold text-zinc-100">Change Password</h3>
              <button onClick={close} className="text-zinc-500 hover:text-zinc-300 text-lg leading-none">&times;</button>
            </div>

            {/* Form */}
            <form onSubmit={handleSubmit} className="px-5 py-4 space-y-3">
              <div>
                <label className="block text-[11px] text-zinc-500 mb-1">Current Password</label>
                <input
                  type="password"
                  value={current}
                  onChange={(e) => setCurrent(e.target.value)}
                  required
                  className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 transition-colors"
                />
              </div>
              <div>
                <label className="block text-[11px] text-zinc-500 mb-1">New Password</label>
                <input
                  type="password"
                  value={newPw}
                  onChange={(e) => setNewPw(e.target.value)}
                  required
                  className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 transition-colors"
                />
              </div>
              <div>
                <label className="block text-[11px] text-zinc-500 mb-1">Confirm New Password</label>
                <input
                  type="password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  required
                  className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 transition-colors"
                />
              </div>

              {/* Status message */}
              {status && (
                <p className={`text-[12px] ${isError ? 'text-red-400' : 'text-emerald-400'}`}>
                  {status}
                </p>
              )}

              {/* Buttons */}
              <div className="flex items-center gap-2 pt-1">
                <button
                  type="button"
                  onClick={close}
                  className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] font-medium text-zinc-400 hover:bg-zinc-700 transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="rounded-md bg-brand-600 px-3 py-1.5 text-[12px] font-medium text-white hover:bg-brand-500 disabled:opacity-50 transition-colors"
                >
                  {submitting ? 'Saving...' : 'Change Password'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  )
}
