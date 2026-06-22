import { useState, useEffect, useCallback, useMemo } from 'react'
import { revenueAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import type { RevenueRow } from '@/lib/types'
import { classNames } from '@/lib/utils'

/* ── Computed row after applying GST / profit logic ── */

interface DailyEntry {
  date: string
  dayLabel: string
  grossRevenue: number
  revenueGst: number
  netRevenue: number
  googleSpend: number
  metaSpendBase: number
  metaGst: number
  metaSpendTotal: number
  totalAdsSpend: number
  creatorPayout: number
  agoraCost: number
  profit: number
}

interface WeekEntry {
  key: string
  label: string
  grossRevenue: number
  revenueGst: number
  netRevenue: number
  googleSpend: number
  metaSpendTotal: number
  metaGst: number
  totalAdsSpend: number
  creatorPayout: number
  agoraCost: number
  profit: number
  days: number
}

type ViewMode = 'daily' | 'weekly'

/* ── Helpers ── */

function fmt(n: number): string {
  return '₹' + Number(Math.round(n)).toLocaleString('en-IN')
}

function computeDaily(rows: RevenueRow[]): DailyEntry[] {
  const daily = rows.map((r) => {
    const dateStr = (r.date || '').substring(0, 10)
    const d = new Date(dateStr + 'T00:00:00')
    const grossRevenue = Number(r.revenue || 0)
    const revenueGst = (grossRevenue * 18) / 118 // GST included in revenue
    const netRevenue = grossRevenue - revenueGst
    const googleSpend = Number(r.google_spend || 0)
    const metaSpendBase = Number(r.meta_spend || 0)
    const metaGst = metaSpendBase * 0.18 // 18% GST on Meta
    const metaSpendTotal = metaSpendBase + metaGst
    const totalAdsSpend = googleSpend + metaSpendTotal
    const creatorPayout = Number(r.payout_paid || 0)
    const agoraCost = Number(r.agora_cost_inr || 0)
    const profit = netRevenue - totalAdsSpend - creatorPayout - agoraCost

    return {
      date: dateStr,
      dayLabel: d.toLocaleDateString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
      }),
      grossRevenue,
      revenueGst,
      netRevenue,
      googleSpend,
      metaSpendBase,
      metaGst,
      metaSpendTotal,
      totalAdsSpend,
      creatorPayout,
      agoraCost,
      profit
    }
  })

  // Sort ascending by date (portal logic)
  daily.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0))
  return daily
}

function groupWeekly(daily: DailyEntry[]): WeekEntry[] {
  const weeks: WeekEntry[] = []
  let currentWeek: WeekEntry | null = null

  daily.forEach((r) => {
    const d = new Date(r.date + 'T00:00:00')
    const day = d.getDay()
    const mon = new Date(d)
    mon.setDate(mon.getDate() - ((day + 6) % 7))
    const weekKey = mon.toISOString().split('T')[0]

    if (!currentWeek || currentWeek.key !== weekKey) {
      currentWeek = {
        key: weekKey,
        label: mon.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }),
        grossRevenue: 0,
        revenueGst: 0,
        netRevenue: 0,
        googleSpend: 0,
        metaSpendTotal: 0,
        metaGst: 0,
        totalAdsSpend: 0,
        creatorPayout: 0,
        agoraCost: 0,
        profit: 0,
        days: 0
      }
      weeks.push(currentWeek)
    }

    currentWeek.grossRevenue += r.grossRevenue
    currentWeek.revenueGst += r.revenueGst
    currentWeek.netRevenue += r.netRevenue
    currentWeek.googleSpend += r.googleSpend
    currentWeek.metaSpendTotal += r.metaSpendTotal
    currentWeek.metaGst += r.metaGst
    currentWeek.totalAdsSpend += r.totalAdsSpend
    currentWeek.creatorPayout += r.creatorPayout
    currentWeek.agoraCost += r.agoraCost
    currentWeek.profit += r.profit
    currentWeek.days++
  })

  return weeks
}

/* ── Column header config ── */

const COLUMNS = [
  { label: 'Gross Revenue', cls: 'text-emerald-400' },
  { label: 'GST (18%)', cls: 'text-zinc-400' },
  { label: 'Net Revenue', cls: 'text-emerald-400' },
  { label: 'Google Spend', cls: 'text-red-400' },
  { label: 'Meta + GST', cls: 'text-red-400' },
  { label: 'Total Ads', cls: 'text-red-400' },
  { label: 'Creator Payout', cls: 'text-blue-400' },
  { label: 'Agora Cost', cls: 'text-zinc-400' },
  { label: 'Profit', cls: 'text-purple-400' }
] as const

/* ── Component ── */

export default function Revenue(): JSX.Element {
  const [rows, setRows] = useState<RevenueRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [viewMode, setViewMode] = useState<ViewMode>('daily')

  // Calculate dynamic start date (30 days ago)
  const startDate = useMemo(() => {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().split('T')[0]
  }, [])

  const startDateLabel = useMemo(() => {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })
  }, [])

  const fetchData = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await revenueAPI.dailyPayout({ from: startDate })
      setRows(res.data?.rows || [])
    } catch {
      setError('Failed to load revenue data. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [startDate])

  useEffect(() => {
    fetchData()
  }, [fetchData])

  const daily = useMemo(() => computeDaily(rows), [rows])
  const weeks = useMemo(() => groupWeekly(daily), [daily])

  /* ── Loading / Error / Empty states ── */

  if (loading) {
    return <Loader label="Loading revenue data..." />
  }

  if (error) {
    return (
      <div className="flex items-center justify-center py-16">
        <p className="text-sm text-red-400">{error}</p>
      </div>
    )
  }

  if (!rows.length) {
    return (
      <div className="flex items-center justify-center py-16">
        <p className="text-sm text-zinc-500">
          No revenue data available from {startDateLabel} onwards.
        </p>
      </div>
    )
  }

  /* ── Data for current view ── */

  const data: (DailyEntry | WeekEntry)[] = viewMode === 'weekly' ? weeks : daily
  const dateHeader = viewMode === 'weekly' ? 'Week Of' : 'Date'

  /* ── Render ── */

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
        <div>
          <h2 className="text-xl font-bold text-zinc-100">
            Revenue &amp; Payout Dashboard
          </h2>
          <p className="text-sm text-zinc-500">
            {startDateLabel} onwards &middot; {daily.length} days
          </p>
        </div>

        {/* Toggle */}
        <div className="flex rounded-lg bg-zinc-800/60 p-0.5">
          <ToggleButton
            active={viewMode === 'daily'}
            onClick={() => setViewMode('daily')}
          >
            Daily
          </ToggleButton>
          <ToggleButton
            active={viewMode === 'weekly'}
            onClick={() => setViewMode('weekly')}
          >
            Weekly
          </ToggleButton>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-lg border border-zinc-800">
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr className="bg-zinc-800/80 text-xs uppercase tracking-wider">
              <th className="px-3 py-2.5 text-left font-semibold text-zinc-400">
                {dateHeader}
              </th>
              {COLUMNS.map((col) => (
                <th
                  key={col.label}
                  className={classNames(
                    'px-3 py-2.5 text-right font-semibold',
                    col.cls
                  )}
                >
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-800/50">
            {/* Reverse chronological — newest first */}
            {[...data].reverse().map((row, idx) => {
              const label =
                viewMode === 'weekly'
                  ? `${(row as WeekEntry).label} (${(row as WeekEntry).days}d)`
                  : (row as DailyEntry).dayLabel

              return (
                <tr
                  key={idx}
                  className="hover:bg-zinc-800/40 transition-colors"
                >
                  <td className="px-3 py-2 text-zinc-300 whitespace-nowrap">
                    {label}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-200 whitespace-nowrap">
                    {fmt(row.grossRevenue)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-400 whitespace-nowrap">
                    {fmt(row.revenueGst)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-200 whitespace-nowrap">
                    {fmt(row.netRevenue)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">
                    {fmt(row.googleSpend)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">
                    {fmt(row.metaSpendTotal)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">
                    {fmt(row.totalAdsSpend)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">
                    {fmt(row.creatorPayout)}
                  </td>
                  <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">
                    {fmt(row.agoraCost)}
                  </td>
                  <td
                    className={classNames(
                      'px-3 py-2 text-right font-semibold whitespace-nowrap',
                      row.profit >= 0 ? 'text-emerald-400' : 'text-red-400'
                    )}
                  >
                    {fmt(row.profit)}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ── Toggle Button ── */

function ToggleButton({
  active,
  onClick,
  children
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      className={classNames(
        'px-4 py-1.5 text-xs font-medium rounded-md transition-colors',
        active
          ? 'bg-brand-600 text-white shadow-sm'
          : 'text-zinc-400 hover:text-zinc-200'
      )}
    >
      {children}
    </button>
  )
}
