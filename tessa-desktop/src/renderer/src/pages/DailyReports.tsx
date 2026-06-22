import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { dailyReportsAPI, kpiAPI, kpiAPI as kpiDefAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import ScriptPanel from '@/components/dailyreports/ScriptPanel'
import UploadPanel from '@/components/dailyreports/UploadPanel'
import { classNames, startOfWeek, addDays, formatDate, weekKey } from '@/lib/utils'
import toast from 'react-hot-toast'

interface DayCol {
  date: Date; dateKey: string; label: string; dateLabel: string
  editable: boolean; isToday: boolean; entries: Record<string, string>
}

interface Field {
  key: string; label: string; group: string
  auto_sync?: boolean; input_type?: string
  upload_accept?: string; upload_max_mb?: number; is_team_total?: boolean
}

function fmtDateKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function stripCommas(v: string): string { return typeof v === 'string' ? v.replace(/,/g, '') : v }
function fmtNum(n: number): string { return isNaN(n) ? '' : n.toLocaleString('en-IN') }

function computeSummary(
  days: DayCol[], fields: Field[], aggMap: Record<string, string>
): Record<string, string> {
  const result: Record<string, string> = {}
  fields.forEach((f) => {
    const agg = aggMap[f.key] || 'sum'
    const vals: number[] = []
    days.forEach((d) => {
      const raw = stripCommas(String(d.entries[f.key] ?? ''))
      if (raw !== '' && !isNaN(parseFloat(raw))) vals.push(parseFloat(raw))
    })
    if (!vals.length) { result[f.key] = ''; return }
    let total: number
    if (agg === 'sum') total = vals.reduce((a, b) => a + b, 0)
    else if (agg === 'avg') total = Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 100) / 100
    else total = vals[vals.length - 1]
    result[f.key] = fmtNum(total)
  })
  return result
}

function aggLabel(key: string, aggMap: Record<string, string>): string {
  const a = aggMap[key] || 'sum'
  if (a === 'sum') return 'Sum'
  if (a === 'avg') return 'Avg'
  return 'Latest'
}

export default function DailyReports(): JSX.Element {
  const { user, people, kpiDefinitions } = useAuth()
  const portalConfig = (window as any).__PORTAL_CONFIG || {}
  const dailyConfig = portalConfig.dailyReports || {}
  const editable = dailyConfig.editable !== false
  const label = dailyConfig.label || 'Reports'

  const [currentDate, setCurrentDate] = useState(() => new Date())
  const [teamMembers, setTeamMembers] = useState<Array<{ id: number; name: string }>>(() => dailyConfig.teamMembers || [])
  const [activePerson, setActivePerson] = useState<string>(() => {
    const tm = dailyConfig.teamMembers || []
    return tm.length > 1 ? String(tm[0]?.id || user?.id || '') : String(dailyConfig.userId || user?.id || '')
  })
  const [loading, setLoading] = useState(true)
  const [days, setDays] = useState<DayCol[]>([])
  const [fields, setFields] = useState<Field[]>([])
  const [aggMap, setAggMap] = useState<Record<string, string>>({})
  const [metaHints, setMetaHints] = useState<Record<string, Record<string, string>>>({})
  const [targets, setTargets] = useState<Record<string, string>>({})
  const [saveStatus, setSaveStatus] = useState('')
  const [openPanel, setOpenPanel] = useState<{ type: 'upload' | 'textarea'; fieldKey: string; fieldLabel: string; dateKey: string; accept?: string; maxMb?: number } | null>(null)

  const hasTeam = teamMembers.length > 1
  const userId = activePerson || String(user?.id || '')

  // Fetch team members from employees API if portal config didn't provide them
  useEffect(() => {
    if (teamMembers.length > 0) return
    ;(async () => {
      try {
        const { employeesAPI } = await import('../api/client')
        const res = await employeesAPI.list()
        const employees = res.data?.employees || res.data || []
        if (Array.isArray(employees) && employees.length > 1) {
          const members = employees.map((e: any) => ({ id: e.id, name: e.name }))
          setTeamMembers(members)
          setActivePerson(String(members[0]?.id || user?.id || ''))
          return
        }
      } catch { /* employees API failed */ }

      // Fallback: try KPI definitions for person data
      try {
        const res = await kpiDefAPI.definitions({ user_id: String(user?.id || '') })
        const defs = res.data?.definitions || {}
        const byPerson = defs.kpiGroupsByPerson || {}
        const personIds = Object.keys(byPerson)
        if (personIds.length > 1) {
          const members = personIds.map((pid) => ({ id: Number(pid), name: byPerson[pid]?.name || String(pid) }))
          setTeamMembers(members)
          setActivePerson(String(members[0]?.id || user?.id || ''))
        }
      } catch { /* no team members available */ }
    })()
  }, [user?.id])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const ws = startOfWeek(currentDate)
      const wk = weekKey(ws)
      const [weekRes, kpiRes] = await Promise.allSettled([
        dailyReportsAPI.list({ user_id: userId, week_key: wk }),
        kpiAPI.entries({ week_key: wk, user_id: userId })
      ])

      if (weekRes.status === 'fulfilled') {
        const d = weekRes.value.data
        const daysArr: Array<{ reportDate: string; entries: Record<string, string> }> = d?.days || []
        const dayMap: Record<string, Record<string, string>> = {}
        daysArr.forEach((day) => { dayMap[day.reportDate] = day.entries || {} })
        const todayStr = fmtDateKey(new Date())

        const cols: DayCol[] = []
        for (let i = 0; i < 7; i++) {
          const dt = addDays(ws, i)
          const dk = fmtDateKey(dt)
          cols.push({
            date: dt, dateKey: dk,
            label: dt.toLocaleDateString('en-IN', { weekday: 'short' }),
            dateLabel: dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }),
            editable, isToday: dk === todayStr,
            entries: dayMap[dk] || {}
          })
        }
        setDays(cols)
        setFields(d?.fields || [])
        setAggMap(d?.aggregation || {})
        setMetaHints(d?.metaHints || {})
      }

      if (kpiRes.status === 'fulfilled') {
        setTargets(kpiRes.value.data?.data?.targets || {})
      }
    } catch {
      toast.error('Failed to load daily reports')
    } finally {
      setLoading(false)
    }
  }, [currentDate, userId])

  useEffect(() => { load() }, [load])

  async function saveEntry(dateKey: string, fieldKey: string, value: string) {
    setSaveStatus('Saving...')
    try {
      await dailyReportsAPI.submit({
        action: 'save_entry', userId, reportDate: dateKey, fieldKey, value
      })
      setSaveStatus('Saved')
      setTimeout(() => setSaveStatus(''), 1500)
      load()
    } catch (e: any) {
      setSaveStatus(e?.message || 'Save failed')
    }
  }

  const ws = startOfWeek(currentDate)
  const wk = weekKey(ws)

  function fmtWeekDate(d: Date): string {
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
  }
  const weekRange = `${fmtWeekDate(ws)} — ${fmtWeekDate(addDays(ws, 6))}`

  const activePersonName = teamMembers.find((m) => String(m.id) === activePerson)?.name || user?.name || ''

  const groups: Array<{ name: string; fields: Field[] }> = []
  let currentGroup = ''
  fields.forEach((f) => {
    if (f.group !== currentGroup) {
      groups.push({ name: f.group, fields: [] })
      currentGroup = f.group
    }
    groups[groups.length - 1]?.fields.push(f)
  })

  const summary = computeSummary(days, fields, aggMap)

  function togglePanel(type: 'upload' | 'textarea', fieldKey: string, fieldLabel: string, dateKey: string, accept?: string, maxMb?: number) {
    if (openPanel && openPanel.fieldKey === fieldKey && openPanel.dateKey === dateKey) {
      setOpenPanel(null)
    } else {
      setOpenPanel({ type, fieldKey, fieldLabel, dateKey, accept, maxMb })
    }
  }

  if (loading && fields.length === 0) return <Loader label="Loading daily reports..." />

  return (
    <div className="space-y-3">
      {/* Person strip at top — matching portal layout */}
      {hasTeam && (
        <div className="flex gap-2 flex-wrap">
          {teamMembers.map((m) => (
            <button
              key={m.id}
              onClick={() => setActivePerson(String(m.id))}
              className={classNames(
                'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                String(m.id) === activePerson
                  ? 'bg-brand-600 text-white'
                  : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700'
              )}
            >
              {m.name}
            </button>
          ))}
        </div>
      )}

      {/* Header + date nav — matching portal: title left, arrows+date right */}
      <div className="flex items-start justify-between">
        <div>
          <h2 className="text-xl font-bold text-zinc-100">
            Daily Reports — {activePersonName}{label ? ` (${label})` : ''}
          </h2>
          <p className="text-xs text-zinc-500 mt-1">Click any editable cell to update. Weekly KPIs auto-calculate from this table.</p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button onClick={() => setCurrentDate(addDays(ws, -7))}
            className="h-9 w-9 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors">←</button>
          <div className="text-sm text-zinc-300 font-medium min-w-[200px] text-center">{weekRange}</div>
          <button onClick={() => setCurrentDate(addDays(ws, 7))}
            className="h-9 w-9 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors">→</button>
        </div>
      </div>

      <div className="text-xs text-zinc-500">
        {saveStatus === 'Saved' ? <span className="text-emerald-400">Saved</span> : 'Auto-saves on blur. Click any cell to update.'}
      </div>

      {/* Table */}
      {fields.length === 0 ? (
        <div className="text-sm text-zinc-500 text-center py-12">No daily report fields configured.</div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-zinc-800">
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr className="bg-surface-3">
                <th className="px-3 py-2.5 text-left text-[10px] font-bold text-zinc-500 uppercase tracking-wider sticky left-0 bg-surface-3 z-10 min-w-[180px]">Metric</th>
                {days.map((d) => (
                  <th key={d.dateKey} className={classNames(
                    'px-2 py-2.5 text-center min-w-[85px]',
                    d.isToday && 'bg-brand-600/5'
                  )}>
                    <div className="text-[11px] font-bold text-zinc-300 uppercase">{d.label}</div>
                    <div className="text-[10px] text-zinc-500">{d.dateLabel}</div>
                    {d.editable && <div className="text-[9px] text-emerald-400 font-semibold uppercase mt-0.5">Editable</div>}
                  </th>
                ))}
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-zinc-300 uppercase min-w-[100px]">Weekly</th>
              </tr>
            </thead>
            <tbody>
              {groups.map((group) => (
                <GroupRows
                  key={group.name}
                  group={group}
                  days={days}
                  summary={summary}
                  targets={targets}
                  aggMap={aggMap}
                  metaHints={metaHints}
                  userId={userId}
                  editable={editable}
                  onSave={saveEntry}
                  onTogglePanel={togglePanel}
                  colCount={days.length + 2}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Upload / Script Panel — rendered below the table */}
      {openPanel && openPanel.type === 'textarea' && (
        <ScriptPanel
          userId={userId}
          fieldKey={openPanel.fieldKey}
          fieldLabel={openPanel.fieldLabel}
          reportDate={openPanel.dateKey}
          onClose={() => setOpenPanel(null)}
          onChanged={load}
        />
      )}
      {openPanel && openPanel.type === 'upload' && (
        <UploadPanel
          userId={userId}
          fieldKey={openPanel.fieldKey}
          fieldLabel={openPanel.fieldLabel}
          reportDate={openPanel.dateKey}
          acceptAttr={openPanel.accept}
          maxMb={openPanel.maxMb}
          onClose={() => setOpenPanel(null)}
          onChanged={load}
        />
      )}
    </div>
  )
}

function GroupRows({
  group, days, summary, targets, aggMap, metaHints, userId, editable, onSave, onTogglePanel, colCount
}: {
  group: { name: string; fields: Field[] }
  days: DayCol[]; summary: Record<string, string>; targets: Record<string, string>
  aggMap: Record<string, string>; metaHints: Record<string, Record<string, string>>
  userId: string; editable: boolean; onSave: (dk: string, fk: string, v: string) => void
  onTogglePanel: (type: 'upload' | 'textarea', fk: string, label: string, dk: string, accept?: string, maxMb?: number) => void
  colCount: number
}): JSX.Element {
  return (
    <>
      <tr className="border-t border-zinc-800">
        <td colSpan={colCount} className="px-3 py-2 text-xs font-bold text-zinc-300 uppercase tracking-wider bg-surface-1">
          {group.name}
        </td>
      </tr>
      {group.fields.map((f) => {
        const sumVal = summary[f.key] || ''
        const tgt = targets[f.key] || ''
        const sumNum = parseFloat(stripCommas(sumVal))
        const tgtNum = parseFloat(stripCommas(tgt))
        const hasSum = sumVal !== '' && !isNaN(sumNum)
        const hasTgt = tgt !== '' && !isNaN(tgtNum) && tgtNum > 0
        let pct = 0, barCls = 'bg-zinc-600'
        let badgeHtml = ''
        if (hasSum && hasTgt) {
          pct = Math.min(Math.round((sumNum / tgtNum) * 100), 100)
          if (sumNum >= tgtNum) { barCls = 'bg-emerald-500'; badgeHtml = '✓ Met' }
          else { barCls = 'bg-red-500'; badgeHtml = `${pct}%` }
        } else if (hasSum) { pct = 100; barCls = 'bg-zinc-600' }

        const isLocked = f.auto_sync || f.input_type === 'upload' || f.input_type === 'textarea' || f.input_type === 'status'
        const fieldHints = metaHints[f.key] || {}

        return (
          <tr key={f.key} className="border-t border-zinc-800/50 hover:bg-surface-1/50">
            <td className="px-3 py-2 sticky left-0 bg-surface-0 z-10">
              <div className="flex items-center gap-1.5">
                <span className="text-xs text-zinc-300">{f.label}</span>
                {f.auto_sync && <span className="text-[9px] bg-blue-500/15 text-blue-400 rounded px-1.5 py-0.5 font-medium">API</span>}
                {f.input_type === 'upload' && <span className="text-[9px] bg-orange-500/15 text-orange-400 rounded px-1.5 py-0.5 font-medium">Upload</span>}
                {f.input_type === 'textarea' && <span className="text-[9px] bg-emerald-500/15 text-emerald-400 rounded px-1.5 py-0.5 font-medium">Script</span>}
                {f.input_type === 'status' && <span className="text-[9px] bg-purple-500/15 text-purple-400 rounded px-1.5 py-0.5 font-medium">Status</span>}
              </div>
              {f.is_team_total && (
                <div className="text-[9px] text-zinc-600 mt-0.5">Team total — only add your own work, team uploads auto-counted</div>
              )}
            </td>

            {days.map((d) => {
              const val = d.entries[f.key] ?? ''
              const numVal = parseInt(stripCommas(String(val)), 10)
              const hasContent = val !== '' && !isNaN(numVal) && numVal > 0

              // Status cells — clickable Done / Not Done toggle
              if (f.input_type === 'status') {
                const isDone = String(val).toLowerCase() === 'done'
                return (
                  <td key={d.dateKey} className={classNames('px-1 py-1.5 text-center', d.isToday && 'bg-brand-600/5')}>
                    <button
                      onClick={() => onSave(d.dateKey, f.key, isDone ? '' : 'Done')}
                      className={classNames(
                        'rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors w-full',
                        isDone
                          ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25'
                          : 'bg-zinc-800 text-zinc-500 border border-zinc-700 hover:border-zinc-600'
                      )}>
                      {isDone ? '✓ Done' : '—'}
                    </button>
                  </td>
                )
              }

              // Upload cells — show colored button with file count, click opens panel
              if (f.input_type === 'upload') {
                return (
                  <td key={d.dateKey} className={classNames('px-1 py-1.5 text-center', d.isToday && 'bg-brand-600/5')}>
                    <button
                      onClick={() => onTogglePanel('upload', f.key, f.label, d.dateKey, f.upload_accept, f.upload_max_mb)}
                      className={classNames(
                        'rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors w-full',
                        hasContent
                          ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25'
                          : 'bg-zinc-800 text-zinc-500 border border-zinc-700 hover:border-zinc-600'
                      )}>
                      {hasContent ? `${numVal} file${numVal !== 1 ? 's' : ''} ▾` : 'Upload ▾'}
                    </button>
                  </td>
                )
              }

              // Textarea cells — show colored button with script count, click opens panel
              if (f.input_type === 'textarea') {
                return (
                  <td key={d.dateKey} className={classNames('px-1 py-1.5 text-center', d.isToday && 'bg-brand-600/5')}>
                    <button
                      onClick={() => onTogglePanel('textarea', f.key, f.label, d.dateKey)}
                      className={classNames(
                        'rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors w-full',
                        hasContent
                          ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25'
                          : 'bg-zinc-800 text-zinc-500 border border-zinc-700 hover:border-zinc-600'
                      )}>
                      {hasContent ? `${numVal} script${numVal !== 1 ? 's' : ''} ▾` : 'Add ▾'}
                    </button>
                  </td>
                )
              }

              // Auto-sync locked cells
              if (f.auto_sync) {
                return (
                  <td key={d.dateKey} className={classNames(
                    'px-2 py-1.5 text-center text-xs',
                    d.isToday && 'bg-brand-600/5',
                    val ? 'text-zinc-300' : 'text-zinc-600'
                  )}>
                    {val ? fmtNum(parseFloat(stripCommas(String(val)))) || val : '—'}
                  </td>
                )
              }

              // Editable text input cells
              return (
                <td key={d.dateKey} className={classNames(
                  'px-1 py-1 text-center',
                  d.isToday && 'bg-brand-600/5'
                )}>
                  <input
                    defaultValue={val}
                    onBlur={(e) => {
                      if (e.target.value !== val) onSave(d.dateKey, f.key, e.target.value)
                    }}
                    placeholder="—"
                    className="w-full bg-transparent border border-transparent hover:border-zinc-700 focus:border-brand-600 rounded px-1.5 py-1 text-xs text-zinc-300 text-center focus:outline-none focus:ring-1 focus:ring-brand-500/30"
                  />
                  {fieldHints[d.dateKey] && (
                    <div className="text-[9px] text-zinc-600 mt-0.5" title="From Meta Ads upload">
                      {fieldHints[d.dateKey]}
                    </div>
                  )}
                </td>
              )
            })}

            {/* Summary cell */}
            <td className="px-2 py-1.5 text-center">
              <div className="text-xs font-medium text-zinc-200">{sumVal || '—'}</div>
              {hasTgt && (
                <div className="text-[10px] text-zinc-500">/ {tgt}</div>
              )}
              {badgeHtml && (
                <span className={classNames(
                  'inline-block rounded-full px-1.5 py-0.5 text-[9px] font-medium mt-0.5',
                  badgeHtml === '✓ Met' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'
                )}>
                  {badgeHtml}
                </span>
              )}
              {(hasSum || hasTgt) && (
                <div className="mt-1 h-1 rounded-full bg-zinc-800 overflow-hidden">
                  <div className={`h-full rounded-full ${barCls}`} style={{ width: `${pct}%` }} />
                </div>
              )}
              <div className="text-[9px] text-zinc-600 mt-0.5">{aggLabel(f.key, aggMap)}</div>
            </td>
          </tr>
        )
      })}
    </>
  )
}
