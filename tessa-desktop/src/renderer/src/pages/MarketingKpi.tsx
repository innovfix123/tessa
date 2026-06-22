import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { dailyReportsAPI, kpiAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { classNames, startOfWeek, addDays, formatDate, weekKey } from '@/lib/utils'
import toast from 'react-hot-toast'

interface MkpiPerson {
  id: number; name: string; role?: string; project?: string; reportingManager?: string
  fields?: Array<{ key: string; label: string; id?: number; group?: string }>
}

interface KpiField { key: string; label: string; id?: number }
interface KpiGroup { name: string; fields: KpiField[] }

function stripCommas(v: string): string { return v?.replace(/,/g, '') || '' }
function fmtNum(n: number): string { return isNaN(n) ? '' : n.toLocaleString('en-IN') }

export default function MarketingKpi(): JSX.Element {
  const { user } = useAuth()
  const portalConfig = (window as any).__PORTAL_CONFIG || {}
  const kpiConfig = portalConfig.kpi || {}
  const canManage = kpiConfig.canManage || false
  const canSetTarget = kpiConfig.canSetTarget || false
  const people: MkpiPerson[] = portalConfig.kpiDefinitions?.marketingKpiPeople || []

  const [ws, setWs] = useState(() => startOfWeek(new Date()))
  const [activeId, setActiveId] = useState<string>(() => people.length ? String(people[0].id) : String(user?.id || ''))
  const [current, setCurrent] = useState<Record<string, string>>({})
  const [previous, setPrevious] = useState<Record<string, string>>({})
  const [targets, setTargets] = useState<Record<string, string>>({})
  const [ceoNote, setCeoNote] = useState('')
  const [loading, setLoading] = useState(true)
  const [noteSaving, setNoteSaving] = useState(false)

  const wk = weekKey(ws)
  const prevWk = weekKey(addDays(ws, -7))
  const isCurrentWeek = Math.abs(ws.getTime() - startOfWeek(new Date()).getTime()) < 86400000
  const activePerson = people.find((p) => String(p.id) === activeId) || people[0]

  const groups: KpiGroup[] = (() => {
    if (!activePerson?.fields?.length) return []
    const map: Record<string, KpiGroup> = {}
    activePerson.fields.forEach((f) => {
      const g = f.group || 'General'
      if (!map[g]) map[g] = { name: g, fields: [] }
      map[g].fields.push(f)
    })
    return Object.values(map)
  })()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [curRes, prevRes, kpiRes] = await Promise.allSettled([
        dailyReportsAPI.list({ user_id: activeId, week_key: wk }),
        dailyReportsAPI.list({ user_id: activeId, week_key: prevWk }),
        kpiAPI.entries({ week_key: wk, user_id: activeId })
      ])
      if (curRes.status === 'fulfilled') setCurrent(curRes.value.data?.summary || {})
      if (prevRes.status === 'fulfilled') setPrevious(prevRes.value.data?.summary || {})
      if (kpiRes.status === 'fulfilled') {
        const d = kpiRes.value.data?.data || {}
        setTargets(d.targets || {})
        setCeoNote(d.ceoNote || '')
      }
    } catch { toast.error('Failed to load Marketing KPIs') }
    finally { setLoading(false) }
  }, [activeId, wk, prevWk])

  useEffect(() => { load() }, [load])

  async function handleTargetSave(fieldKey: string, value: string) {
    try {
      await kpiAPI.postEntry({ action: 'save_target', userId: activeId, weekKey: wk, fieldKey, value })
      toast.success('Target saved')
    } catch { toast.error('Failed to save') }
  }

  async function saveCeoNote() {
    setNoteSaving(true)
    try {
      await kpiAPI.postEntry({ action: 'save_ceo_note', userId: activeId, weekKey: wk, note: ceoNote })
      toast.success('CEO Note saved')
    } catch { toast.error('Failed to save note') }
    finally { setNoteSaving(false) }
  }

  const weekRange = `${formatDate(ws)} — ${formatDate(addDays(ws, 4))}`

  if (loading && people.length === 0) return <Loader label="Loading Marketing KPIs..." />

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-zinc-100">Marketing KPIs</h2>
        <div className="flex items-center gap-2">
          <button onClick={() => setWs(addDays(ws, -7))} className="h-8 w-8 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors">←</button>
          <div className={classNames('text-sm text-center min-w-[180px]', isCurrentWeek ? 'text-zinc-300' : 'text-zinc-500')}>
            {weekRange}
            {isCurrentWeek && <span className="ml-2 text-[10px] bg-brand-600/20 text-brand-400 rounded px-1.5 py-0.5">This week</span>}
          </div>
          <button onClick={() => setWs(addDays(ws, 7))} disabled={isCurrentWeek} className="h-8 w-8 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors disabled:opacity-30">→</button>
        </div>
      </div>

      {/* Person table */}
      {people.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-zinc-800">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-surface-3">
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-400">Name</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-400">Role</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-400">Project</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-zinc-400">Reporting Manager</th>
              </tr>
            </thead>
            <tbody>
              {people.map((p) => (
                <tr
                  key={p.id}
                  onClick={() => setActiveId(String(p.id))}
                  className={classNames(
                    'border-t border-zinc-800/50 cursor-pointer transition-colors',
                    String(p.id) === activeId ? 'bg-brand-600/10 text-brand-400' : 'hover:bg-surface-1 text-zinc-300'
                  )}
                >
                  <td className="px-3 py-2 font-medium">{p.name}</td>
                  <td className="px-3 py-2 text-xs text-zinc-500">{p.role || '—'}</td>
                  <td className="px-3 py-2 text-xs text-zinc-500">{p.project || '—'}</td>
                  <td className="px-3 py-2 text-xs text-zinc-500">{p.reportingManager || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* KPI cards */}
      {groups.length === 0 ? (
        <div className="text-sm text-zinc-500 text-center py-8">No KPIs for this person.</div>
      ) : (
        groups.map((group) => (
          <div key={group.name}>
            <div className="text-sm font-semibold text-zinc-300 mb-2">{group.name}</div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {group.fields.map((f) => {
                const actualNum = parseFloat(stripCommas(current[f.key] || ''))
                const targetNum = parseFloat(stripCommas(targets[f.key] || ''))
                const hasActual = current[f.key] !== undefined && !isNaN(actualNum)
                const hasTarget = targets[f.key] !== undefined && !isNaN(targetNum) && targetNum > 0
                let pct = 0, barCls = 'bg-zinc-600', badge = 'No target'
                if (hasActual && hasTarget) {
                  pct = Math.min(Math.round((actualNum / targetNum) * 100), 100)
                  if (actualNum >= targetNum) { barCls = 'bg-emerald-500'; badge = 'Target met' }
                  else { barCls = 'bg-red-500'; badge = `${pct}% of target` }
                } else if (hasActual) { pct = 100 }

                return (
                  <article key={f.key} className="card">
                    <div className="text-xs font-medium text-zinc-400 mb-1">{f.label}</div>
                    <div className={classNames('text-2xl font-bold mb-1', hasActual ? 'text-zinc-100' : 'text-zinc-600')}>
                      {hasActual ? fmtNum(actualNum) : '—'}
                    </div>
                    <div className="flex items-center gap-2 mb-2">
                      {hasTarget && <span className="text-xs text-zinc-500">Target: {targets[f.key]}</span>}
                      <span className={classNames('rounded-full px-2 py-0.5 text-[10px] font-medium',
                        badge === 'Target met' ? 'bg-emerald-500/15 text-emerald-400' :
                        badge.includes('%') ? 'bg-red-500/15 text-red-400' : 'bg-zinc-700/50 text-zinc-500'
                      )}>{badge}</span>
                    </div>
                    <div className="h-1.5 rounded-full bg-zinc-800 overflow-hidden mb-2">
                      <div className={`h-full rounded-full ${barCls}`} style={{ width: `${pct}%` }} />
                    </div>
                    <div className="text-[11px] text-zinc-500">Prev: {previous[f.key] || '—'}</div>
                    {canSetTarget && (
                      <div className="mt-2 pt-2 border-t border-zinc-800">
                        <input defaultValue={targets[f.key] || ''} onBlur={(e) => handleTargetSave(f.key, e.target.value)}
                          placeholder="Set target" className="w-full bg-surface-3 border border-zinc-700 rounded px-2 py-1 text-xs text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500/30" />
                      </div>
                    )}
                  </article>
                )
              })}
            </div>
          </div>
        ))
      )}

      {/* CEO Note */}
      <div className="card">
        <h4 className="text-sm font-semibold text-zinc-300 mb-2">CEO Note</h4>
        <textarea
          value={ceoNote}
          onChange={(e) => setCeoNote(e.target.value)}
          rows={4}
          placeholder="Add weekly feedback..."
          className="textarea-field mb-2"
        />
        <button onClick={saveCeoNote} disabled={noteSaving} className="btn-primary text-xs">
          {noteSaving ? 'Saving...' : 'Save CEO Note'}
        </button>
      </div>
    </div>
  )
}
