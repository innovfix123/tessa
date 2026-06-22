import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { tessaAPI, tasksAPI } from '@/api/client'
import { formatTessaReply, classNames } from '@/lib/utils'
import { Modal } from '@/components/ui'
import { Plus, CheckSquare, Send, Pencil } from 'lucide-react'
import toast from 'react-hot-toast'

interface ChatMsg { role: 'user' | 'assistant'; content: string; html?: string }
interface ServerChat { id: number; title: string; created_at: string }

function detectSteps(msg: string): string[] {
  const m = msg.toLowerCase()
  if (/leave|sick|wfh|vacation|day off|casual|earned|comp off/.test(m)) {
    if (/cancel/.test(m)) return ['Finding your leave request...', 'Cancelling...']
    if (/balance|how many|remaining/.test(m)) return ['Checking your leave balance...']
    return ['Processing your leave request...', 'Almost done...']
  }
  if (/sign.?off|signoff/.test(m)) return ['Checking your pending items...', 'Preparing sign-off...']
  if (/sign.?in|good morning|morning briefing/.test(m)) return ['Preparing your morning briefing...']
  if (/pending|to.?do|what.*need/.test(m)) return ['Checking your pending work...']
  if (/meeting/.test(m)) return ['Reviewing your meetings...']
  if (/daily.*report|report/.test(m)) return ['Looking at daily reports...']
  if (/kpi|target|metric/.test(m)) return ['Analyzing KPI status...']
  if (/escalat/.test(m)) return ['Checking escalations...']
  if (/slack|send|dm|message/.test(m)) return ['Sending message...']
  return ['Thinking...']
}

function useTypewriter() {
  const frameRef = useRef<number>(0)
  function type(setText: (html: string) => void, reply: string, onComplete: () => void) {
    const len = reply.length
    const duration = Math.min(Math.max(len * 4, 600), 2200)
    const start = performance.now()
    function tick(now: number) {
      const elapsed = now - start
      let t = Math.min(elapsed / duration, 1)
      t = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2
      const pos = Math.round(t * len)
      setText(formatTessaReply(reply.slice(0, pos)))
      if (t < 1) frameRef.current = requestAnimationFrame(tick)
      else { setText(formatTessaReply(reply)); onComplete() }
    }
    frameRef.current = requestAnimationFrame(tick)
  }
  function cancel() { cancelAnimationFrame(frameRef.current) }
  return { type, cancel }
}

export default function TessaChat(): JSX.Element {
  const { user, people } = useAuth()
  const navigate = useNavigate()
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const { type: startTypewriter, cancel: cancelTypewriter } = useTypewriter()

  const [chatId, setChatId] = useState<number | null>(null)
  const [messages, setMessages] = useState<ChatMsg[]>([])
  const [inputText, setInputText] = useState('')
  const [sending, setSending] = useState(false)
  const [typingHtml, setTypingHtml] = useState<string | null>(null)
  const [searchStep, setSearchStep] = useState<string | null>(null)
  const [taskModalOpen, setTaskModalOpen] = useState(false)
  const [initialLoading, setInitialLoading] = useState(true)

  const greeting: ChatMsg = {
    role: 'assistant',
    content: `How can I help, ${user?.name || 'Team'}?`,
    html: `How can I help, ${user?.name || 'Team'}?`
  }

  const hasUserMessages = messages.some((m) => m.role === 'user')

  // Load most recent chat from server on mount
  useEffect(() => {
    ;(async () => {
      try {
        const res = await tessaAPI.chats()
        const chats: ServerChat[] = res.data?.chats || []
        if (chats.length > 0) {
          const latest = chats[0]
          setChatId(latest.id)
          const msgRes = await tessaAPI.messages(latest.id)
          const serverMsgs: ChatMsg[] = (msgRes.data?.messages || []).map((m: any) => ({
            role: m.role,
            content: m.content || m.text || '',
            html: m.role === 'assistant' ? formatTessaReply(m.content || m.text || '') : undefined
          }))
          if (serverMsgs.length > 0) {
            if (serverMsgs[0].role === 'user') serverMsgs.unshift(greeting)
            setMessages(serverMsgs)
          } else {
            setMessages([greeting])
          }
        } else {
          setMessages([greeting])
        }
      } catch {
        setMessages([greeting])
      } finally {
        setInitialLoading(false)
      }
    })()
  }, [])

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages.length, typingHtml])

  function newChat() {
    cancelTypewriter()
    setSending(false)
    setTypingHtml(null)
    setSearchStep(null)
    setInputText('')
    setChatId(null)
    setMessages([greeting])
    inputRef.current?.focus()
  }

  async function handleSend(overrideText?: string) {
    const text = (overrideText || inputText).trim()
    if (!text || sending) return

    setInputText('')
    setSending(true)

    const userMsg: ChatMsg = { role: 'user', content: text }
    const updatedMessages = [...messages, userMsg]
    setMessages(updatedMessages)

    const steps = detectSteps(text)
    setSearchStep(steps[0])
    let stepIdx = 0
    const stepTimer = setInterval(() => {
      stepIdx++
      if (stepIdx < steps.length) setSearchStep(steps[stepIdx])
    }, 2000)

    const apiMessages = updatedMessages
      .filter((m) => m.role === 'user' || m.role === 'assistant')
      .slice(-10)
      .map((m) => ({ role: m.role, content: m.content }))

    try {
      const payload: Record<string, unknown> = { messages: apiMessages }
      if (chatId) payload.chat_id = chatId

      const res = await tessaAPI.chat(payload)

      clearInterval(stepTimer)
      setSearchStep(null)

      const reply = res.data?.reply || 'Sorry, I encountered an error.'
      const serverChatId = res.data?.chat_id

      if (serverChatId) setChatId(serverChatId)

      setTypingHtml('')
      startTypewriter(
        (html) => setTypingHtml(html),
        reply,
        () => {
          setTypingHtml(null)
          const assistantMsg: ChatMsg = {
            role: 'assistant',
            content: reply,
            html: formatTessaReply(reply)
          }
          setMessages((prev) => [...prev, assistantMsg])
          setSending(false)
        }
      )
    } catch {
      clearInterval(stepTimer)
      setSearchStep(null)
      setSending(false)
      toast.error('Failed to get a response from Tessa')
    }
  }

  function handleQuickAction(action: string) {
    const textMap: Record<string, string> = {
      signin: 'Sign in for today',
      signoff: 'Sign off for today',
      pending: 'Show my pending work',
      pendingwork: 'Show my pending work',
      clear: ''
    }
    if (action === 'clear') { newChat(); return }
    const text = textMap[action]
    if (!text) return
    handleSend(text)
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const userInitial = (user?.name || '?').charAt(0).toUpperCase()

  if (initialLoading) {
    return (
      <div className="flex items-center justify-center h-[calc(100vh-5rem)] -m-5">
        <div className="flex gap-3 items-center">
          <div className="h-8 w-8 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-sm animate-pulse">T</div>
          <span className="text-sm text-zinc-500">Loading chat...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-[calc(100vh-5rem)] -m-5">
      <div className="flex-1 flex flex-col overflow-hidden relative">
        {/* Top buttons */}
        <div className="absolute top-3 right-3 flex gap-2 z-10">
          <button onClick={() => navigate('/tasks')} className="btn-icon" title="Tasks">
            <CheckSquare className="h-4 w-4" />
          </button>
          <button onClick={newChat} className="btn-icon" title="New Chat">
            <Pencil className="h-4 w-4" />
          </button>
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
          {messages.map((msg, i) => (
            <div key={i} className="flex gap-3 max-w-3xl mx-auto">
              {msg.role === 'assistant' ? (
                <div className="h-8 w-8 shrink-0 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-sm">T</div>
              ) : (
                <div className="h-8 w-8 shrink-0 rounded-lg bg-zinc-700 flex items-center justify-center text-zinc-200 font-semibold text-sm">{userInitial}</div>
              )}
              <div className="flex-1 min-w-0">
                {msg.role === 'assistant' ? (
                  <div
                    className="text-sm text-zinc-300 leading-relaxed tessa-reply"
                    dangerouslySetInnerHTML={{ __html: msg.html || formatTessaReply(msg.content) }}
                  />
                ) : (
                  <p className="text-sm text-zinc-200">{msg.content}</p>
                )}
              </div>
            </div>
          ))}

          {/* Typewriter / loading row */}
          {(sending || typingHtml !== null) && (
            <div className="flex gap-3 max-w-3xl mx-auto">
              <div className={classNames(
                'h-8 w-8 shrink-0 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-sm',
                typingHtml === null && 'animate-pulse'
              )}>T</div>
              <div className="flex-1 min-w-0">
                {typingHtml !== null ? (
                  <div
                    className="text-sm text-zinc-300 leading-relaxed tessa-reply"
                    dangerouslySetInnerHTML={{ __html: typingHtml }}
                  />
                ) : searchStep ? (
                  <span className="text-sm text-zinc-500 animate-pulse">{searchStep}</span>
                ) : (
                  <span className="text-sm text-zinc-500 animate-pulse">Thinking...</span>
                )}
              </div>
            </div>
          )}

          {/* Quick action chips — new chats with no user messages */}
          {!hasUserMessages && !sending && (
            <div className="flex gap-2 justify-center pt-4">
              {['Sign In', 'Sign Off', 'Pending Work'].map((label) => (
                <button
                  key={label}
                  onClick={() => handleQuickAction(label.toLowerCase().replace(/\s+/g, ''))}
                  className="rounded-full bg-surface-3 border border-zinc-700 px-4 py-1.5 text-xs text-zinc-400 hover:text-zinc-200 hover:border-zinc-600 transition-colors"
                >
                  {label}
                </button>
              ))}
            </div>
          )}

          <div ref={messagesEndRef} />
        </div>

        {/* Input area */}
        <div className="border-t border-zinc-800 px-5 py-3">
          <div className="flex items-end gap-2 max-w-3xl mx-auto">
            <button onClick={() => setTaskModalOpen(true)} className="btn-icon p-2" title="Assign Task">
              <Plus className="h-4 w-4" />
            </button>
            <textarea
              ref={inputRef}
              value={inputText}
              onChange={(e) => {
                setInputText(e.target.value)
                const el = e.target
                el.style.height = 'auto'
                el.style.height = Math.min(el.scrollHeight, 200) + 'px'
              }}
              placeholder="Ask Tessa anything"
              rows={1}
              onKeyDown={handleKeyDown}
              className="flex-1 resize-none bg-surface-3 border border-zinc-700 rounded-lg px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-brand-500/40 focus:border-brand-600"
              disabled={sending}
            />
            <button
              onClick={() => handleSend()}
              disabled={sending || !inputText.trim()}
              className="btn-icon p-2 text-brand-400 hover:text-brand-300 disabled:opacity-30"
              title="Send"
            >
              <Send className="h-4 w-4" />
            </button>
          </div>
          <div className="flex gap-2 justify-center mt-2">
            {[
              { key: 'pending', label: 'Pending Work' },
              { key: 'signin', label: 'Sign In' },
              { key: 'signoff', label: 'Sign Off' },
              { key: 'clear', label: 'Clear Chat' }
            ].map((chip) => (
              <button
                key={chip.key}
                onClick={() => handleQuickAction(chip.key)}
                disabled={sending}
                className={classNames(
                  'rounded-full px-3 py-1 text-[11px] font-medium transition-colors',
                  chip.key === 'clear'
                    ? 'text-zinc-500 hover:text-zinc-300'
                    : 'bg-surface-3 border border-zinc-800 text-zinc-500 hover:text-zinc-300 hover:border-zinc-700'
                )}
              >
                {chip.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      <TaskCreateModal
        open={taskModalOpen}
        people={people || []}
        onClose={() => setTaskModalOpen(false)}
        onCreated={(taskTitle, assigneeName) => {
          setTaskModalOpen(false)
          const sysMsg: ChatMsg = {
            role: 'assistant',
            content: `Task assigned to ${assigneeName}: ${taskTitle}`,
            html: `<em>Task assigned to <strong>${assigneeName}</strong>: ${taskTitle}</em>`
          }
          setMessages((prev) => [...prev, sysMsg])
        }}
      />
    </div>
  )
}

// ── Task Create Modal ──

function TaskCreateModal({
  open, people, onClose, onCreated
}: {
  open: boolean
  people: Array<{ id: number; name: string }>
  onClose: () => void
  onCreated: (title: string, assigneeName: string) => void
}) {
  const [assignedTo, setAssignedTo] = useState<number | null>(null)
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState('medium')
  const [deadline, setDeadline] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (open) {
      setAssignedTo(null); setTitle(''); setDescription(''); setPriority('medium'); setDeadline('')
    }
  }, [open])

  function setQuickDeadline(offset: string) {
    const now = new Date()
    if (offset === 'eod') {
      now.setHours(18, 0, 0, 0)
    } else if (offset === 'tomorrow') {
      now.setDate(now.getDate() + 1); now.setHours(18, 0, 0, 0)
    } else if (offset === 'week') {
      const day = now.getDay()
      now.setDate(now.getDate() + (day <= 5 ? 5 - day : 6)); now.setHours(18, 0, 0, 0)
    } else { setDeadline(''); return }
    const y = now.getFullYear(), m = String(now.getMonth() + 1).padStart(2, '0'),
      d = String(now.getDate()).padStart(2, '0'), h = String(now.getHours()).padStart(2, '0'),
      min = String(now.getMinutes()).padStart(2, '0')
    setDeadline(`${y}-${m}-${d}T${h}:${min}`)
  }

  async function handleSubmit() {
    if (!assignedTo || !title.trim()) { toast.error('Please select a person and enter a title'); return }
    setSaving(true)
    try {
      const payload: Record<string, unknown> = { assigned_to: assignedTo, title: title.trim(), priority }
      if (description.trim()) payload.description = description.trim()
      if (deadline) payload.deadline = deadline
      await tasksAPI.create(payload)
      const assigneeName = people.find((p) => p.id === assignedTo)?.name || 'someone'
      toast.success('Task assigned')
      onCreated(title.trim(), assigneeName)
    } catch { toast.error('Failed to assign task') } finally { setSaving(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Assign Task" width="max-w-lg" footer={
      <>
        <button onClick={onClose} className="btn-secondary">Cancel</button>
        <button onClick={handleSubmit} disabled={saving} className="btn-primary">{saving ? 'Assigning...' : 'Assign'}</button>
      </>
    }>
      <div className="space-y-4">
        <div>
          <label className="label-text">Assign to <span className="text-zinc-600">— click a name</span></label>
          <div className="flex flex-wrap gap-1.5 mt-1">
            {people.map((p) => (
              <button key={p.id} type="button" onClick={() => setAssignedTo(p.id)}
                className={classNames('px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                  assignedTo === p.id ? 'bg-brand-600/20 text-brand-400 border-brand-500/30' : 'bg-zinc-800 text-zinc-400 border-zinc-700 hover:border-zinc-600')}>
                {p.name}
              </button>
            ))}
          </div>
        </div>
        <div>
          <label className="label-text">Title</label>
          <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Check if new scores strategy is working" className="input-field" />
        </div>
        <div>
          <label className="label-text">Description <span className="text-zinc-600">— optional</span></label>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} placeholder="Add details..." className="textarea-field" />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label-text">Priority</label>
            <select value={priority} onChange={(e) => setPriority(e.target.value)} className="select-field">
              <option value="low">Low</option><option value="medium">Medium</option>
              <option value="high">High</option><option value="urgent">Urgent</option>
            </select>
          </div>
          <div>
            <label className="label-text">Deadline <span className="text-zinc-600">— optional</span></label>
            <div className="flex gap-1 mb-1.5">
              {[{ key: 'eod', label: 'EOD' }, { key: 'tomorrow', label: 'Tomorrow' }, { key: 'week', label: 'Friday' }, { key: 'clear', label: 'Clear' }].map((b) => (
                <button key={b.key} type="button" onClick={() => setQuickDeadline(b.key)}
                  className="text-[10px] px-2 py-0.5 rounded bg-zinc-800 text-zinc-400 hover:text-zinc-200 border border-zinc-700">{b.label}</button>
              ))}
            </div>
            <input type="datetime-local" value={deadline} onChange={(e) => setDeadline(e.target.value)} className="input-field text-xs" />
          </div>
        </div>
      </div>
    </Modal>
  )
}
