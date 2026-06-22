import { useState, useEffect, useCallback, useMemo } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import {
  sprintsAPI, storiesAPI, bugsAPI, squadsAPI, epicsAPI,
  labelsAPI, agileDashboardAPI, agileAiAPI
} from '@/api/client'
import { Modal, Loader, Badge } from '@/components/ui'
import { classNames, formatTessaReply } from '@/lib/utils'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import toast from 'react-hot-toast'

// ── Types ──

interface Sprint { id: number; name: string; goal?: string; status: string; projectId?: number; projectName?: string; squadId?: number; squadName?: string; startDate: string; endDate: string; totalPoints?: number; completedPoints?: number; daysRemaining?: number; velocity?: number }
interface Story { id: number; title: string; description?: string; acceptanceCriteria?: string; status: string; priority: string; storyPoints?: number; sprintId?: number; sprintName?: string; epicId?: number; epicTitle?: string; assigneeId?: number; assigneeName?: string; reporterName?: string; labels?: Label[]; projectId?: number }
interface Bug { id: number; title: string; description?: string; stepsToReproduce?: string; status: string; priority: string; severity?: string; environment?: string; sprintId?: number; sprintName?: string; assigneeId?: number; assigneeName?: string; reporterName?: string; labels?: Label[]; projectId?: number }
interface Epic { id: number; title: string; description?: string; status: string; priority: string; progress: number; projectId?: number; projectName?: string; squadId?: number; squadName?: string; ownerId?: number; ownerName?: string; targetDate?: string; labels?: Label[] }
interface Squad { id: number; name: string; description?: string; leadName?: string; leadUserId?: number; members?: Array<{ id: number; name: string; roleInSquad: string }> }
interface Label { id: number; name: string; color?: string }
interface BoardData { [col: string]: { stories: any[]; bugs: any[] } }

const PRIORITY_COLORS: Record<string, string> = { critical: '#ef4444', high: '#f97316', medium: '#eab308', low: '#22c55e' }
const STATUS_LABELS: Record<string, string> = {
  backlog: 'Backlog', todo: 'To Do', in_progress: 'In Progress', code_review: 'Code Review', qa: 'QA', done: 'Done',
  open: 'Open', fixed: 'Fixed', verified: 'Verified', closed: 'Closed', wont_fix: "Won't Fix",
  planning: 'Planning', active: 'Active', review: 'Review', cancelled: 'Cancelled'
}
const STORY_STATUSES = ['todo', 'in_progress', 'code_review', 'qa', 'done']
const BUG_STATUSES = ['open', 'in_progress', 'fixed', 'verified', 'closed']
const BOARD_COLUMNS = ['todo', 'in_progress', 'code_review', 'qa', 'done']

const TESSA_ICON = <svg className="inline h-3.5 w-3.5 mr-1" viewBox="0 0 60 60" fill="none"><path d="M2 5 L58 5 L58 13 L40 13 L36 19 L36 55 L24 55 L24 19 L20 13 L2 13 Z" fill="currentColor"/></svg>

function statusBadgeColor(s: string): string {
  if (s === 'active') return 'bg-emerald-500'
  if (s === 'closed' || s === 'done') return 'bg-zinc-600'
  if (s === 'review') return 'bg-purple-500'
  return 'bg-blue-500'
}

// ── Main Component ──

export default function Agile(): JSX.Element {
  const { user, people } = useAuth()
  const portalConfig = (window as any).__PORTAL_CONFIG || {}
  const agileConfig = portalConfig.agile || {}
  const projects: Array<{ id: number; name: string }> = agileConfig.projects || []
  const canManageSprints = agileConfig.canManageSprints || false
  const canManageEpics = agileConfig.canManageEpics || false
  const canManageSquads = agileConfig.canManageSquads || false
  const canCrudStories = agileConfig.canCrudStories || false
  const canCrudBugs = agileConfig.canCrudBugs || false
  const canViewDashboard = agileConfig.canViewDashboard || false

  const [tab, setTab] = useState('board')
  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(null)
  const [sprints, setSprints] = useState<Sprint[]>([])
  const [selectedSprintId, setSelectedSprintId] = useState<number | null>(null)
  const [boardData, setBoardData] = useState<BoardData | null>(null)
  const [stories, setStories] = useState<Story[]>([])
  const [epics, setEpics] = useState<Epic[]>([])
  const [squads, setSquads] = useState<Squad[]>([])
  const [labels, setLabels] = useState<Label[]>([])
  const [velocityData, setVelocityData] = useState<any>(null)
  const [loading, setLoading] = useState(true)

  // Modals
  const [createModal, setCreateModal] = useState<{ type: string; sprintId?: number | null } | null>(null)
  const [detailItem, setDetailItem] = useState<{ item: any; isStory: boolean } | null>(null)
  const [aiModal, setAiModal] = useState<{ title: string; content: string } | null>(null)
  const [aiLoading, setAiLoading] = useState<string | null>(null)

  const projectParam = selectedProjectId ? { project_id: String(selectedProjectId) } : {}

  // ── Data loading ──

  // Get allowed project IDs for filtering (empty = all allowed)
  const allowedProjectIds = useMemo(() => {
    const ids = projects.map((p: any) => p.id)
    console.log('Agile: Portal projects:', projects, 'Allowed IDs:', ids)
    return ids
  }, [projects])

  const loadSprints = useCallback(async () => {
    try {
      const r = await sprintsAPI.list(projectParam)
      let sprintsList = r.data?.sprints || []
      console.log('Agile: Raw sprints:', sprintsList.map((s: Sprint) => ({ id: s.id, name: s.name, projectId: s.projectId })))
      // Filter by allowed projects if user has restrictions
      if (allowedProjectIds.length > 0) {
        sprintsList = sprintsList.filter((s: Sprint) => allowedProjectIds.includes(s.projectId))
        console.log('Agile: Filtered sprints:', sprintsList.map((s: Sprint) => ({ id: s.id, name: s.name, projectId: s.projectId })))
      }
      setSprints(sprintsList)
    } catch { /* ignore */ }
  }, [selectedProjectId, allowedProjectIds])

  const loadStories = useCallback(async () => {
    try {
      const r = await storiesAPI.list(projectParam)
      let storiesList = r.data?.stories || []
      // Filter by allowed projects if user has restrictions
      if (allowedProjectIds.length > 0 && !selectedProjectId) {
        storiesList = storiesList.filter((s: Story) => allowedProjectIds.includes(s.projectId))
      }
      setStories(storiesList)
    } catch { /* ignore */ }
  }, [selectedProjectId, allowedProjectIds])

  const loadEpics = useCallback(async () => {
    try {
      const r = await epicsAPI.list()
      let epicsList = r.data?.epics || []
      // Filter by selected project if specified
      if (selectedProjectId) {
        epicsList = epicsList.filter((e: Epic) => e.projectId === selectedProjectId)
      } else if (allowedProjectIds.length > 0) {
        // Filter by allowed projects if user has restrictions
        epicsList = epicsList.filter((e: Epic) => allowedProjectIds.includes(e.projectId))
      }
      setEpics(epicsList)
    } catch { /* ignore */ }
  }, [selectedProjectId, allowedProjectIds])

  const loadSquads = useCallback(async () => {
    try {
      const r = await squadsAPI.list()
      setSquads(r.data?.squads || [])
    } catch { /* ignore */ }
  }, [])

  const loadLabels = useCallback(async () => {
    try {
      const r = await labelsAPI.list()
      setLabels(r.data?.labels || [])
    } catch { /* ignore */ }
  }, [])

  const loadBoard = useCallback(async () => {
    if (!selectedSprintId) { setBoardData(null); return }
    try {
      const r = await sprintsAPI.board(selectedSprintId)
      setBoardData(r.data?.columns || null)
    } catch { setBoardData(null) }
  }, [selectedSprintId])

  const loadVelocity = useCallback(async () => {
    try {
      const r = await agileDashboardAPI.velocity()
      setVelocityData(r.data?.data || null)
    } catch { /* ignore */ }
  }, [])

  // Initial load
  useEffect(() => {
    ;(async () => {
      setLoading(true)
      await Promise.all([loadSprints(), loadStories(), loadEpics(), loadSquads(), loadLabels()])
      setLoading(false)
    })()
  }, [])

  // Auto-select single project if user only has one
  useEffect(() => {
    if (projects.length === 1 && !selectedProjectId) {
      setSelectedProjectId(projects[0].id)
    }
  }, [projects])

  // Auto-select sprint
  useEffect(() => {
    if (!selectedSprintId && sprints.length) {
      const active = sprints.find(s => s.status === 'active')
      setSelectedSprintId(active?.id || sprints[0]?.id || null)
    }
  }, [sprints])

  // Load board when sprint changes
  useEffect(() => { loadBoard() }, [selectedSprintId, loadBoard])

  // Reload when project changes
  useEffect(() => {
    ;(async () => {
      setSelectedSprintId(null)
      setBoardData(null)
      await Promise.all([loadSprints(), loadStories(), loadEpics()])
    })()
  }, [selectedProjectId])

  function changeProject(pid: number | null) {
    setSelectedProjectId(pid)
  }

  // ── Card movement ──

  async function moveItem(item: any, newStatus: string, isStory: boolean) {
    try {
      if (isStory) await storiesAPI.move(item.id, { status: newStatus })
      else await bugsAPI.move(item.id, { status: newStatus })
      await loadBoard()
    } catch (e: any) { toast.error(e?.message || 'Move failed') }
  }

  async function sprintAction(action: string) {
    if (!selectedSprintId || !confirm(`Are you sure you want to ${action} this sprint?`)) return
    try {
      if (action === 'activate') await sprintsAPI.activate(selectedSprintId)
      else if (action === 'review') await sprintsAPI.review(selectedSprintId)
      else if (action === 'close') await sprintsAPI.close(selectedSprintId)
      await loadSprints()
      await loadBoard()
    } catch (e: any) { toast.error(e?.message || 'Action failed') }
  }

  // ── AI features ──

  async function aiCall(label: string, endpoint: () => Promise<any>, resultKey: string, title: string) {
    setAiLoading(label)
    try {
      const r = await endpoint()
      setAiLoading(null)
      const data = r.data || {}
      const content = data[resultKey] || data.raw || data.message || data.result || ''
      setAiModal({ title, content: content ? String(content) : 'No data available for this analysis yet. Try again after there is more sprint activity.' })
    } catch (e: any) { setAiLoading(null); toast.error(e?.response?.data?.error || e?.message || 'AI service unavailable') }
  }

  const sprint = sprints.find(s => s.id === selectedSprintId)

  // ── Tab definitions ──

  const tabs = [
    { key: 'board', label: 'Sprint Board' },
    { key: 'backlog', label: 'Backlog' },
    { key: 'epics', label: 'Epics' },
    ...(canViewDashboard ? [{ key: 'velocity', label: 'Velocity' }] : []),
    ...(canManageSquads ? [{ key: 'squads', label: 'Squads' }] : []),
    { key: 'guide', label: 'Guide' }
  ]

  if (loading) return <Loader label="Loading agile board..." />

  return (
    <div className="space-y-4">
      {/* Project bar */}
      {projects.length > 0 && (
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-xs text-zinc-500 font-medium">Project:</span>
          {/* Only show All if user has access to multiple projects */}
          {projects.length !== 1 && (
            <button onClick={() => changeProject(null)} className={classNames('rounded-full px-3 py-1 text-xs font-medium transition-colors', !selectedProjectId ? 'bg-brand-600 text-white' : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700')}>All</button>
          )}
          {projects.map(p => (
            <button key={p.id} onClick={() => changeProject(p.id)} className={classNames('rounded-full px-3 py-1 text-xs font-medium transition-colors', (selectedProjectId === p.id || (projects.length === 1 && !selectedProjectId)) ? 'bg-brand-600 text-white' : 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700')}>{p.name}</button>
          ))}
          {/* DEBUG: show allowed project count */}
          <span className="text-xs text-zinc-600 ml-2">(allowed: {projects.length} projects)</span>
        </div>
      )}

      {/* Sub-nav */}
      <div className="flex gap-1 border-b border-zinc-800 pb-0">
        {tabs.map(t => (
          <button key={t.key} onClick={() => { setTab(t.key); if (t.key === 'velocity') loadVelocity() }}
            className={classNames('px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
              tab === t.key ? 'border-brand-500 text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-300')}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Content */}
      {tab === 'board' && <BoardTab sprint={sprint} sprints={sprints} boardData={boardData} canManageSprints={canManageSprints} canCrudStories={canCrudStories} canCrudBugs={canCrudBugs}
        onSprintChange={(id) => setSelectedSprintId(id)} onSprintAction={sprintAction} onMoveItem={moveItem}
        onCreateSprint={() => setCreateModal({ type: 'sprint' })} onCreateStory={() => setCreateModal({ type: 'story', sprintId: selectedSprintId })}
        onCreateBug={() => setCreateModal({ type: 'bug', sprintId: selectedSprintId })} onItemClick={(item, isStory) => setDetailItem({ item, isStory })}
        onAi={(label, fn) => aiCall(label, fn, label === 'plan-sprint' ? 'recommendation' : label === 'standup' ? 'summary' : label === 'retro' ? 'retro' : 'nudges', label)}
        squads={squads} selectedSprintId={selectedSprintId}
        aiPlanSprint={() => aiCall('Planning Sprint', () => agileAiAPI.planSprint({ squad_id: squads[0]?.id }), 'recommendation', 'Sprint Planning Recommendation')}
        aiStandup={() => aiCall('Generating Standup', () => agileAiAPI.standupSummary({ sprint_id: selectedSprintId }), 'summary', 'Daily Standup Summary')}
        aiReviewNudge={() => aiCall('Checking Items', () => agileAiAPI.reviewNudge({ sprint_id: selectedSprintId }), 'nudges', 'Review & QA Nudges')}
        aiRetro={(id: number) => aiCall('Generating Retro', () => agileAiAPI.sprintRetro({ sprint_id: id }), 'retro', 'Sprint Retrospective')}
        aiWriteStory={() => setCreateModal({ type: 'ai-story', sprintId: selectedSprintId })}
        aiWriteBug={() => setCreateModal({ type: 'ai-bug', sprintId: selectedSprintId })}
      />}
      {tab === 'backlog' && <BacklogTab stories={stories} sprints={sprints} canCrudStories={canCrudStories} canManageSprints={canManageSprints}
        onItemClick={(item) => setDetailItem({ item, isStory: true })}
        onCreateStory={() => setCreateModal({ type: 'story', sprintId: null })}
        onCreateBug={() => setCreateModal({ type: 'bug', sprintId: null })}
        onBulkMove={async (storyId, sprintId) => { await storiesAPI.bulkMove({ story_ids: [storyId], sprint_id: sprintId }); await loadStories() }}
        aiPrioritize={() => aiCall('Analyzing Backlog', () => agileAiAPI.prioritizeBacklog({}), 'prioritization', 'Backlog Prioritization')}
      />}
      {tab === 'epics' && <EpicsTab epics={epics} canManageEpics={canManageEpics} squads={squads}
        onCreateEpic={() => setCreateModal({ type: 'epic' })}
        aiInsights={(id) => aiCall('Analyzing Epic', () => agileAiAPI.epicInsights({ epic_id: id }), 'insights', 'Epic Health Report')}
        aiPredict={(id) => aiCall('Predicting', () => agileAiAPI.predictVelocity({ squad_id: squads[0]?.id, epic_id: id }), 'prediction', 'Velocity Prediction')}
      />}
      {tab === 'velocity' && <VelocityTab data={velocityData} />}
      {tab === 'squads' && <SquadsTab squads={squads} people={people || []} onReload={async () => { await loadSquads() }}
        onCreateSquad={() => setCreateModal({ type: 'squad' })} />}
      {tab === 'guide' && <GuideTab />}

      {/* Create Modals */}
      <CreateModal
        type={createModal?.type || null} sprintId={createModal?.sprintId}
        sprints={sprints} epics={epics} squads={squads} projects={projects} people={people || []}
        onClose={() => setCreateModal(null)}
        onCreated={async () => { setCreateModal(null); await Promise.all([loadSprints(), loadStories(), loadEpics(), loadSquads(), loadBoard()]) }}
      />

      {/* Item Detail Modal */}
      {detailItem && (
        <ItemDetailModal item={detailItem.item} isStory={detailItem.isStory} onClose={() => setDetailItem(null)}
          aiValidate={(id) => { setDetailItem(null); aiCall('Validating', () => agileAiAPI.validateAcceptance({ story_id: id }), 'checklist', 'Acceptance Criteria Check') }}
          aiSuggestAssignee={(title, desc) => { setDetailItem(null); aiCall('Finding Assignee', () => agileAiAPI.suggestAssignee({ bug_title: title, bug_description: desc, squad_id: null }), 'suggestion', 'Bug Assignment Suggestion') }}
        />
      )}

      {/* AI Result Modal */}
      {aiModal && (
        <Modal open={true} onClose={() => setAiModal(null)} title={aiModal.title} width="max-w-2xl">
          <div className="tessa-reply text-sm" dangerouslySetInnerHTML={{ __html: formatAiContent(aiModal.content) }} />
        </Modal>
      )}

      {/* AI Loading */}
      {aiLoading && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
          <div className="card flex items-center gap-3 px-6 py-4">
            <div className="h-8 w-8 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-sm animate-spin">T</div>
            <span className="text-sm text-zinc-300">Tessa is {aiLoading}...</span>
          </div>
        </div>
      )}
    </div>
  )
}

function formatAiContent(text: string): string {
  if (!text) return '<p class="text-zinc-500">No result returned.</p>'
  return formatTessaReply(text)
}

// ── Board Tab ──

function BoardTab({ sprint, sprints, boardData, canManageSprints, canCrudStories, canCrudBugs, onSprintChange, onSprintAction, onMoveItem, onCreateSprint, onCreateStory, onCreateBug, onItemClick, aiPlanSprint, aiStandup, aiReviewNudge, aiRetro, aiWriteStory, aiWriteBug, squads, selectedSprintId }: any) {
  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex items-center gap-3 flex-wrap">
        <label className="text-xs text-zinc-500">Sprint:</label>
        <select value={selectedSprintId || ''} onChange={e => onSprintChange(+e.target.value || null)} className="select-field text-xs w-auto max-w-[250px]">
          <option value="">-- Select Sprint --</option>
          {sprints.map((s: Sprint) => <option key={s.id} value={s.id}>{s.name} ({STATUS_LABELS[s.status] || s.status})</option>)}
        </select>
        {canManageSprints && <>
          <button onClick={onCreateSprint} className="btn-primary text-xs">+ New Sprint</button>
          <button onClick={aiPlanSprint} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Plan Sprint</button>
        </>}
      </div>

      {/* Sprint info */}
      {sprint && (
        <div className="card">
          <div className="flex items-center gap-3 flex-wrap">
            <strong className="text-sm text-zinc-200">{sprint.name}</strong>
            <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium text-white ${statusBadgeColor(sprint.status)}`}>{STATUS_LABELS[sprint.status] || sprint.status}</span>
            <span className="text-xs text-zinc-500">{sprint.completedPoints || 0}/{sprint.totalPoints || 0} pts</span>
            {sprint.daysRemaining != null && <span className="text-xs text-zinc-500">{sprint.daysRemaining} days left</span>}
          </div>
          {sprint.goal && <p className="text-xs text-zinc-400 mt-1">{sprint.goal}</p>}
          <div className="flex gap-2 mt-2 flex-wrap">
            {sprint.status === 'active' && <>
              <button onClick={aiStandup} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Standup Summary</button>
              <button onClick={aiReviewNudge} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Review Nudge</button>
            </>}
            {(sprint.status === 'closed' || sprint.status === 'review') && <button onClick={() => aiRetro(sprint.id)} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Generate Retro</button>}
            {canManageSprints && <>
              {sprint.status === 'planning' && <button onClick={() => onSprintAction('activate')} className="text-xs bg-emerald-600 text-white rounded px-2.5 py-1">Start Sprint</button>}
              {sprint.status === 'active' && <button onClick={() => onSprintAction('review')} className="text-xs bg-amber-600 text-white rounded px-2.5 py-1">Move to Review</button>}
              {(sprint.status === 'active' || sprint.status === 'review') && <button onClick={() => onSprintAction('close')} className="text-xs bg-red-600 text-white rounded px-2.5 py-1">Close Sprint</button>}
            </>}
          </div>
        </div>
      )}

      {/* Board columns */}
      {!boardData ? (
        <div className="text-sm text-zinc-500 text-center py-8">{sprint ? 'Loading board...' : 'Select a sprint to view the board.'}</div>
      ) : (
        <div className="flex gap-3 overflow-x-auto pb-2">
          {BOARD_COLUMNS.map(col => {
            const colData = boardData[col] || { stories: [], bugs: [] }
            const items = [...(colData.stories || []).map((s: any) => ({ ...s, _isStory: true })), ...(colData.bugs || []).map((b: any) => ({ ...b, _isStory: false }))]
            return (
              <div key={col} className="w-56 shrink-0 rounded-lg border border-zinc-800 bg-surface-1">
                <div className="flex items-center justify-between px-3 py-2 border-b border-zinc-800">
                  <span className="text-xs font-semibold text-zinc-300">{STATUS_LABELS[col]}</span>
                  <span className="text-[10px] bg-zinc-800 text-zinc-500 rounded-full px-1.5">{items.length}</span>
                </div>
                <div className="p-2 space-y-2 min-h-[120px] max-h-[500px] overflow-y-auto">
                  {items.map((item: any) => (
                    <AgileCard key={`${item._isStory ? 's' : 'b'}-${item.id}`} item={item} isStory={item._isStory}
                      canMove={canCrudStories || canCrudBugs}
                      onClick={() => onItemClick(item, item._isStory)}
                      onMove={(newStatus: string) => onMoveItem(item, newStatus, item._isStory)} />
                  ))}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Add bar */}
      {(canCrudStories || canCrudBugs) && sprint && (
        <div className="flex gap-2 flex-wrap">
          <button onClick={onCreateStory} className="btn-primary text-xs">+ Add Story</button>
          <button onClick={aiWriteStory} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Write Story</button>
          <button onClick={onCreateBug} className="btn-secondary text-xs">+ Report Bug</button>
          <button onClick={aiWriteBug} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Report Bug</button>
        </div>
      )}
    </div>
  )
}

function AgileCard({ item, isStory, canMove, onClick, onMove }: { item: any; isStory: boolean; canMove: boolean; onClick: () => void; onMove: (s: string) => void }) {
  const statuses = isStory ? STORY_STATUSES : BUG_STATUSES
  const idx = statuses.indexOf(item.status)
  const assigneeName = item.assignee?.name || item.assigneeName || ''

  return (
    <div onClick={onClick} className={classNames('rounded-md border p-2 cursor-pointer hover:border-zinc-600 transition-colors', isStory ? 'border-zinc-800 bg-surface-3' : 'border-red-900/30 bg-red-500/5')}>
      <div className="flex items-center gap-1.5 mb-1">
        <span className="h-2 w-2 rounded-full shrink-0" style={{ background: PRIORITY_COLORS[item.priority] || '#6b7280' }} />
        <span className="text-[10px] font-medium text-zinc-500">{isStory ? 'Story' : 'Bug'}</span>
        {isStory && item.storyPoints && <span className="text-[10px] bg-brand-500/10 text-brand-400 rounded px-1">{item.storyPoints}pt</span>}
        {!isStory && item.severity && <span className="text-[10px] rounded px-1 text-white" style={{ background: PRIORITY_COLORS[item.severity] || '#6b7280' }}>{item.severity}</span>}
      </div>
      <div className="text-xs text-zinc-200 mb-1 line-clamp-2">{item.title}</div>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-1">
          {assigneeName && <span className="text-[10px] text-zinc-500">{assigneeName}</span>}
        </div>
        {item.labels?.length > 0 && (
          <div className="flex gap-0.5">{item.labels.slice(0, 2).map((l: Label) => (
            <span key={l.id} className="text-[8px] rounded px-1 py-0.5" style={{ background: `${l.color}22`, color: l.color, border: `1px solid ${l.color}44` }}>{l.name}</span>
          ))}</div>
        )}
      </div>
      {canMove && (
        <div className="flex gap-1 mt-1.5">
          {idx > 0 && <button onClick={e => { e.stopPropagation(); onMove(statuses[idx - 1]) }} className="text-[10px] bg-zinc-800 hover:bg-zinc-700 rounded px-1.5 py-0.5 text-zinc-400">←</button>}
          {idx < statuses.length - 1 && <button onClick={e => { e.stopPropagation(); onMove(statuses[idx + 1]) }} className="text-[10px] bg-zinc-800 hover:bg-zinc-700 rounded px-1.5 py-0.5 text-zinc-400">→</button>}
        </div>
      )}
    </div>
  )
}

// ── Backlog Tab ──

function BacklogTab({ stories, sprints, canCrudStories, canManageSprints, onItemClick, onCreateStory, onCreateBug, onBulkMove, aiPrioritize }: any) {
  const backlog = stories.filter((s: Story) => !s.sprintId)
  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold text-zinc-100">Backlog</h2>
      {canCrudStories && (
        <div className="flex gap-2 flex-wrap">
          <button onClick={onCreateStory} className="btn-primary text-xs">+ Add Story</button>
          <button onClick={onCreateBug} className="btn-secondary text-xs">+ Report Bug</button>
          <button onClick={aiPrioritize} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Prioritize Backlog</button>
        </div>
      )}
      {!backlog.length ? <div className="text-sm text-zinc-500 text-center py-8">No items in backlog.</div> : (
        <div className="space-y-1">
          {backlog.map((s: Story) => (
            <div key={s.id} onClick={() => onItemClick(s)} className="flex items-center gap-3 rounded-md border border-zinc-800 bg-surface-3 px-3 py-2 cursor-pointer hover:border-zinc-700">
              <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ background: PRIORITY_COLORS[s.priority] || '#6b7280' }} />
              <span className="text-xs text-zinc-200 flex-1 truncate">{s.title}</span>
              {s.storyPoints && <span className="text-[10px] bg-brand-500/10 text-brand-400 rounded px-1.5">{s.storyPoints}pt</span>}
              {s.assigneeName && <span className="text-[10px] text-zinc-500">{s.assigneeName}</span>}
              {s.epicTitle && <span className="text-[10px] text-zinc-600">{s.epicTitle}</span>}
              {canManageSprints && sprints.length > 0 && (
                <select className="select-field text-[10px] w-auto max-w-[130px] py-0.5" onClick={e => e.stopPropagation()}
                  onChange={async e => { if (e.target.value) { await onBulkMove(s.id, +e.target.value); e.target.value = '' } }}>
                  <option value="">Move to sprint...</option>
                  {sprints.filter((sp: Sprint) => sp.status !== 'closed').map((sp: Sprint) => <option key={sp.id} value={sp.id}>{sp.name}</option>)}
                </select>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Epics Tab ──

function EpicsTab({ epics, canManageEpics, onCreateEpic, aiInsights, aiPredict }: any) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-zinc-100">Epics</h2>
        {canManageEpics && <button onClick={onCreateEpic} className="btn-primary text-xs">+ New Epic</button>}
      </div>
      {!epics.length ? <div className="text-sm text-zinc-500 text-center py-8">No epics yet.</div> : (
        <div className="space-y-3">
          {epics.map((e: Epic) => (
            <div key={e.id} className="card">
              <div className="flex items-center gap-2 mb-2">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: PRIORITY_COLORS[e.priority] || '#6b7280' }} />
                <strong className="text-sm text-zinc-200">{e.title}</strong>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium text-white ${statusBadgeColor(e.status)}`}>{STATUS_LABELS[e.status] || e.status}</span>
                {e.squadName && <span className="text-[10px] text-zinc-500">{e.squadName}</span>}
              </div>
              <div className="flex items-center gap-2 mb-2">
                <div className="flex-1 h-1.5 rounded-full bg-zinc-800 overflow-hidden"><div className="h-full rounded-full bg-brand-500" style={{ width: `${e.progress}%` }} /></div>
                <span className="text-xs text-zinc-400">{e.progress}%</span>
              </div>
              {e.description && <p className="text-xs text-zinc-500 mb-1">{e.description}</p>}
              {e.targetDate && <p className="text-[10px] text-zinc-600">Target: {e.targetDate}</p>}
              {e.ownerName && <p className="text-[10px] text-zinc-600">Owner: {e.ownerName}</p>}
              <div className="flex gap-2 mt-2">
                <button onClick={() => aiInsights(e.id)} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Epic Insights</button>
                <button onClick={() => aiPredict(e.id)} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Predict Completion</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Velocity Tab ──

function VelocityTab({ data }: { data: any }) {
  if (!data) return <div className="text-sm text-zinc-500 text-center py-8">No velocity data yet. Complete a sprint to see velocity.</div>
  const datasets = Array.isArray(data) ? data : [data]
  return (
    <div className="space-y-6 max-w-[900px]">
      <h2 className="text-lg font-bold text-zinc-100">Velocity Dashboard</h2>
      {datasets.map((ds: any, i: number) => {
        const vel = ds.velocity || ds
        const sprintsList = vel.sprints || []
        const maxV = Math.max(...sprintsList.map((s: any) => s.velocity || 0), 1)
        return (
          <div key={i} className="rounded-xl border border-zinc-800 bg-[#151515] p-5">
            {ds.squadName && <h3 className="text-sm font-semibold text-zinc-200 mb-2">{ds.squadName}</h3>}
            {vel.averageVelocity != null && (
              <div className="text-[15px] font-semibold text-zinc-100 mb-4">Average Velocity: {vel.averageVelocity} pts/sprint</div>
            )}
            {sprintsList.length ? (
              <div className="flex items-end gap-3" style={{ height: '200px', paddingTop: '10px' }}>
                {sprintsList.map((s: any) => {
                  const pct = ((s.velocity || 0) / maxV) * 100
                  return (
                    <div key={s.id} className="flex-1 flex flex-col items-center justify-end h-full">
                      <div
                        className="w-full max-w-[48px] rounded-t-md transition-all duration-300"
                        style={{ height: `${pct}%`, background: '#3b82f6', minHeight: '4px' }}
                      />
                      <div className="text-xs font-semibold text-zinc-100 mt-1">{s.velocity || 0}</div>
                      <div className="text-[11px] text-zinc-500 mt-0.5 text-center truncate max-w-[60px]">{s.name}</div>
                    </div>
                  )
                })}
              </div>
            ) : <div className="text-sm text-zinc-500 text-center py-6">No completed sprints yet.</div>}
          </div>
        )
      })}
    </div>
  )
}

// ── Squads Tab ──

function SquadsTab({ squads, people, onReload, onCreateSquad }: any) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-zinc-100">Squads</h2>
        <button onClick={onCreateSquad} className="btn-primary text-xs">+ New Squad</button>
      </div>
      {!squads.length ? <div className="text-sm text-zinc-500 text-center py-8">No squads yet. Create one to get started.</div> : (
        <div className="space-y-3">
          {squads.map((sq: Squad) => (
            <div key={sq.id} className="card">
              <div className="flex items-center gap-2 mb-2">
                <strong className="text-sm text-zinc-200">{sq.name}</strong>
                {sq.leadName && <span className="text-[10px] text-zinc-500">Lead: {sq.leadName}</span>}
              </div>
              {sq.description && <p className="text-xs text-zinc-500 mb-2">{sq.description}</p>}
              <div className="space-y-1 mb-2">
                {(sq.members || []).map((m: any) => (
                  <div key={m.id} className="flex items-center gap-2 text-xs">
                    <span className="text-zinc-300">{m.name}</span>
                    <span className="text-[10px] text-zinc-600">{m.roleInSquad}</span>
                    <button onClick={async () => { if (!confirm(`Remove ${m.name}?`)) return; await squadsAPI.removeMember(sq.id, m.id); onReload() }}
                      className="text-[10px] text-zinc-600 hover:text-red-400">×</button>
                  </div>
                ))}
              </div>
              <div className="flex gap-2">
                <select className="select-field text-xs w-auto max-w-[180px]" id={`add-member-${sq.id}`}>
                  <option value="">Add member...</option>
                  {people.filter((p: any) => !(sq.members || []).find((m: any) => m.id === p.id)).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
                <button onClick={async () => {
                  const sel = document.getElementById(`add-member-${sq.id}`) as HTMLSelectElement
                  if (!sel?.value) return
                  await squadsAPI.addMember(sq.id, { user_id: +sel.value }); onReload()
                }} className="btn-secondary text-xs">Add</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Guide Tab ──

function GuideTab() {
  return (
    <div className="tessa-reply max-w-3xl" dangerouslySetInnerHTML={{ __html: GUIDE_HTML }} />
  )
}

const GUIDE_HTML = `
<h2>Agile Guide for the Team</h2>
<p>New to Agile? This guide explains everything in simple terms. Read it once — it'll make the board, backlog, and sprints make sense.</p>

<h3>What is Agile & Why Are We Doing This?</h3>
<p><strong>The Problem:</strong> We have multiple tech members all working on different things. Without a system — nobody knows what others are working on, big features get stuck with no visibility, bugs get lost, and leadership can't see progress without asking everyone.</p>
<p><strong>The Solution — Agile (Scrum):</strong></p>
<ul><li>Break work into small pieces (2 weeks at a time)</li><li>Everyone can see what everyone else is doing (transparency)</li><li>You review and adjust every 2 weeks (no waiting months to find problems)</li></ul>
<p>Think of it like cooking — instead of preparing a 10-course meal all at once, you cook one dish at a time, taste it, adjust seasoning, then move to the next.</p>

<h3>The Work Hierarchy: Epic > Story > Task > Bug</h3>
<h4>Epic</h4>
<p>A BIG feature or project that takes weeks or months. Too large for one sprint.</p>
<p><em>Examples: "Build Invoice Module", "Revamp Meeting System", "Add AI Chat"</em></p>
<p>Without Epics, big work items sit as one giant task with no way to track progress. With Epics, you can see "Invoice Module is 60% done — 6 of 10 stories completed."</p>
<h4>Story</h4>
<p>One specific thing a user can do after you build it. Written as: <strong>"As a [role], I can [do something]"</strong></p>
<p><em>Examples: "As a CFO, I can approve invoices", "As an Accountant, I can upload an invoice"</em></p>
<p>Each Story should be completable within one sprint (2 weeks). If it can't, break it smaller.</p>
<p>A Story has: title, description, <strong>acceptance criteria</strong> (how do we know it's DONE?), story points, priority, and an assignee.</p>
<h4>Task</h4>
<p>The actual technical work needed to complete a Story. One Story usually has multiple Tasks.</p>
<p><em>Example for "CFO can approve invoices": 1) Create DB table, 2) Build API endpoint, 3) Add UI buttons, 4) Send Slack notification</em></p>
<h4>Bug</h4>
<p>Something that's broken in existing functionality. Bugs have extra fields: <strong>severity</strong> (how bad), <strong>steps to reproduce</strong>, and <strong>environment</strong> (dev/staging/production).</p>
<p><strong>Severity vs Priority:</strong> Severity = how broken (critical = app crashes). Priority = how soon to fix (a low-severity bug on login page may be high priority because everyone sees it).</p>

<h3>Story Points</h3>
<p>Story Points are NOT hours. They are a rough estimate of complexity relative to other work.</p>
<table><tr><td><strong>1 pt</strong></td><td>Trivial — fix a typo, change a color</td></tr><tr><td><strong>2 pts</strong></td><td>Small — add a column, simple change</td></tr><tr><td><strong>3 pts</strong></td><td>Medium — build a simple API endpoint</td></tr><tr><td><strong>5 pts</strong></td><td>Large — form + API + DB changes</td></tr><tr><td><strong>8 pts</strong></td><td>Very large — needs research, many parts</td></tr><tr><td><strong>13 pts</strong></td><td>Too big — break it into smaller stories</td></tr></table>
<p>After a few sprints, we'll know our <strong>velocity</strong> — how many points we complete per sprint. This helps us plan realistically.</p>

<h3>Sprints: The Heartbeat</h3>
<p>A Sprint is a fixed 2-week period where the team commits to completing a set of Stories/Bugs.</p>
<p><strong>Planning → Active → Review → Closed</strong></p>
<p><strong>Planning (Day 1):</strong> Team picks stories from the backlog they can realistically finish. Sprint gets a one-sentence Goal.</p>
<p><strong>Active (Day 1-14):</strong> Everyone works. Daily standups (15 min). Stories move across the board: Todo → In Progress → Code Review → QA → Done.</p>
<p><strong>Review (Day 14):</strong> Team demos what they built. Incomplete work goes back to backlog.</p>
<p><strong>Closed:</strong> Velocity calculated. Retro: What went well? What didn't? What to improve?</p>

<h3>The Sprint Board</h3>
<p>A visual board with columns. Each Story/Bug is a card that moves left to right.</p>
<table><tr><td><strong>Todo</strong></td><td>Planned for this sprint, not started yet</td><td>Sprint Planning</td></tr><tr><td><strong>In Progress</strong></td><td>Someone is actively coding</td><td>Developer moves own card</td></tr><tr><td><strong>Code Review</strong></td><td>Code written, waiting for review</td><td>Developer after pushing code</td></tr><tr><td><strong>QA</strong></td><td>Reviewed, waiting for testing</td><td>After review approved</td></tr><tr><td><strong>Done</strong></td><td>Tested, approved, merged</td><td>QA after testing passes</td></tr></table>
<p><strong>Rules:</strong> Only move YOUR cards. If stuck, raise it in standup. A card shouldn't stay in one column for more than 2-3 days.</p>

<h3>Squads</h3>
<p>A Squad is a small team (3-6 people) within the larger tech team. Each Squad owns a specific area.</p>
<p><strong>Why?</strong> Too many people in one team = standups take forever, work gets confusing. Squads have their own sprints, their own board, their own velocity.</p>

<h3>Velocity & Burndown</h3>
<p><strong>Velocity</strong> = how many Story Points completed per sprint. It tells you how much to plan next time. If velocity is 26, don't plan 40 points.</p>
<p><strong>Important:</strong> Velocity is NOT a performance metric. Don't use it to judge people. It's a planning tool.</p>
<p><strong>Burndown Chart</strong> = shows remaining work day by day. Line should go down steadily. Flat line = team is stuck. Line going up = work was added mid-sprint (bad).</p>

<h3>Daily Standup</h3>
<p>Each person answers 3 questions:</p>
<ul><li>What did I do yesterday?</li><li>What will I do today?</li><li>Am I blocked by anything?</li></ul>
<p><strong>15 minutes MAX.</strong> Not a discussion meeting. Take discussions offline.</p>

<h3>Labels</h3>
<p>Color-coded tags on Stories/Bugs: <em>frontend, backend, database, ai, urgent, tech-debt</em>. Helps filter the board — "show me only backend work" or "all urgent items".</p>

<h3>Tessa AI Features</h3>
<p>Tessa AI is integrated throughout the Agile board to help you:</p>
<table><tr><td><strong>Write Story</strong></td><td>Describe your idea roughly, Tessa formats it as a proper user story with acceptance criteria</td></tr><tr><td><strong>Report Bug</strong></td><td>Describe the bug casually, Tessa formats title, steps to reproduce, severity</td></tr><tr><td><strong>Plan Sprint</strong></td><td>Tessa analyzes backlog + velocity and recommends what to pick for next sprint</td></tr><tr><td><strong>Standup Summary</strong></td><td>Auto-generates standup summary from board activity</td></tr><tr><td><strong>Generate Retro</strong></td><td>Analyzes completed sprint and generates retro talking points</td></tr><tr><td><strong>Epic Insights</strong></td><td>AI health check on an epic — progress, risks, estimated completion</td></tr><tr><td><strong>Suggest Assignee</strong></td><td>Recommends who to assign a bug to based on expertise and workload</td></tr><tr><td><strong>Prioritize Backlog</strong></td><td>AI recommends priority ordering for backlog items</td></tr><tr><td><strong>Predict Completion</strong></td><td>Predicts when an epic will be done based on velocity</td></tr><tr><td><strong>Review Nudge</strong></td><td>Finds items stuck in Code Review or QA and generates friendly nudges</td></tr><tr><td><strong>Validate Acceptance</strong></td><td>Checks if a story's acceptance criteria have been met before marking Done</td></tr></table>

<h3>Who Can Do What</h3>
<table><tr><td><strong>Action</strong></td><td><strong>Tech Lead</strong></td><td><strong>Developers</strong></td><td><strong>QA</strong></td><td><strong>CEO/COO</strong></td></tr><tr><td>Create/manage Sprints</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr><tr><td>Create/manage Epics</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr><tr><td>Create Stories & Bugs</td><td>Yes</td><td>Yes</td><td>Yes</td><td>No</td></tr><tr><td>Move own items on board</td><td>Yes</td><td>Yes</td><td>Yes</td><td>No</td></tr><tr><td>Assign items to people</td><td>Yes</td><td>No</td><td>Yes</td><td>No</td></tr><tr><td>View dashboard & velocity</td><td>Yes</td><td>No</td><td>No</td><td>Yes</td></tr></table>
`

// ── Item Detail Modal ──

function ItemDetailModal({ item, isStory, onClose, aiValidate, aiSuggestAssignee }: any) {
  return (
    <Modal open={true} onClose={onClose} title={item.title} width="max-w-lg">
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <span className="text-[10px] font-medium text-zinc-500">{isStory ? 'Story' : 'Bug'}</span>
          <span className="h-2.5 w-2.5 rounded-full" style={{ background: PRIORITY_COLORS[item.priority] || '#6b7280' }} />
          <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium text-white ${statusBadgeColor(item.status)}`}>{STATUS_LABELS[item.status] || item.status}</span>
        </div>
        <div className="grid grid-cols-2 gap-2 text-xs">
          {item.assigneeName && <div><span className="text-zinc-500">Assignee:</span> <strong className="text-zinc-200">{item.assigneeName}</strong></div>}
          {item.reporterName && <div><span className="text-zinc-500">Reporter:</span> <strong className="text-zinc-200">{item.reporterName}</strong></div>}
          {isStory && item.storyPoints && <div><span className="text-zinc-500">Points:</span> <strong className="text-zinc-200">{item.storyPoints}</strong></div>}
          {item.epicTitle && <div><span className="text-zinc-500">Epic:</span> <strong className="text-zinc-200">{item.epicTitle}</strong></div>}
          {item.sprintName && <div><span className="text-zinc-500">Sprint:</span> <strong className="text-zinc-200">{item.sprintName}</strong></div>}
          {!isStory && item.severity && <div><span className="text-zinc-500">Severity:</span> <strong className="text-zinc-200">{item.severity}</strong></div>}
          {!isStory && item.environment && <div><span className="text-zinc-500">Environment:</span> <strong className="text-zinc-200">{item.environment}</strong></div>}
        </div>
        {item.description && <div><strong className="text-xs text-zinc-400">Description</strong><p className="text-xs text-zinc-300 mt-1">{item.description}</p></div>}
        {isStory && item.acceptanceCriteria && <div><strong className="text-xs text-zinc-400">Acceptance Criteria</strong><p className="text-xs text-zinc-300 mt-1">{item.acceptanceCriteria}</p></div>}
        {!isStory && item.stepsToReproduce && <div><strong className="text-xs text-zinc-400">Steps to Reproduce</strong><p className="text-xs text-zinc-300 mt-1">{item.stepsToReproduce}</p></div>}
        <div className="flex gap-2 pt-2">
          {isStory && item.acceptanceCriteria && <button onClick={() => aiValidate(item.id)} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Validate Acceptance</button>}
          {!isStory && <button onClick={() => aiSuggestAssignee(item.title, item.description)} className="btn-ghost text-xs text-brand-400">{TESSA_ICON} Suggest Assignee</button>}
        </div>
      </div>
    </Modal>
  )
}

// ── Create Modal ──

function CreateModal({ type, sprintId, sprints, epics, squads, projects, people, onClose, onCreated }: any) {
  const [form, setForm] = useState<Record<string, any>>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => { setForm({}) }, [type])

  if (!type) return null

  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  async function handleSubmit() {
    setSaving(true)
    try {
      if (type === 'sprint') {
        await sprintsAPI.create(form)
      } else if (type === 'story') {
        const data = { ...form }; if (sprintId) data.sprint_id = sprintId
        await storiesAPI.create(data)
      } else if (type === 'bug') {
        const data = { ...form }; if (sprintId) data.sprint_id = sprintId
        await bugsAPI.create(data)
      } else if (type === 'epic') {
        await epicsAPI.create(form)
      } else if (type === 'squad') {
        await squadsAPI.create(form)
      }
      toast.success('Created')
      onCreated()
    } catch (e: any) { toast.error(e?.message || 'Failed') }
    finally { setSaving(false) }
  }

  const priorityOpts = [{ v: 'low', l: 'Low' }, { v: 'medium', l: 'Medium' }, { v: 'high', l: 'High' }, { v: 'critical', l: 'Critical' }]
  const title = type === 'sprint' ? 'Create Sprint' : type === 'story' ? 'Create Story' : type === 'bug' ? 'Report Bug' : type === 'epic' ? 'Create Epic' : type === 'squad' ? 'Create Squad' : ''

  // ── AI Write Story modal — sourced from agile.js aiWriteStory() lines 910-970 ──
  if (type === 'ai-story') return (
    <AiWriteStoryModal sprintId={sprintId} epics={epics} onClose={onClose} onCreated={onCreated} />
  )

  // ── AI Report Bug modal — sourced from agile.js aiWriteBug() lines 973-1010 ──
  if (type === 'ai-bug') return (
    <AiWriteBugModal sprintId={sprintId} onClose={onClose} onCreated={onCreated} />
  )

  return (
    <Modal open={true} onClose={onClose} title={title} width="max-w-lg" footer={
      <><button onClick={onClose} className="btn-secondary">Cancel</button><button onClick={handleSubmit} disabled={saving} className="btn-primary">{saving ? 'Creating...' : 'Create'}</button></>
    }>
      <div className="space-y-3">
        {type === 'sprint' && <>
          <div><label className="label-text">Sprint Name</label><input className="input-field" value={form.name || ''} onChange={e => set('name', e.target.value)} placeholder="e.g. Sprint 1" /></div>
          <div><label className="label-text">Sprint Goal</label><textarea className="textarea-field" rows={2} value={form.goal || ''} onChange={e => set('goal', e.target.value)} placeholder="What do you want to achieve?" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label-text">Project</label><select className="select-field" value={form.project_id || ''} onChange={e => set('project_id', +e.target.value || null)}><option value="">-- Select --</option>{projects.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Squad</label><select className="select-field" value={form.squad_id || ''} onChange={e => set('squad_id', +e.target.value || null)}><option value="">-- Select --</option>{squads.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
            <div><label className="label-text">Start Date</label><input type="date" className="input-field" value={form.start_date || ''} onChange={e => set('start_date', e.target.value)} /></div>
            <div><label className="label-text">End Date</label><input type="date" className="input-field" value={form.end_date || ''} onChange={e => set('end_date', e.target.value)} /></div>
          </div>
        </>}
        {type === 'story' && <>
          <div><label className="label-text">Title</label><input className="input-field" value={form.title || ''} onChange={e => set('title', e.target.value)} placeholder="As a [role], I can [do something]" /></div>
          <div><label className="label-text">Description</label><textarea className="textarea-field" rows={3} value={form.description || ''} onChange={e => set('description', e.target.value)} /></div>
          <div><label className="label-text">Acceptance Criteria</label><textarea className="textarea-field" rows={2} value={form.acceptance_criteria || ''} onChange={e => set('acceptance_criteria', e.target.value)} placeholder="How do we know this is done?" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label-text">Project</label><select className="select-field" value={form.project_id || ''} onChange={e => set('project_id', +e.target.value || null)}><option value="">None</option>{projects.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Epic</label><select className="select-field" value={form.epic_id || ''} onChange={e => set('epic_id', +e.target.value || null)}><option value="">None</option>{epics.map((e: any) => <option key={e.id} value={e.id}>{e.title}</option>)}</select></div>
            <div><label className="label-text">Assignee</label><select className="select-field" value={form.assignee_id || ''} onChange={e => set('assignee_id', +e.target.value || null)}><option value="">Unassigned</option>{people.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Priority</label><select className="select-field" value={form.priority || 'medium'} onChange={e => set('priority', e.target.value)}>{priorityOpts.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}</select></div>
            <div><label className="label-text">Story Points</label><input type="number" className="input-field" value={form.story_points || ''} onChange={e => set('story_points', +e.target.value || null)} placeholder="1, 2, 3, 5, 8, 13" /></div>
          </div>
        </>}
        {type === 'bug' && <>
          <div><label className="label-text">Title</label><input className="input-field" value={form.title || ''} onChange={e => set('title', e.target.value)} /></div>
          <div><label className="label-text">Description</label><textarea className="textarea-field" rows={3} value={form.description || ''} onChange={e => set('description', e.target.value)} /></div>
          <div><label className="label-text">Steps to Reproduce</label><textarea className="textarea-field" rows={2} value={form.steps_to_reproduce || ''} onChange={e => set('steps_to_reproduce', e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label-text">Assignee</label><select className="select-field" value={form.assignee_id || ''} onChange={e => set('assignee_id', +e.target.value || null)}><option value="">Unassigned</option>{people.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Severity</label><select className="select-field" value={form.severity || 'medium'} onChange={e => set('severity', e.target.value)}>{priorityOpts.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}</select></div>
            <div><label className="label-text">Priority</label><select className="select-field" value={form.priority || 'medium'} onChange={e => set('priority', e.target.value)}>{priorityOpts.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}</select></div>
            <div><label className="label-text">Environment</label><select className="select-field" value={form.environment || ''} onChange={e => set('environment', e.target.value)}><option value="">N/A</option><option value="dev">Dev</option><option value="staging">Staging</option><option value="production">Production</option></select></div>
          </div>
        </>}
        {type === 'epic' && <>
          <div><label className="label-text">Title</label><input className="input-field" value={form.title || ''} onChange={e => set('title', e.target.value)} /></div>
          <div><label className="label-text">Description</label><textarea className="textarea-field" rows={3} value={form.description || ''} onChange={e => set('description', e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label-text">Project</label><select className="select-field" value={form.project_id || ''} onChange={e => set('project_id', +e.target.value || null)}><option value="">None</option>{projects.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Squad</label><select className="select-field" value={form.squad_id || ''} onChange={e => set('squad_id', +e.target.value || null)}><option value="">None</option>{squads.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
            <div><label className="label-text">Owner</label><select className="select-field" value={form.owner_id || ''} onChange={e => set('owner_id', +e.target.value || null)}><option value="">None</option>{people.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label-text">Priority</label><select className="select-field" value={form.priority || 'medium'} onChange={e => set('priority', e.target.value)}>{priorityOpts.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}</select></div>
            <div><label className="label-text">Target Date</label><input type="date" className="input-field" value={form.target_date || ''} onChange={e => set('target_date', e.target.value)} /></div>
          </div>
        </>}
        {type === 'squad' && <>
          <div><label className="label-text">Squad Name</label><input className="input-field" value={form.name || ''} onChange={e => set('name', e.target.value)} /></div>
          <div><label className="label-text">Description</label><textarea className="textarea-field" rows={2} value={form.description || ''} onChange={e => set('description', e.target.value)} /></div>
          <div><label className="label-text">Squad Lead</label><select className="select-field" value={form.lead_user_id || ''} onChange={e => set('lead_user_id', +e.target.value || null)}><option value="">None</option>{people.map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
        </>}
      </div>
    </Modal>
  )
}

// ── AI Write Story Modal — sourced from agile.js lines 910-970 ──

function AiWriteStoryModal({ sprintId, epics, onClose, onCreated }: { sprintId?: number | null; epics: Epic[]; onClose: () => void; onCreated: () => void }) {
  const [idea, setIdea] = useState('')
  const [epicTitle, setEpicTitle] = useState('')
  const [generating, setGenerating] = useState(false)
  const [suggestion, setSuggestion] = useState<any>(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  async function handleGenerate() {
    if (!idea.trim()) return
    setGenerating(true)
    setError('')
    setSuggestion(null)
    try {
      const r = await agileAiAPI.writeStory({ idea: idea.trim(), epic_title: epicTitle || null })
      const data = r.data || {}
      if (data.suggestion) {
        setSuggestion(data.suggestion)
      } else {
        setError(data.raw || 'Could not generate story.')
      }
    } catch (e: any) {
      setError(e?.response?.data?.error || e?.message || 'AI service unavailable')
    } finally {
      setGenerating(false)
    }
  }

  async function handleUse() {
    if (!suggestion) return
    setSaving(true)
    try {
      await storiesAPI.create({
        title: suggestion.title,
        description: suggestion.description,
        acceptance_criteria: suggestion.acceptance_criteria,
        priority: suggestion.priority || 'medium',
        story_points: suggestion.suggested_points || null,
        sprint_id: sprintId || null,
        epic_id: epics.find(e => e.title === epicTitle)?.id || null
      })
      toast.success('Story created from Tessa suggestion')
      onCreated()
    } catch (e: any) {
      toast.error(e?.message || 'Failed to create story')
      setSaving(false)
    }
  }

  return (
    <Modal open={true} onClose={onClose} title="Tessa — Write Story" width="max-w-lg" footer={
      <><button onClick={onClose} className="btn-secondary">Cancel</button>
        <button onClick={handleGenerate} disabled={generating || !idea.trim()} className="btn-primary">
          {generating ? 'Tessa is thinking...' : 'Generate'}
        </button></>
    }>
      <div className="space-y-3">
        <div>
          <label className="label-text">Your rough idea:</label>
          <textarea className="textarea-field" rows={3} value={idea} onChange={e => setIdea(e.target.value)}
            placeholder='Describe your idea roughly... e.g. "users should be able to export invoices to PDF"' />
        </div>
        <div>
          <label className="label-text">Epic (optional):</label>
          <select className="select-field" value={epicTitle} onChange={e => setEpicTitle(e.target.value)}>
            <option value="">No epic</option>
            {epics.map(ep => <option key={ep.id} value={ep.title}>{ep.title}</option>)}
          </select>
        </div>

        {error && <p className="text-[12px] text-red-400">{error}</p>}

        {suggestion && (
          <div className="rounded-lg border border-zinc-700 bg-surface-3 p-4 space-y-2 text-[13px]">
            <p><strong className="text-zinc-200">Title:</strong> <span className="text-zinc-300">{suggestion.title}</span></p>
            <p><strong className="text-zinc-200">Description:</strong> <span className="text-zinc-400">{suggestion.description}</span></p>
            <div>
              <strong className="text-zinc-200">Acceptance Criteria:</strong>
              <p className="text-zinc-400 whitespace-pre-line mt-0.5">{suggestion.acceptance_criteria}</p>
            </div>
            <p className="text-zinc-500">
              <strong className="text-zinc-300">Points:</strong> {suggestion.suggested_points || '?'} &nbsp;|&nbsp;
              <strong className="text-zinc-300">Priority:</strong> {suggestion.priority || 'medium'}
            </p>
            <button onClick={handleUse} disabled={saving} className="btn-primary text-xs mt-2">
              {saving ? 'Creating...' : 'Use This Story'}
            </button>
          </div>
        )}
      </div>
    </Modal>
  )
}

// ── AI Report Bug Modal — sourced from agile.js lines 973-1010 ──

function AiWriteBugModal({ sprintId, onClose, onCreated }: { sprintId?: number | null; onClose: () => void; onCreated: () => void }) {
  const [description, setDescription] = useState('')
  const [generating, setGenerating] = useState(false)
  const [suggestion, setSuggestion] = useState<any>(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  async function handleGenerate() {
    if (!description.trim()) return
    setGenerating(true)
    setError('')
    setSuggestion(null)
    try {
      const r = await agileAiAPI.writeBug({ description: description.trim() })
      const data = r.data || {}
      if (data.suggestion) {
        setSuggestion(data.suggestion)
      } else {
        setError(data.raw || 'Could not generate bug report.')
      }
    } catch (e: any) {
      setError(e?.response?.data?.error || e?.message || 'AI service unavailable')
    } finally {
      setGenerating(false)
    }
  }

  async function handleUse() {
    if (!suggestion) return
    setSaving(true)
    try {
      await bugsAPI.create({
        title: suggestion.title,
        description: suggestion.description,
        steps_to_reproduce: suggestion.steps_to_reproduce,
        severity: suggestion.severity || 'medium',
        priority: suggestion.priority || 'medium',
        environment: suggestion.environment || 'production',
        sprint_id: sprintId || null
      })
      toast.success('Bug reported from Tessa suggestion')
      onCreated()
    } catch (e: any) {
      toast.error(e?.message || 'Failed to report bug')
      setSaving(false)
    }
  }

  return (
    <Modal open={true} onClose={onClose} title="Tessa — Report Bug" width="max-w-lg" footer={
      <><button onClick={onClose} className="btn-secondary">Cancel</button>
        <button onClick={handleGenerate} disabled={generating || !description.trim()} className="btn-primary">
          {generating ? 'Tessa is thinking...' : 'Generate'}
        </button></>
    }>
      <div className="space-y-3">
        <div>
          <label className="label-text">Describe the bug casually:</label>
          <textarea className="textarea-field" rows={4} value={description} onChange={e => setDescription(e.target.value)}
            placeholder='e.g. "when I try to upload an invoice with a large file it just shows a blank page"' />
        </div>

        {error && <p className="text-[12px] text-red-400">{error}</p>}

        {suggestion && (
          <div className="rounded-lg border border-zinc-700 bg-surface-3 p-4 space-y-2 text-[13px]">
            <p><strong className="text-zinc-200">Title:</strong> <span className="text-zinc-300">{suggestion.title}</span></p>
            <p><strong className="text-zinc-200">Description:</strong> <span className="text-zinc-400">{suggestion.description}</span></p>
            <div>
              <strong className="text-zinc-200">Steps to Reproduce:</strong>
              <p className="text-zinc-400 whitespace-pre-line mt-0.5">{suggestion.steps_to_reproduce}</p>
            </div>
            <p className="text-zinc-500">
              <strong className="text-zinc-300">Severity:</strong> {suggestion.severity || 'medium'} &nbsp;|&nbsp;
              <strong className="text-zinc-300">Priority:</strong> {suggestion.priority || 'medium'} &nbsp;|&nbsp;
              <strong className="text-zinc-300">Env:</strong> {suggestion.environment || 'production'}
            </p>
            <button onClick={handleUse} disabled={saving} className="btn-primary text-xs mt-2">
              {saving ? 'Reporting...' : 'Report This Bug'}
            </button>
          </div>
        )}
      </div>
    </Modal>
  )
}
