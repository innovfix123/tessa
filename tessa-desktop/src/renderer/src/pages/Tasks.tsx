import { useState, useEffect, useCallback, useRef } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { tasksAPI } from '@/api/client'
import { Modal, Loader, Avatar } from '@/components/ui'
import { classNames, initials, relativeTime, prettyDateTime } from '@/lib/utils'
import { Send, UserPlus, Search, X } from 'lucide-react'
import toast from 'react-hot-toast'

interface Task {
  id: number; title: string; description?: string; priority: string; status: string
  status_note?: string; ai_summary?: string; blocker_status?: string; blocker_note?: string
  message_count: number; unread_count: number
  people: Array<{ name: string; role: string }>
  assigned_by: { id: number; name: string } | null
  assigned_to: { id: number; name: string } | null
  deadline: string | null; is_overdue: boolean
  completed_at: string | null; created_at: string; updated_at: string
}

interface ThreadMsg {
  id: number; user_id: number; user_name: string; content: string
  created_at: string; is_unread: boolean
}

interface Participant { user_id: number; user_name: string; role: string }

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'assigned_to_me', label: 'Assigned to Me' },
  { key: 'assigned_by_me', label: 'Assigned by Me' }
]

const statusLabel: Record<string, string> = {
  completed: 'Done', pending: 'Pending', in_progress: 'In Progress'
}
const statusClass: Record<string, string> = {
  completed: 'bg-emerald-500/15 text-emerald-400',
  pending: 'bg-zinc-700/40 text-zinc-400',
  in_progress: 'bg-blue-500/15 text-blue-400'
}
const priorityClass: Record<string, string> = {
  urgent: 'bg-red-500', high: 'bg-orange-500', medium: 'bg-amber-500', low: 'bg-zinc-500'
}

function fmtDeadline(iso: string | null): string {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
  } catch { return '' }
}

function fmtDetailDeadline(iso: string | null): string {
  if (!iso) return 'No deadline'
  try {
    const d = new Date(iso)
    return d.toLocaleDateString('en-IN', {
      weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true
    })
  } catch { return iso }
}

export default function Tasks(): JSX.Element {
  const { user, people } = useAuth()
  const [tasks, setTasks] = useState<Task[]>([])
  const [filter, setFilter] = useState('all')
  const [loading, setLoading] = useState(true)
  const [selectedTask, setSelectedTask] = useState<Task | null>(null)
  const [modalOpen, setModalOpen] = useState(false)

  // Thread state
  const [threadMsgs, setThreadMsgs] = useState<ThreadMsg[]>([])
  const [participants, setParticipants] = useState<Participant[]>([])
  const [threadInput, setThreadInput] = useState('')
  const [threadLoading, setThreadLoading] = useState(false)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteSearch, setInviteSearch] = useState('')
  const threadEndRef = useRef<HTMLDivElement>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await tasksAPI.list({ filter })
      setTasks(res.data?.tasks || [])
    } catch {
      toast.error('Failed to load tasks')
    } finally {
      setLoading(false)
    }
  }, [filter])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    threadEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [threadMsgs.length])

  async function openTask(task: Task) {
    setSelectedTask(task)
    setModalOpen(true)
    setThreadLoading(true)
    try {
      const res = await tasksAPI.thread(task.id)
      setThreadMsgs(res.data?.messages || [])
      setParticipants(res.data?.participants || [])
    } catch {
      toast.error('Failed to load thread')
    } finally {
      setThreadLoading(false)
    }
  }

  async function sendThreadMessage() {
    if (!threadInput.trim() || !selectedTask) return
    const content = threadInput.trim()
    setThreadInput('')

    // Optimistic add
    const optimistic: ThreadMsg = {
      id: Date.now(), user_id: user?.id || 0, user_name: user?.name || '?',
      content, created_at: new Date().toISOString(), is_unread: false
    }
    setThreadMsgs((prev) => [...prev, optimistic])

    try {
      const res = await tasksAPI.postThread(selectedTask.id, { content })
      if (res.data?.ai_summary) {
        setSelectedTask((t) => t ? { ...t, ai_summary: res.data.ai_summary } : t)
      }
    } catch {
      toast.error('Failed to send message')
    }
  }

  async function inviteUser(userId: number) {
    if (!selectedTask) return
    try {
      await tasksAPI.invite(selectedTask.id, { user_id: userId })
      toast.success('User invited')
      setInviteOpen(false)
      // Reload thread
      const res = await tasksAPI.thread(selectedTask.id)
      setParticipants(res.data?.participants || [])
    } catch {
      toast.error('Failed to invite')
    }
  }

  const filteredPeople = (people || []).filter((p) => {
    if (participants.some((pt) => pt.user_id === p.id)) return false
    if (!inviteSearch) return true
    return p.name.toLowerCase().includes(inviteSearch.toLowerCase())
  })

  return (
    <div className="space-y-4">
      {/* Header + filters */}
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-zinc-100">Tasks</h3>
        <div className="flex gap-1 rounded-lg bg-surface-1 p-1">
          {FILTERS.map((f) => (
            <button
              key={f.key}
              onClick={() => setFilter(f.key)}
              className={classNames(
                'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                filter === f.key
                  ? 'bg-zinc-800 text-zinc-100'
                  : 'text-zinc-500 hover:text-zinc-300'
              )}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      {/* Grid */}
      {loading ? (
        <Loader size="sm" label="Loading tasks..." />
      ) : tasks.length === 0 ? (
        <div className="text-sm text-zinc-500 text-center py-12">No tasks yet.</div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {tasks.map((t) => {
            const isCompleted = t.status === 'completed'
            const sLabel = t.is_overdue ? 'Overdue' : (statusLabel[t.status] || t.status)
            const sClass = t.is_overdue ? 'bg-red-500/15 text-red-400' : (statusClass[t.status] || statusClass.pending)

            return (
              <div
                key={t.id}
                onClick={() => openTask(t)}
                className={classNames(
                  'card-hover relative',
                  isCompleted && 'opacity-60'
                )}
              >
                {/* Unread badge */}
                {t.unread_count > 0 && (
                  <span className="absolute top-2 right-2 h-5 w-5 rounded-full bg-brand-600 text-white text-[10px] font-bold flex items-center justify-center">
                    {t.unread_count}
                  </span>
                )}

                <div className="flex items-start justify-between gap-2 mb-2">
                  <span className="text-sm font-medium text-zinc-200 line-clamp-2">{t.title}</span>
                </div>

                <div className="flex items-center gap-2 mb-2">
                  <span className={classNames('rounded-full px-2 py-0.5 text-[10px] font-medium', sClass)}>
                    {sLabel}
                  </span>
                  {fmtDeadline(t.deadline) && (
                    <span className="text-[11px] text-zinc-500">{fmtDeadline(t.deadline)}</span>
                  )}
                </div>

                {t.ai_summary && (
                  <p className="text-[11px] text-zinc-500 line-clamp-2 mb-2">{t.ai_summary}</p>
                )}

                {t.blocker_status === 'blocked' && t.blocker_note && (
                  <div className="text-[11px] text-red-400 bg-red-500/10 rounded px-2 py-1 mb-2">
                    {t.blocker_note}
                  </div>
                )}

                <div className="flex items-center justify-between">
                  <div className="flex -space-x-1.5">
                    {t.people.slice(0, 3).map((p, i) => (
                      <Avatar key={i} name={p.name} size="sm" className="ring-1 ring-surface-2" />
                    ))}
                    {t.people.length > 3 && (
                      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-zinc-700 text-[10px] text-zinc-400 ring-1 ring-surface-2">
                        +{t.people.length - 3}
                      </span>
                    )}
                  </div>
                  <span className={classNames('h-2.5 w-2.5 rounded-full', priorityClass[t.priority] || priorityClass.medium)} title={t.priority} />
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Task Detail Modal */}
      <Modal
        open={modalOpen}
        onClose={() => { setModalOpen(false); setSelectedTask(null); setInviteOpen(false); load() }}
        title={selectedTask?.title || 'Task'}
        width="max-w-2xl"
      >
        {selectedTask && (
          <div className="space-y-4">
            {/* Meta */}
            <div className="flex items-center gap-2">
              <span className={classNames(
                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                selectedTask.is_overdue ? 'bg-red-500/15 text-red-400' :
                  (statusClass[selectedTask.status] || statusClass.pending)
              )}>
                {selectedTask.is_overdue ? 'Overdue' : (statusLabel[selectedTask.status] || selectedTask.status)}
              </span>
              <span className={classNames(
                'rounded-full px-2.5 py-0.5 text-xs font-medium bg-zinc-800 text-zinc-400'
              )}>
                {selectedTask.priority}
              </span>
            </div>

            <div className="grid grid-cols-3 gap-3 text-sm">
              <div><span className="text-zinc-500">Assigned by:</span> <strong className="text-zinc-200">{selectedTask.assigned_by?.name || '—'}</strong></div>
              <div><span className="text-zinc-500">Assigned to:</span> <strong className="text-zinc-200">{selectedTask.assigned_to?.name || '—'}</strong></div>
              <div><span className="text-zinc-500">Deadline:</span> <strong className="text-zinc-200">{fmtDetailDeadline(selectedTask.deadline)}</strong></div>
            </div>

            {selectedTask.description && (
              <p className="text-sm text-zinc-400">{selectedTask.description}</p>
            )}
            {selectedTask.blocker_status === 'blocked' && selectedTask.blocker_note && (
              <div className="text-sm text-red-400 bg-red-500/10 rounded-lg px-3 py-2">{selectedTask.blocker_note}</div>
            )}
            {selectedTask.ai_summary && (
              <div className="text-xs text-zinc-500 bg-surface-3 rounded-lg px-3 py-2 italic">{selectedTask.ai_summary}</div>
            )}
            {selectedTask.status_note && (
              <div className="text-sm text-zinc-400"><strong className="text-zinc-300">Status Note:</strong> {selectedTask.status_note}</div>
            )}

            {/* Thread */}
            <div className="border-t border-zinc-800 pt-4">
              <div className="flex items-center justify-between mb-3">
                <span className="text-sm font-semibold text-zinc-300">Thread</span>
                <button onClick={() => setInviteOpen(!inviteOpen)} className="text-xs text-brand-400 hover:text-brand-300">
                  <UserPlus className="h-3.5 w-3.5 inline mr-1" />Invite
                </button>
              </div>

              {/* Participants */}
              <div className="flex flex-wrap gap-1.5 mb-3">
                {participants.map((p) => (
                  <span key={p.user_id} className={classNames(
                    'rounded-full px-2 py-0.5 text-[10px] font-medium border',
                    p.role === 'assigner' ? 'border-amber-500/30 text-amber-400 bg-amber-500/10' :
                    p.role === 'assignee' ? 'border-brand-500/30 text-brand-400 bg-brand-500/10' :
                    'border-zinc-700 text-zinc-400 bg-zinc-800'
                  )}>
                    {p.user_name}
                  </span>
                ))}
              </div>

              {/* Invite dropdown */}
              {inviteOpen && (
                <div className="card mb-3 max-h-48 overflow-y-auto">
                  <div className="relative mb-2">
                    <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-3 w-3 text-zinc-500" />
                    <input
                      value={inviteSearch}
                      onChange={(e) => setInviteSearch(e.target.value)}
                      placeholder="Search people..."
                      className="input-field text-xs pl-7"
                      autoFocus
                    />
                  </div>
                  {filteredPeople.length === 0 ? (
                    <p className="text-xs text-zinc-500 text-center py-2">Everyone is already in this thread</p>
                  ) : (
                    filteredPeople.map((p) => (
                      <button
                        key={p.id}
                        onClick={() => inviteUser(p.id)}
                        className="flex items-center gap-2 w-full px-2 py-1.5 rounded hover:bg-zinc-800 text-sm text-zinc-300"
                      >
                        <Avatar name={p.name} size="sm" />
                        {p.name}
                      </button>
                    ))
                  )}
                </div>
              )}

              {/* Messages */}
              {threadLoading ? (
                <Loader size="sm" />
              ) : (
                <div className="space-y-2 max-h-60 overflow-y-auto mb-3">
                  {threadMsgs.map((m, i) => {
                    const isMe = m.user_id === user?.id
                    const time = (() => {
                      try {
                        return new Date(m.created_at).toLocaleTimeString('en-IN', {
                          hour: 'numeric', minute: '2-digit', hour12: true
                        })
                      } catch { return '' }
                    })()
                    const showUnread = m.is_unread && !isMe && !threadMsgs.slice(0, i).some((prev) => prev.is_unread && prev.user_id !== user?.id)

                    return (
                      <div key={m.id}>
                        {showUnread && (
                          <div className="flex items-center gap-2 my-2">
                            <div className="flex-1 h-px bg-brand-600/50" />
                            <span className="text-[10px] text-brand-400 font-medium">Unread</span>
                            <div className="flex-1 h-px bg-brand-600/50" />
                          </div>
                        )}
                        <div className={classNames('flex', isMe ? 'justify-end' : 'justify-start')}>
                          <div className={classNames(
                            'max-w-[75%] rounded-lg px-3 py-2',
                            isMe ? 'bg-brand-600/20 text-zinc-200' : 'bg-surface-3 text-zinc-300'
                          )}>
                            <div className="flex items-center gap-2 mb-0.5">
                              <span className="text-[11px] font-medium text-zinc-400">{m.user_name}</span>
                              <span className="text-[10px] text-zinc-600">{time}</span>
                            </div>
                            <p className="text-sm">{m.content}</p>
                          </div>
                        </div>
                      </div>
                    )
                  })}
                  <div ref={threadEndRef} />
                </div>
              )}

              {/* Compose */}
              <div className="flex gap-2">
                <textarea
                  value={threadInput}
                  onChange={(e) => setThreadInput(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendThreadMessage() }
                  }}
                  placeholder="Type a message..."
                  rows={1}
                  className="flex-1 resize-none textarea-field text-xs"
                />
                <button onClick={sendThreadMessage} className="btn-icon text-brand-400">
                  <Send className="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
