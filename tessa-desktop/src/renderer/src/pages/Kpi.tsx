import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { dailyReportsAPI, kpiAPI } from '@/api/client'
import { Loader, Modal } from '@/components/ui'
import { classNames, startOfWeek, addDays, formatDate, weekKey } from '@/lib/utils'
import { Pencil, Trash2 } from 'lucide-react'
import toast from 'react-hot-toast'

interface KpiField { key: string; label: string; id?: number }
interface KpiGroup { name: string; fields: KpiField[] }
interface PersonData { name: string; role?: string; projectName?: string; reportingManager?: string; groups: KpiGroup[] }

function stripCommas(v: string): string { return v?.replace(/,/g, '') || '' }
function fmtNum(n: number): string { return isNaN(n) ? '' : n.toLocaleString('en-IN') }

function KpiCard({
  f, actual, target, prev, canSetTarget, canManage, onTargetSave, onEdit, onDelete
}: {
  f: KpiField; actual: string; target: string; prev: string
  canSetTarget: boolean; canManage: boolean
  onTargetSave: (key: string, val: string) => void
  onEdit: (f: KpiField) => void; onDelete: (f: KpiField) => void
}) {
  const actualNum = parseFloat(stripCommas(actual))
  const targetNum = parseFloat(stripCommas(target))
  const hasActual = actual !== '' && !isNaN(actualNum)
  const hasTarget = target !== '' && !isNaN(targetNum) && targetNum > 0

  let status = 'neutral', badgeText = 'No target', pct = 0
  if (hasActual && hasTarget) {
    pct = Math.min(Math.round((actualNum / targetNum) * 100), 100)
    if (actualNum >= targetNum) { status = 'met'; badgeText = 'Target met' }
    else { status = 'not-met'; badgeText = `${Math.round((actualNum / targetNum) * 100)}% of target` }
  } else if (hasActual) { pct = 100; badgeText = 'No target set' }

  const barColor = status === 'met' ? 'bg-emerald-500' : status === 'not-met' ? 'bg-red-500' : 'bg-zinc-600'
  const badgeColor = status === 'met' ? 'bg-emerald-500/15 text-emerald-400' : status === 'not-met' ? 'bg-red-500/15 text-red-400' : 'bg-zinc-700/50 text-zinc-500'

  return (
    <article className="card">
      <div className="flex items-start justify-between mb-2">
        <div className="text-xs font-medium text-zinc-400">{f.label}</div>
        {canManage && f.id && (
          <div className="flex gap-1">
            <button onClick={() => onEdit(f)} className="p-0.5 text-zinc-600 hover:text-zinc-300 rounded"><Pencil className="h-3 w-3" /></button>
            <button onClick={() => onDelete(f)} className="p-0.5 text-zinc-600 hover:text-red-400 rounded"><Trash2 className="h-3 w-3" /></button>
          </div>
        )}
      </div>
      <div className={classNames('text-2xl font-bold mb-1', hasActual ? 'text-zinc-100' : 'text-zinc-600')}>
        {hasActual ? fmtNum(actualNum) : '—'}
      </div>
      <div className="flex items-center gap-2 mb-2">
        {hasTarget && <span className="text-xs text-zinc-500">Target: {target}</span>}
        <span className={classNames('rounded-full px-2 py-0.5 text-[10px] font-medium', badgeColor)}>{badgeText}</span>
      </div>
      <div className="h-1.5 rounded-full bg-zinc-800 overflow-hidden mb-2">
        <div className={`h-full rounded-full transition-all ${barColor}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="text-[11px] text-zinc-500">Prev: {prev || '—'}</div>
      <div className="text-[10px] text-zinc-600 mt-0.5">Actuals auto-computed from Daily Reports</div>
      {canSetTarget && (
        <div className="mt-2 pt-2 border-t border-zinc-800">
          <div className="text-[10px] text-zinc-500 mb-1">Target</div>
          <input
            defaultValue={target}
            onBlur={(e) => { if (e.target.value !== target) onTargetSave(f.key, e.target.value) }}
            placeholder="Set target"
            className="w-full bg-surface-3 border border-zinc-700 rounded px-2 py-1 text-xs text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500/30"
          />
        </div>
      )}
    </article>
  )
}

export default function Kpi(): JSX.Element {
  const { user } = useAuth()
  const portalConfig = (window as any).__PORTAL_CONFIG || {}
  const kpiConfig = portalConfig.kpi || {}
  const canManage = kpiConfig.canManage || false
  const canSetTarget = kpiConfig.canSetTarget || false

  const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()))
  const [activePerson, setActivePerson] = useState<string>(String(user?.id || ''))
  const [byPerson, setByPerson] = useState<Record<string, PersonData>>({})
  const [groups, setGroups] = useState<KpiGroup[]>([])
  const [current, setCurrent] = useState<Record<string, string>>({})
  const [previous, setPrevious] = useState<Record<string, string>>({})
  const [targets, setTargets] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalType, setModalType] = useState<'add_kpi' | 'add_group' | 'edit_label'>('add_kpi')
  const [editingField, setEditingField] = useState<KpiField | null>(null)
  const [modalGroupName, setModalGroupName] = useState('')

  const effectiveUserId = activePerson || String(user?.id || '')
  const wk = weekKey(weekStart)
  const prevWk = weekKey(addDays(weekStart, -7))
  const isCurrentWeek = Math.abs(weekStart.getTime() - startOfWeek(new Date()).getTime()) < 86400000

  // Load KPI definitions — portal config has ALL people (including those with no KPIs)
  const loadDefinitions = useCallback(async () => {
    // Re-read portal config fresh (it may have been set after initial render)
    const freshConfig = (window as any).__PORTAL_CONFIG || {}
    const freshKpiConfig = freshConfig.kpi || {}
    const pcDefs = freshConfig.kpiDefinitions || {}
    const pcByPerson: Record<string, PersonData> = pcDefs.kpiGroupsByPerson || {}

    if (Object.keys(pcByPerson).length > 0) {
      setByPerson(pcByPerson)
      const pIds = Object.keys(pcByPerson)
      const currentPerson = pIds.includes(activePerson) ? activePerson : pIds[0]
      if (currentPerson !== activePerson) setActivePerson(currentPerson)
      setGroups(pcByPerson[currentPerson]?.groups || [])
      return
    }

    // Fallback: fetch from API
    try {
      const userIds: number[] = freshKpiConfig.userIds || kpiConfig.userIds || []
      const params: Record<string, string> = userIds.length > 1
        ? { user_ids: userIds.join(',') }
        : { user_id: effectiveUserId }
      const res = await kpiAPI.definitions(params)
      const defs = res.data?.definitions || {}
      const kpiByPerson = defs.kpiGroupsByPerson || {}
      setByPerson(kpiByPerson)
      const pIds = Object.keys(kpiByPerson)
      if (pIds.length > 0) {
        const currentPerson = pIds.includes(activePerson) ? activePerson : pIds[0]
        if (currentPerson !== activePerson) setActivePerson(currentPerson)
        setGroups(kpiByPerson[currentPerson]?.groups || [])
      } else {
        setGroups(defs.kpiGroups || [])
      }
    } catch {
      setGroups(portalConfig.KPI_GROUPS || [])
    }
  }, [effectiveUserId])

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      const [curRes, prevRes, kpiRes] = await Promise.allSettled([
        dailyReportsAPI.list({ user_id: effectiveUserId, week_key: wk }),
        dailyReportsAPI.list({ user_id: effectiveUserId, week_key: prevWk }),
        kpiAPI.entries({ week_key: wk, user_id: effectiveUserId })
      ])
      if (curRes.status === 'fulfilled') setCurrent(curRes.value.data?.summary || {})
      if (prevRes.status === 'fulfilled') setPrevious(prevRes.value.data?.summary || {})
      if (kpiRes.status === 'fulfilled') setTargets(kpiRes.value.data?.data?.targets || {})
    } catch { toast.error('Failed to load KPIs') }
    finally { setLoading(false) }
  }, [effectiveUserId, wk, prevWk])

  useEffect(() => { loadDefinitions() }, [loadDefinitions])
  useEffect(() => { loadData() }, [loadData])

  // When activePerson changes, update groups from byPerson (may be empty for that person)
  useEffect(() => {
    if (Object.keys(byPerson).length > 0) {
      setGroups(byPerson[activePerson]?.groups || [])
    }
  }, [activePerson, byPerson])

  async function handleTargetSave(fieldKey: string, value: string) {
    try {
      await kpiAPI.postEntry({ action: 'save_target', userId: effectiveUserId, weekKey: wk, fieldKey, value })
      toast.success('Target saved')
      loadData()
    } catch { toast.error('Failed to save target') }
  }

  async function handleDeleteKpi(f: KpiField) {
    if (!confirm(`Delete KPI "${f.label}"?`)) return
    try {
      await kpiAPI.postDefinition({ action: 'delete', id: f.id })
      toast.success('KPI deleted')
      loadDefinitions()
      loadData()
    } catch { toast.error('Failed to delete') }
  }

  function handleEditKpi(f: KpiField) {
    setEditingField(f)
    setModalType('edit_label')
    setModalOpen(true)
  }

  function openAddKpi(groupName: string) {
    setModalGroupName(groupName)
    setModalType('add_kpi')
    setEditingField(null)
    setModalOpen(true)
  }

  function openAddGroup() {
    setModalType('add_group')
    setEditingField(null)
    setModalGroupName('')
    setModalOpen(true)
  }

  const personIds = Object.keys(byPerson)
  const hasMultiPerson = personIds.length > 1
  const weekEnd = addDays(weekStart, 4)
  const weekRange = `${formatDate(weekStart)} — ${formatDate(weekEnd)}`

  if (loading && groups.length === 0) return <Loader label="Loading KPIs..." />

  return (
    <div className="space-y-4">
      {hasMultiPerson && (
        <div className="flex gap-1.5 flex-wrap">
          {personIds.map((pid) => {
            const p = byPerson[pid]
            return (
              <button key={pid} onClick={() => setActivePerson(pid)}
                className={classNames(
                  'rounded-lg px-3 py-2 text-xs font-medium border transition-colors text-left',
                  pid === activePerson
                    ? 'bg-brand-600/10 text-brand-400 border-brand-500/30'
                    : 'bg-zinc-800 text-zinc-400 border-zinc-700 hover:border-zinc-600'
                )}>
                {p?.name || pid}
              </button>
            )
          })}
        </div>
      )}

      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-zinc-100">Team KPIs</h2>
        <div className="flex items-center gap-2">
          <button onClick={() => setWeekStart(addDays(weekStart, -7))}
            className="h-8 w-8 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors">←</button>
          <div className={classNames('text-sm text-center min-w-[180px]', isCurrentWeek ? 'text-zinc-300' : 'text-zinc-500')}>
            {weekRange}
            {isCurrentWeek && <span className="ml-2 text-[10px] bg-brand-600/20 text-brand-400 rounded px-1.5 py-0.5">This week</span>}
          </div>
          <button onClick={() => setWeekStart(addDays(weekStart, 7))} disabled={isCurrentWeek}
            className="h-8 w-8 flex items-center justify-center rounded-lg bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-100 transition-colors disabled:opacity-30">→</button>
          {!isCurrentWeek && (
            <button onClick={() => setWeekStart(startOfWeek(new Date()))} className="text-xs text-brand-400 hover:text-brand-300 ml-1">Today</button>
          )}
        </div>
      </div>

      <div className="text-xs text-zinc-500">Weekly actuals are auto-calculated from Daily Reports. Targets auto-save on blur.</div>

      {groups.length === 0 ? (
        <div className="text-sm text-zinc-500 text-center py-12">No KPIs assigned yet.</div>
      ) : (
        groups.map((group) => (
          <div key={group.name}>
            <div className="text-sm font-semibold text-zinc-300 mb-2">{group.name}</div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {group.fields.map((f) => (
                <KpiCard
                  key={f.key} f={f}
                  actual={current[f.key] || ''} target={targets[f.key] || ''} prev={previous[f.key] || ''}
                  canSetTarget={canSetTarget} canManage={canManage}
                  onTargetSave={handleTargetSave} onEdit={handleEditKpi} onDelete={handleDeleteKpi}
                />
              ))}
            </div>
            {canManage && (
              <button onClick={() => openAddKpi(group.name)} className="mt-2 text-xs text-brand-400 hover:text-brand-300">+ Add KPI</button>
            )}
          </div>
        ))
      )}

      {canManage && (
        <button onClick={openAddGroup} className="text-xs text-brand-400 hover:text-brand-300">+ Add Group</button>
      )}

      <KpiModal
        open={modalOpen} type={modalType} editingField={editingField} groupName={modalGroupName}
        userId={effectiveUserId}
        onClose={() => setModalOpen(false)}
        onDone={() => { setModalOpen(false); loadDefinitions(); loadData() }}
      />
    </div>
  )
}

function KpiModal({ open, type, editingField, groupName, userId, onClose, onDone }: {
  open: boolean; type: string; editingField: KpiField | null; groupName: string
  userId: string; onClose: () => void; onDone: () => void
}) {
  const [label, setLabel] = useState('')
  const [fieldKey, setFieldKey] = useState('')
  const [agg, setAgg] = useState('sum')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (open) { setLabel(editingField?.label || ''); setFieldKey(editingField?.key || ''); setAgg('sum') }
  }, [open, editingField])

  function slugify(s: string): string { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') }

  async function handleSubmit() {
    setSaving(true)
    try {
      if (type === 'edit_label') {
        await kpiAPI.postDefinition({ action: 'update', id: editingField?.id, fieldLabel: label })
      } else if (type === 'add_kpi') {
        await kpiAPI.postDefinition({ action: 'create', groupName, fieldKey: fieldKey || slugify(label), fieldLabel: label, aggregation: agg, userId })
      } else if (type === 'add_group') {
        await kpiAPI.postDefinition({ action: 'create_group', groupName: label, userId })
      }
      toast.success('Saved')
      onDone()
    } catch { toast.error('Failed to save') }
    finally { setSaving(false) }
  }

  const title = type === 'edit_label' ? 'Edit KPI' : type === 'add_group' ? 'Add Group' : 'Add KPI'

  return (
    <Modal open={open} onClose={onClose} title={title} width="max-w-sm" footer={
      <>
        <button onClick={onClose} className="btn-secondary">Cancel</button>
        <button onClick={handleSubmit} disabled={saving || !label.trim()} className="btn-primary">{saving ? 'Saving...' : 'Save'}</button>
      </>
    }>
      <div className="space-y-3">
        <div>
          <label className="label-text">{type === 'add_group' ? 'Group Name' : 'Label'}</label>
          <input value={label} onChange={(e) => { setLabel(e.target.value); if (type === 'add_kpi' && !editingField) setFieldKey(slugify(e.target.value)) }} className="input-field" autoFocus />
        </div>
        {type === 'add_kpi' && (
          <>
            <div>
              <label className="label-text">Field Key</label>
              <input value={fieldKey} onChange={(e) => setFieldKey(e.target.value)} className="input-field" />
            </div>
            <div>
              <label className="label-text">Aggregation</label>
              <select value={agg} onChange={(e) => setAgg(e.target.value)} className="select-field">
                <option value="sum">Sum</option><option value="avg">Average</option><option value="latest">Latest</option>
              </select>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}
