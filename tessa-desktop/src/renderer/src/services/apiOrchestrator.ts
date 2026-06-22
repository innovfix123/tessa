/**
 * API Orchestrator v2
 *
 * Comprehensive data fetcher for Tessa Voice Assistant.
 * Fetches COMPLETE data (not truncated) so the AI can give accurate, detailed responses.
 * Designed for personal assistant use for JP and Fida.
 */

import {
  pendingWorkAPI,
  tasksAPI,
  sprintsAPI,
  meetingsAPI,
  dailyReportsAPI,
  kpiAPI,
  signoffAPI,
  escalationsAPI,
  revenueAPI,
  metaAdsAPI,
  googleAdsAPI,
  invoicesAPI,
  ticketsAPI,
  dashboardAPI,
  employeesAPI,
  leaveAPI,
  releasesAPI,
  scriptsAPI,
  storiesAPI,
  epicsAPI,
  bugsAPI,
  agileDashboardAPI
} from '@/api/client'

export interface OrchestratorResult {
  data: Record<string, unknown>
  summaryHint: string
}

type IntentHandler = (params: Record<string, string>, userRole?: string) => Promise<OrchestratorResult>

// ── Date/Time Utilities ──

function getWeekKey(offset = 0): string {
  const now = new Date()
  now.setDate(now.getDate() + offset * 7)
  const day = now.getDay()
  const diff = day === 0 ? -6 : 1 - day
  const monday = new Date(now)
  monday.setDate(now.getDate() + diff)
  return monday.toLocaleDateString('en-CA')
}

function currentWeekKey(): string {
  return getWeekKey(0)
}

function lastWeekKey(): string {
  return getWeekKey(-1)
}

function resolveWeekKey(timeRef?: string): string {
  if (!timeRef) return currentWeekKey()
  const lc = timeRef.toLowerCase()
  if (lc.includes('last week') || lc.includes('previous week')) return lastWeekKey()
  return currentWeekKey()
}

function fmtDate(iso: string | null | undefined): string | null {
  if (!iso) return null
  try {
    const d = new Date(iso)
    if (isNaN(d.getTime())) return iso
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
  } catch { return iso }
}

function fmtDateTime(iso: string | null | undefined): string | null {
  if (!iso) return null
  try {
    const d = new Date(iso)
    if (isNaN(d.getTime())) return iso
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
  } catch { return iso }
}

function getTodayDate(): string {
  return new Date().toLocaleDateString('en-CA')
}

function getYesterdayDate(): string {
  const d = new Date()
  d.setDate(d.getDate() - 1)
  return d.toLocaleDateString('en-CA')
}

function resolveDayName(input?: string): string | undefined {
  if (!input) return undefined
  const lc = input.toLowerCase().trim()
  const weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
  if (weekdays.includes(lc)) return lc
  if (lc === 'today') return new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
  if (lc === 'yesterday') {
    const d = new Date()
    d.setDate(d.getDate() - 1)
    return d.toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
  }
  return undefined
}

function buildMeetingId(meetingKey: string, recurrence: string, dayOverride?: string): string {
  if (recurrence !== 'daily_weekdays') return meetingKey
  const dayName = (dayOverride || new Date().toLocaleDateString('en-US', { weekday: 'long' })).toLowerCase()
  const suffixMap: Record<string, string> = {
    monday: '', tuesday: '-tue', wednesday: '-wed', thursday: '-thu', friday: '-fri',
    saturday: '', sunday: ''
  }
  return meetingKey + (suffixMap[dayName] ?? '')
}

// ── Data Summarizers (full detail, not truncated) ──

function fullTask(t: any): Record<string, unknown> {
  return {
    id: t.id,
    title: t.title,
    description: t.description || null,
    status: t.status,
    priority: t.priority,
    deadline: fmtDate(t.deadline),
    is_overdue: !!t.is_overdue,
    progress: t.progress,
    assigned_to: t.assigned_to?.name || null,
    assigned_by: t.assigned_by?.name || null,
    blocker_status: t.blocker_status,
    blocker_note: t.blocker_note || null,
    status_note: t.status_note || null,
    ai_summary: t.ai_summary || null,
    subtask_done: t.subtask_done,
    subtask_total: t.subtask_total,
    message_count: t.message_count,
    unread_count: t.unread_count,
    created_at: fmtDate(t.created_at),
    updated_at: fmtDate(t.updated_at),
    people: Array.isArray(t.people) ? t.people.map((p: any) => p.name || p) : []
  }
}

function fullMeeting(m: any): Record<string, unknown> {
  return {
    id: m.id,
    meeting_key: m.meetingKey,
    title: m.title,
    time: m.time,
    day_of_week: m.dayOfWeek,
    owner: m.owner,
    recurrence: m.recurrence,
    attendees: Array.isArray(m.attendees) ? m.attendees : [],
    portal: m.portal
  }
}

function fullActionItem(a: any): Record<string, unknown> {
  return {
    id: a.id,
    task: a.task,
    owner: a.owner,
    deadline: fmtDate(a.deadline),
    status: a.status,
    priority: a.priority,
    meeting_title: a.meeting_title || a.meetingTitle || null,
    meeting_date: a.meeting_date || null,
    comment: a.comment || null,
    completed_at: fmtDateTime(a.completedAt || a.completed_at),
    carried_from: a.carriedFromWeek || a.carried_from_week || null
  }
}

function fullEscalation(e: any): Record<string, unknown> {
  return {
    id: e.id,
    title: e.title,
    description: e.description,
    severity: e.severity,
    status: e.status,
    category: e.category,
    raised_by: e.raised_by_name,
    resolution_note: e.resolution_note || null,
    created_at: fmtDateTime(e.created_at),
    updated_at: fmtDateTime(e.updated_at)
  }
}

// ── Intent Handlers ──

const intentHandlers: Record<string, IntentHandler> = {

  // ─────────────────────────────────────────────────────────────────────────────
  // TASKS
  // ─────────────────────────────────────────────────────────────────────────────

  my_tasks: async (params) => {
    const res = await tasksAPI.list({ filter: 'all' })
    let tasks = res.data?.tasks || []

    // Apply status filter if specified
    if (params.status) {
      const statusMap: Record<string, string> = {
        'pending': 'pending', 'new': 'pending',
        'in_progress': 'in_progress', 'in progress': 'in_progress', 'ongoing': 'in_progress', 'working': 'in_progress',
        'completed': 'completed', 'done': 'completed', 'finished': 'completed',
        'on_hold': 'on_hold', 'on hold': 'on_hold', 'paused': 'on_hold', 'blocked': 'on_hold'
      }
      const mapped = statusMap[params.status.toLowerCase()] || params.status
      tasks = tasks.filter((t: any) => t.status === mapped)
    }

    // Apply priority filter if specified
    if (params.priority) {
      const priorityMap: Record<string, string> = {
        'urgent': 'urgent', 'critical': 'urgent',
        'high': 'high', 'important': 'high',
        'medium': 'medium', 'normal': 'medium',
        'low': 'low'
      }
      const mapped = priorityMap[params.priority.toLowerCase()] || params.priority
      tasks = tasks.filter((t: any) => t.priority === mapped)
    }

    // Statistics
    const allTasks = res.data?.tasks || []
    const stats = {
      total: allTasks.length,
      by_status: {} as Record<string, number>,
      by_priority: {} as Record<string, number>,
      overdue: allTasks.filter((t: any) => t.is_overdue).length,
      blocked: allTasks.filter((t: any) => t.blocker_status === 'blocked').length
    }
    for (const t of allTasks) {
      stats.by_status[t.status] = (stats.by_status[t.status] || 0) + 1
      if (t.priority) stats.by_priority[t.priority] = (stats.by_priority[t.priority] || 0) + 1
    }

    return {
      data: {
        filter_applied: { status: params.status || 'all', priority: params.priority || 'all' },
        result_count: tasks.length,
        statistics: stats,
        tasks: tasks.map(fullTask)
      },
      summaryHint: `${tasks.length} tasks`
    }
  },

  task_detail: async (params) => {
    const res = await tasksAPI.list({ filter: 'all' })
    const tasks = res.data?.tasks || []
    const keyword = (params.taskName || params.query || '').toLowerCase().trim()

    // Find best match
    let match = tasks.find((t: any) => t.title?.toLowerCase() === keyword)
    if (!match) match = tasks.find((t: any) => t.title?.toLowerCase().includes(keyword))
    if (!match && keyword.length > 3) {
      // Try partial word match
      const words = keyword.split(/\s+/)
      match = tasks.find((t: any) => words.every((w: string) => t.title?.toLowerCase().includes(w)))
    }

    if (!match) {
      return {
        data: {
          found: false,
          query: params.taskName || params.query,
          suggestion: 'No task found matching that name.',
          available_tasks: tasks.slice(0, 15).map((t: any) => ({ title: t.title, status: t.status, priority: t.priority }))
        },
        summaryHint: 'Task not found'
      }
    }

    // Fetch thread/comments
    let thread: any[] = []
    try {
      const threadRes = await tasksAPI.thread(match.id)
      const msgs = threadRes.data?.messages || threadRes.data || []
      if (Array.isArray(msgs)) {
        thread = msgs.map((m: any) => ({
          author: m.author?.name || m.user?.name || m.user_name || 'Unknown',
          message: m.body || m.text || m.content || '',
          timestamp: fmtDateTime(m.created_at)
        }))
      }
    } catch { /* no thread */ }

    return {
      data: {
        found: true,
        task: fullTask(match),
        conversation_thread: thread,
        thread_count: thread.length
      },
      summaryHint: `Task: ${match.title}`
    }
  },

  overdue_tasks: async () => {
    const res = await tasksAPI.list({ filter: 'all' })
    const tasks = (res.data?.tasks || []).filter((t: any) => t.is_overdue)

    return {
      data: {
        count: tasks.length,
        tasks: tasks.map(fullTask)
      },
      summaryHint: `${tasks.length} overdue tasks`
    }
  },

  blocked_tasks: async () => {
    const res = await tasksAPI.list({ filter: 'all' })
    const tasks = (res.data?.tasks || []).filter((t: any) => t.blocker_status === 'blocked')

    return {
      data: {
        count: tasks.length,
        tasks: tasks.map(fullTask)
      },
      summaryHint: `${tasks.length} blocked tasks`
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // MEETINGS
  // ─────────────────────────────────────────────────────────────────────────────

  meetings_today: async (_params, userRole) => {
    const res = await meetingsAPI.list({ portal: userRole || 'ops' })
    const items = res.data?.items || []
    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()

    const todayMeetings = items.filter((m: any) =>
      m.recurrence === 'daily_weekdays' || (m.dayOfWeek || '').toLowerCase() === todayName
    )

    return {
      data: {
        today: todayName,
        count: todayMeetings.length,
        meetings: todayMeetings.map(fullMeeting),
        all_meetings_count: items.length
      },
      summaryHint: `${todayMeetings.length} meetings today`
    }
  },

  meetings_list: async (_params, userRole) => {
    const res = await meetingsAPI.list({ portal: userRole || 'ops' })
    const items = res.data?.items || []

    // Group by day
    const byDay: Record<string, any[]> = {}
    for (const m of items) {
      const day = m.recurrence === 'daily_weekdays' ? 'Daily (Mon-Fri)' : (m.dayOfWeek || 'Unscheduled')
      if (!byDay[day]) byDay[day] = []
      byDay[day].push(fullMeeting(m))
    }

    return {
      data: {
        total: items.length,
        by_day: byDay,
        meetings: items.map(fullMeeting)
      },
      summaryHint: `${items.length} meetings`
    }
  },

  meeting_detail: async (params, userRole) => {
    const res = await meetingsAPI.list({ portal: userRole || 'ops' })
    const items = res.data?.items || []
    const keyword = (params.meetingName || params.query || '').toLowerCase().trim()

    // Find meeting
    let match = items.find((m: any) => (m.title || '').toLowerCase().includes(keyword))
    if (!match) match = items.find((m: any) => (m.meetingKey || '').toLowerCase().includes(keyword))
    if (!match) match = items.find((m: any) => (m.owner || '').toLowerCase().includes(keyword))

    if (!match) {
      return {
        data: {
          found: false,
          query: params.meetingName || params.query,
          available_meetings: items.map((m: any) => ({ title: m.title, day: m.dayOfWeek, time: m.time }))
        },
        summaryHint: 'Meeting not found'
      }
    }

    const week_key = resolveWeekKey(params.time)
    const baseKey = String(match.meetingKey || '')
    const recurrence = String(match.recurrence || 'none')
    const requestedDay = resolveDayName(params.day)

    // For daily meetings, try requested day first, then fall back
    const tryDays: string[] = []
    if (recurrence === 'daily_weekdays') {
      const start = requestedDay || new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
      const order = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
      const idx = Math.max(0, order.indexOf(start))
      tryDays.push(order[idx])
      for (let i = idx - 1; i >= 0; i--) tryDays.push(order[i])
      for (let i = idx + 1; i < order.length; i++) tryDays.push(order[i])
    } else {
      tryDays.push('')
    }

    let chosenDay = ''
    let sections: any[] = []
    let unsectioned: any[] = []
    let actionItems: any[] = []
    let carried: any[] = []
    let noteContent = ''

    for (const day of tryDays) {
      const meeting_id = buildMeetingId(baseKey, recurrence, day || undefined)
      const [agendaRes, actionsRes, notesRes] = await Promise.all([
        meetingsAPI.agendaSections({ meeting_id, week_key }).catch(() => ({ data: null })),
        meetingsAPI.actionItems({ meeting_id, week_key }).catch(() => ({ data: null })),
        meetingsAPI.notes({ meeting_id, week_key }).catch(() => ({ data: null }))
      ])

      const s = agendaRes.data?.sections || []
      const u = agendaRes.data?.unsectioned || []
      const ai = actionsRes.data?.items || []
      const cf = actionsRes.data?.carriedForward || []
      const note = (notesRes.data?.note || notesRes.data?.content || '').toString()

      const hasContent = s.some((sec: any) => (sec.points || []).some((p: any) => p.answer?.trim())) ||
        u.some((p: any) => p.answer?.trim()) || ai.length > 0 || note.trim()

      if (hasContent) {
        chosenDay = day
        sections = s
        unsectioned = u
        actionItems = ai
        carried = cf
        noteContent = note
        break
      }

      if (!chosenDay) {
        chosenDay = day
        sections = s
        unsectioned = u
        actionItems = ai
        carried = cf
        noteContent = note
      }
    }

    // Format agenda with FULL content
    const agenda = sections.map((s: any) => ({
      section_title: s.title,
      discussion_points: (s.points || []).map((p: any) => ({
        question: p.question,
        answer: p.answer || null
      }))
    }))

    const otherPoints = unsectioned.map((p: any) => ({
      question: p.question,
      answer: p.answer || null
    }))

    return {
      data: {
        found: true,
        meeting: fullMeeting(match),
        day_shown: chosenDay || match.dayOfWeek,
        week_key,
        minutes_of_meeting: noteContent || null,
        agenda_sections: agenda,
        unsectioned_points: otherPoints,
        action_items: actionItems.map(fullActionItem),
        carried_forward: carried.map(fullActionItem),
        has_content: !!(noteContent || actionItems.length || agenda.some((a: any) => a.discussion_points.some((p: any) => p.answer)))
      },
      summaryHint: `Meeting: ${match.title}`
    }
  },

  action_items: async (params, userRole) => {
    // Get action items across all meetings
    const meetingsRes = await meetingsAPI.list({ portal: userRole || 'ops' })
    const meetings = meetingsRes.data?.items || []
    const week_key = resolveWeekKey(params.time)

    const allActions: any[] = []
    const allCarried: any[] = []

    for (const m of meetings) {
      const baseKey = String(m.meetingKey || '')
      const recurrence = String(m.recurrence || 'none')

      if (recurrence === 'daily_weekdays') {
        // Check all weekdays
        for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) {
          const meeting_id = buildMeetingId(baseKey, recurrence, day)
          try {
            const res = await meetingsAPI.actionItems({ meeting_id, week_key })
            const items = (res.data?.items || []).map((a: any) => ({ ...a, meeting_title: m.title, meeting_day: day }))
            const carried = (res.data?.carriedForward || []).map((a: any) => ({ ...a, meeting_title: m.title, meeting_day: day }))
            allActions.push(...items)
            allCarried.push(...carried)
          } catch { /* skip */ }
        }
      } else {
        const meeting_id = baseKey
        try {
          const res = await meetingsAPI.actionItems({ meeting_id, week_key })
          const items = (res.data?.items || []).map((a: any) => ({ ...a, meeting_title: m.title }))
          const carried = (res.data?.carriedForward || []).map((a: any) => ({ ...a, meeting_title: m.title }))
          allActions.push(...items)
          allCarried.push(...carried)
        } catch { /* skip */ }
      }
    }

    // Filter by status if specified
    let filtered = allActions
    if (params.status) {
      const statusMap: Record<string, string> = {
        'pending': 'pending', 'open': 'pending',
        'in_progress': 'in_progress', 'in progress': 'in_progress',
        'done': 'done', 'completed': 'done',
        'blocked': 'blocked'
      }
      const mapped = statusMap[params.status.toLowerCase()] || params.status
      filtered = allActions.filter((a: any) => a.status === mapped)
    }

    const byStatus: Record<string, number> = {}
    for (const a of allActions) byStatus[a.status || 'unknown'] = (byStatus[a.status || 'unknown'] || 0) + 1

    return {
      data: {
        week_key,
        total: allActions.length,
        filtered_count: filtered.length,
        status_filter: params.status || 'all',
        by_status: byStatus,
        action_items: filtered.map(fullActionItem),
        carried_forward_count: allCarried.length,
        carried_forward: allCarried.map(fullActionItem)
      },
      summaryHint: `${filtered.length} action items`
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // DAILY REPORTS
  // ─────────────────────────────────────────────────────────────────────────────

  daily_report_status: async (params) => {
    try {
    const week_key = resolveWeekKey(params.time)
    console.log('Voice: Fetching daily reports for week:', week_key)
    const res = await dailyReportsAPI.list({ week_key })
    console.log('Voice: Daily reports response:', res.data)
    const d = res.data || {}

    const today = getTodayDate()
    const days = Array.isArray(d.days) ? d.days : []
    const fields = Array.isArray(d.fields) ? d.fields : []

    // Get today's entries
    const todayDay = days.find((day: any) => day.reportDate === today)
    const todayEntries = todayDay?.entries || {}

    // Build field status
    const fieldStatus = fields.map((f: any) => ({
      label: f.label,
      key: f.key,
      group: f.group,
      value: todayEntries[f.key] ?? null,
      filled: todayEntries[f.key] !== null && todayEntries[f.key] !== '' && todayEntries[f.key] !== undefined
    }))

    const filled = fieldStatus.filter((f: any) => f.filled)
    const missing = fieldStatus.filter((f: any) => !f.filled)

    // Week overview
    const weekOverview = days.map((day: any) => {
      const entries = day.entries || {}
      const dayFilled = Object.values(entries).filter((v) => v !== null && v !== '' && v !== undefined).length
      return {
        date: day.reportDate,
        day_name: new Date(day.reportDate + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long' }),
        fields_filled: dayFilled,
        total_fields: fields.length
      }
    })

    return {
      data: {
        week_key,
        today: today,
        today_filled: filled.length,
        today_total: fields.length,
        today_fields: fieldStatus,
        missing_fields: missing.map((f: any) => f.label),
        filled_fields: filled.map((f: any) => ({ label: f.label, value: f.value })),
        week_overview: weekOverview,
        week_summary: d.summary || null
      },
      summaryHint: `${filled.length}/${fields.length} fields filled today`
    }
    } catch (e: any) {
      console.error('Voice: Daily reports error:', e?.message)
      const status = e?.response?.status
      if (status === 403) {
        return { data: { error: true, permission_denied: true }, summaryHint: 'No access to daily reports' }
      }
      return { data: { error: true }, summaryHint: 'Daily reports data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // KPIs
  // ─────────────────────────────────────────────────────────────────────────────

  kpi_summary: async (params) => {
    try {
      const week_key = resolveWeekKey(params.time)
      console.log('Voice: Fetching KPIs for week:', week_key)

      // Get KPI definitions from portal config (like the Marketing KPIs page does)
      const portalConfig = (window as any).__PORTAL_CONFIG || {}
      const kpiDefs = portalConfig.kpiDefinitions || {}
      const marketingPeople = kpiDefs.marketingKpiPeople || []

      // Get the first person's fields as the KPI fields (or current user)
      const currentUserId = portalConfig.user?.id
      const activePerson = marketingPeople.find((p: any) => p.id === currentUserId) || marketingPeople[0]
      const fields = activePerson?.fields || []

      console.log('Voice: KPI fields from portal config:', fields.length)

      // Fetch data from daily reports (summary) and kpi entries (targets)
      const [reportsRes, kpiRes] = await Promise.all([
        dailyReportsAPI.list({ week_key }).catch(() => ({ data: {} })),
        kpiAPI.entries({ week_key }).catch(() => ({ data: {} }))
      ])

      console.log('Voice: Daily reports response keys:', Object.keys(reportsRes.data || {}))
      console.log('Voice: KPI entries response:', JSON.stringify(kpiRes.data || {}).substring(0, 300))

      // Try multiple paths to find the summary data
      const summary = reportsRes.data?.summary || reportsRes.data?.data?.summary || reportsRes.data || {}
      const targets = kpiRes.data?.data?.targets || {}
      const ceoNote = kpiRes.data?.data?.ceoNote || null

      console.log('Voice: KPI summary data:', Object.keys(summary).length, 'fields')
      console.log('Voice: KPI summary keys:', Object.keys(summary))
      console.log('Voice: KPI summary values:', JSON.stringify(summary).substring(0, 500))
      console.log('Voice: KPI targets:', Object.keys(targets).length)

      // Build KPI list from fields
      const kpis: any[] = []
      for (const f of fields) {
        const value = summary[f.key]
        const target = targets[f.key]
        if (value !== undefined && value !== null && value !== '') {
          kpis.push({
            name: f.label,
            group: f.group || 'General',
            value,
            target: target ?? null,
            on_track: target != null && !isNaN(parseFloat(value)) && !isNaN(parseFloat(target))
              ? parseFloat(String(value).replace(/,/g, '')) >= parseFloat(String(target).replace(/,/g, ''))
              : null
          })
        }
      }

      // If no fields from portal config, try using raw summary keys
      if (kpis.length === 0 && Object.keys(summary).length > 0) {
        console.log('Voice: Using fallback - building KPIs from raw summary')
        for (const [key, value] of Object.entries(summary)) {
          // Accept any value that isn't undefined/null, including 0 and empty objects
          if (value !== undefined && value !== null) {
            // If value is an object, try to extract a meaningful value
            let displayValue = value
            if (typeof value === 'object' && value !== null) {
              // Try common patterns: { value: x }, { amount: x }, { total: x }
              displayValue = (value as any).value ?? (value as any).amount ?? (value as any).total ?? JSON.stringify(value)
            }
            kpis.push({
              name: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
              group: 'General',
              value: displayValue,
              target: targets[key] ?? null
            })
          }
        }
        console.log('Voice: Fallback built', kpis.length, 'KPIs')
      }

      // Group KPIs
      const byGroup: Record<string, any[]> = {}
      for (const kpi of kpis) {
        if (!byGroup[kpi.group]) byGroup[kpi.group] = []
        byGroup[kpi.group].push(kpi)
      }

      return {
        data: {
          week_key,
          total_kpis: kpis.length,
          kpis: kpis.slice(0, 20), // Limit to 20 for voice
          by_group: byGroup,
          ceo_note: ceoNote
        },
        summaryHint: `${kpis.length} KPIs`
      }
    } catch (e: any) {
      console.error('Voice: KPI error:', e?.message)
      return { data: { error: true }, summaryHint: 'KPI data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // SIGN IN / SIGN OFF
  // ─────────────────────────────────────────────────────────────────────────────

  sign_in: async () => {
    try {
      const res = await signoffAPI.signIn()
      return {
        data: {
          success: true,
          signed_in_at: new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
          message: 'You are now signed in for today.',
          result: res.data
        },
        summaryHint: 'Signed in'
      }
    } catch (e: any) {
      return {
        data: {
          success: false,
          error: e?.message || 'Failed to sign in'
        },
        summaryHint: 'Sign-in failed'
      }
    }
  },

  sign_off_status: async () => {
    const res = await signoffAPI.status()
    const d = res.data || {}
    const items = Array.isArray(d.items) ? d.items : []

    const pending = items.filter((i: any) => i.status === 'pending')
    const complete = items.filter((i: any) => i.status === 'complete')

    return {
      data: {
        signed_off: d.signedOff,
        signed_off_at: d.signedOffAt,
        can_sign_off: d.canSignOff,
        day_name: d.dayName,
        pending_count: pending.length,
        complete_count: complete.length,
        all_items: items.map((i: any) => ({
          type: i.type,
          label: i.label,
          status: i.status,
          detail: i.detail,
          meeting_key: i.meetingKey
        })),
        pending_items: pending.map((i: any) => ({
          type: i.type,
          label: i.label,
          detail: i.detail
        }))
      },
      summaryHint: d.signedOff ? 'Signed off' : `${pending.length} items pending sign-off`
    }
  },

  sign_off_action: async (params) => {
    console.log('Voice: sign_off_action called with params:', params)

    // Check if user is forcing/insisting
    const forceSignOff = params.force === 'true' || String(params.force) === 'true'
    console.log('Voice: Force sign-off:', forceSignOff)

    // First check current status
    const statusRes = await signoffAPI.status()
    const statusData = statusRes.data || {}
    console.log('Voice: Sign-off status:', { signedOff: statusData.signedOff, canSignOff: statusData.canSignOff })
    const items = Array.isArray(statusData.items) ? statusData.items : []
    const pending = items.filter((i: any) => i.status === 'pending')
    console.log('Voice: Pending items:', pending.length)

    // Already signed off?
    if (statusData.signedOff) {
      return {
        data: {
          success: true,
          already_signed_off: true,
          signed_off_at: statusData.signedOffAt,
          message: 'You are already signed off for today.'
        },
        summaryHint: 'Already signed off'
      }
    }

    // If user is NOT forcing and there are pending items, warn them
    // Note: Tessa backend may not allow sign-off with pending items regardless
    if (pending.length > 0 && !forceSignOff) {
      return {
        data: {
          success: false,
          needs_confirmation: true,
          pending_count: pending.length,
          pending_items: pending.map((i: any) => ({ type: i.type, label: i.label, detail: i.detail })),
          message: `You have ${pending.length} pending item(s): ${pending.map((i: any) => i.label).join(', ')}. Note: Tessa usually requires these to be completed before sign-off. I can try anyway if you say "sign me off anyway", but the system may still block it.`
        },
        summaryHint: `${pending.length} items pending - awaiting confirmation`
      }
    }

    // User is forcing OR no pending items — attempt the sign-off
    console.log('Voice: Attempting sign-off submit...', forceSignOff ? '(force=true)' : '')
    try {
      await signoffAPI.submit({ force: forceSignOff })
      console.log('Voice: Sign-off successful!')
      return {
        data: {
          success: true,
          signed_off: true,
          signed_off_at: new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
          forced: forceSignOff && pending.length > 0,
          pending_skipped: pending.length,
          message: pending.length > 0
            ? `Done! I've signed you off. The ${pending.length} pending item(s) will carry forward.`
            : 'Done! You are now signed off for today.'
        },
        summaryHint: 'Signed off successfully'
      }
    } catch (e: any) {
      console.error('Voice: Sign-off API error:', e?.response?.data || e?.message)
      const apiMessage = e?.response?.data?.message || ''

      // If API says can't sign off, explain why clearly
      if (apiMessage.includes('pending') || apiMessage.includes('complete') || !statusData.canSignOff) {
        const pendingLabels = pending.map((i: any) => i.label).join(', ')
        return {
          data: {
            success: false,
            system_blocked: true,
            not_assistant_choice: true,
            message: `I tried, but the Tessa system itself won't allow sign-off until pending items are done. This isn't my rule — it's built into Tessa. You need to complete: ${pendingLabels}. Once those are filled in, I can sign you off.`
          },
          summaryHint: 'System requires pending items'
        }
      }

      return {
        data: {
          success: false,
          error: true,
          message: apiMessage || 'Sign-off failed. Please try using the Tessa portal directly.'
        },
        summaryHint: 'Sign-off failed'
      }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // PENDING WORK (comprehensive)
  // ─────────────────────────────────────────────────────────────────────────────

  pending_work: async () => {
    const res = await pendingWorkAPI.list()
    const d = res.data || {}

    const actionItems = Array.isArray(d.actionItems) ? d.actionItems : []
    const carried = Array.isArray(d.carriedForward) ? d.carriedForward : []
    const agendaPending = Array.isArray(d.agenda) ? d.agenda : []
    const notesPending = Array.isArray(d.notes) ? d.notes : []
    const dailyReportPending = Array.isArray(d.dailyReport) ? d.dailyReport : []

    return {
      data: {
        date: d.date,
        signed_in: d.signedIn,
        signed_in_at: d.signedInAt,
        signed_off: d.signedOff,
        signed_off_at: d.signedOffAt,
        action_items: {
          count: actionItems.length,
          items: actionItems.map(fullActionItem)
        },
        carried_forward: {
          count: carried.length,
          items: carried.map(fullActionItem)
        },
        agenda_pending: {
          count: agendaPending.length,
          items: agendaPending.map((a: any) => ({
            meeting: a.meeting_title,
            question: a.question,
            meeting_time: a.meeting_time
          }))
        },
        notes_pending: {
          count: notesPending.length,
          meetings: notesPending.map((n: any) => n.meeting_title)
        },
        daily_report_pending: {
          count: dailyReportPending.length,
          fields: dailyReportPending.map((f: any) => f.field_label)
        }
      },
      summaryHint: `${actionItems.length} action items, ${dailyReportPending.length} report fields pending`
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // ESCALATIONS
  // ─────────────────────────────────────────────────────────────────────────────

  escalations: async (params) => {
    const statusFilter = params.status || 'open,in_progress'
    const res = await escalationsAPI.list({ status: statusFilter })
    const items = res.data?.items || res.data?.data || res.data || []
    const list = Array.isArray(items) ? items : []

    const bySeverity: Record<string, any[]> = {}
    const byStatus: Record<string, number> = {}
    for (const e of list) {
      const sev = e.severity || 'unknown'
      if (!bySeverity[sev]) bySeverity[sev] = []
      bySeverity[sev].push(fullEscalation(e))
      byStatus[e.status || 'unknown'] = (byStatus[e.status || 'unknown'] || 0) + 1
    }

    return {
      data: {
        status_filter: statusFilter,
        total: list.length,
        by_severity: bySeverity,
        by_status: byStatus,
        escalations: list.map(fullEscalation)
      },
      summaryHint: `${list.length} escalations`
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // TICKETS
  // ─────────────────────────────────────────────────────────────────────────────

  tickets: async (params) => {
    try {
      const queryParams: Record<string, string> = {}
      if (params.status) queryParams.status = params.status
      if (params.priority) queryParams.priority = params.priority

      const res = await ticketsAPI.list(queryParams)
      const tickets = res.data?.tickets || res.data || []
      const list = Array.isArray(tickets) ? tickets : []

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const t of list) {
        const status = t.status || 'open'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      // Group by priority
      const byPriority: Record<string, number> = {}
      for (const t of list) {
        const priority = t.priority || 'medium'
        byPriority[priority] = (byPriority[priority] || 0) + 1
      }

      // Group by assignee
      const byAssignee: Record<string, number> = {}
      for (const t of list) {
        const assignee = t.assignedToName || t.assigned_to_name || t.assignee || 'Unassigned'
        byAssignee[assignee] = (byAssignee[assignee] || 0) + 1
      }

      return {
        data: {
          status_filter: params.status || 'all',
          priority_filter: params.priority || 'all',
          total_count: list.length,
          by_status: byStatus,
          by_priority: byPriority,
          by_assignee: byAssignee,
          tickets: list.slice(0, 15).map((t: any) => ({
            title: t.title || t.name,
            description: t.description,
            status: t.status || 'open',
            priority: t.priority || 'medium',
            type: t.type || t.category,
            assigned_to: t.assignedToName || t.assigned_to_name || t.assignee,
            reported_by: t.reportedByName || t.reported_by_name || t.reporter || t.createdByName,
            created_at: t.created_at || t.createdAt,
            project: t.project || t.projectName
          }))
        },
        summaryHint: `${list.length} tickets`
      }
    } catch (e: any) {
      console.error('Voice: Tickets error:', e?.message)
      return { data: { error: true }, summaryHint: 'Tickets data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // SPRINT
  // ─────────────────────────────────────────────────────────────────────────────

  sprint_status: async (params) => {
    // Fetch all sprints
    const sprintsRes = await sprintsAPI.list()
    const allSprints = sprintsRes.data?.sprints || []

    console.log('Voice: All sprints:', allSprints.map((s: any) => ({ name: s.name, project: s.projectName, status: s.status })))

    // Get unique projects
    const projectSet = new Set<string>()
    for (const s of allSprints) {
      if (s.projectName) projectSet.add(s.projectName)
    }
    const projects = Array.from(projectSet)

    // Filter by project if specified
    const projectQuery = (params.project || '').toLowerCase().trim()
    let filteredSprints = allSprints

    if (projectQuery) {
      filteredSprints = allSprints.filter((s: any) => {
        const projectName = (s.projectName || '').toLowerCase()
        const sprintName = (s.name || '').toLowerCase()
        // Match project name OR sprint name containing the query
        return projectName.includes(projectQuery) ||
               projectQuery.includes(projectName) ||
               sprintName.includes(projectQuery)
      })
      console.log('Voice: Filtered sprints for project query "' + projectQuery + '":', filteredSprints.length)
    }

    // Find ALL active sprints (not just first)
    const activeSprints = filteredSprints.filter((s: any) => s.status === 'active')

    if (activeSprints.length === 0) {
      // No active sprints - show what's available
      const otherSprints = filteredSprints.filter((s: any) => s.status !== 'active')
      return {
        data: {
          has_active_sprint: false,
          project_filter: params.project || null,
          available_projects: projects,
          message: projectQuery
            ? `No active sprint found for "${params.project}".`
            : 'No active sprints found.',
          other_sprints: otherSprints.slice(0, 10).map((s: any) => ({
            name: s.name || s.title,
            project: s.projectName,
            status: s.status,
            start_date: fmtDate(s.startDate || s.start_date),
            end_date: fmtDate(s.endDate || s.end_date)
          }))
        },
        summaryHint: projectQuery ? `No active sprint for ${params.project}` : 'No active sprints'
      }
    }

    // If asking about a specific project OR only one active sprint, show detailed board
    const showDetailedBoard = projectQuery || activeSprints.length === 1
    const primarySprint = activeSprints[0]

    let boardData: Record<string, any[]> = {}
    let columnCounts: Record<string, number> = {}
    let totalItems = 0

    if (showDetailedBoard && primarySprint) {
      const boardRes = await sprintsAPI.board(primarySprint.id)
      const boardColumns = boardRes.data?.columns || boardRes.data || {}

      for (const [col, items] of Object.entries(boardColumns)) {
        if (Array.isArray(items)) {
          boardData[col] = items.map((item: any) => ({
            id: item.id,
            title: item.title,
            type: item.type || 'story',
            points: item.points,
            priority: item.priority,
            assignee: item.assignee?.name || item.assignee,
            status: item.status
          }))
          columnCounts[col] = items.length
          totalItems += items.length
        }
      }
    }

    // Build sprint summaries
    const sprintSummaries = activeSprints.map((s: any) => ({
      id: s.id,
      name: s.name || s.title,
      project: s.projectName || 'No project',
      goal: s.goal,
      start_date: fmtDate(s.startDate || s.start_date),
      end_date: fmtDate(s.endDate || s.end_date),
      days_remaining: s.daysRemaining,
      total_points: s.totalPoints,
      completed_points: s.completedPoints,
      velocity: s.velocity
    }))

    return {
      data: {
        has_active_sprint: true,
        project_filter: params.project || null,
        available_projects: projects,
        active_sprint_count: activeSprints.length,
        active_sprints: sprintSummaries,
        // Detailed board for primary sprint (when specific project requested or only one sprint)
        primary_sprint: showDetailedBoard ? {
          ...sprintSummaries[0],
          board_columns: boardData,
          column_counts: columnCounts,
          total_items: totalItems
        } : null
      },
      summaryHint: activeSprints.length === 1
        ? `Sprint: ${primarySprint.name}${primarySprint.projectName ? ` (${primarySprint.projectName})` : ''}`
        : `${activeSprints.length} active sprints`
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // REVENUE & FINANCE
  // ─────────────────────────────────────────────────────────────────────────────

  revenue: async (params) => {
    try {
      // Fetch revenue data - default from start of current month
      const today = new Date()
      const monthStart = new Date(today.getFullYear(), today.getMonth(), 1).toLocaleDateString('en-CA')
      const res = await revenueAPI.dailyPayout({ from: monthStart })
      const rows = res.data?.rows || []

      // Parse date filter if specified
      let targetDate: string | null = null
      if (params.date) {
        const dateStr = params.date.toLowerCase().trim()
        if (dateStr === 'today') {
          targetDate = today.toLocaleDateString('en-CA')
        } else if (dateStr === 'yesterday') {
          const d = new Date(today)
          d.setDate(d.getDate() - 1)
          targetDate = d.toLocaleDateString('en-CA')
        } else if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
          targetDate = dateStr
        } else {
          // Try to parse natural date like "13th April", "April 13", "13 April"
          // Remove ordinal suffixes (st, nd, rd, th)
          const cleaned = dateStr.replace(/(\d+)(st|nd|rd|th)/gi, '$1')
          // Try different formats
          let parsed = new Date(cleaned + ' ' + today.getFullYear())
          if (isNaN(parsed.getTime())) {
            // Try with current year prefix
            parsed = new Date(today.getFullYear() + ' ' + cleaned)
          }
          if (!isNaN(parsed.getTime())) {
            targetDate = parsed.toLocaleDateString('en-CA')
          }
        }
        console.log('Voice: Revenue date parsing:', params.date, '→', targetDate)
      }

      // Filter to specific date if requested
      let filteredRows = rows
      if (targetDate) {
        filteredRows = rows.filter((r: any) => r.date === targetDate)
      }

      // Calculate totals
      const totals = {
        gross_revenue: 0,
        google_spend: 0,
        meta_spend: 0,
        payout_paid: 0,
        agora_cost: 0
      }
      for (const r of filteredRows) {
        totals.gross_revenue += r.revenue || 0
        totals.google_spend += r.google_spend || 0
        totals.meta_spend += r.meta_spend || 0
        totals.payout_paid += r.payout_paid || 0
        totals.agora_cost += r.agora_cost_inr || 0
      }

      // Send raw numbers — Claude will format as spoken Indian currency (lakhs, crores)
      return {
        data: {
          note: 'All amounts are in Indian Rupees. Format as spoken words using Indian system (lakhs, crores).',
          date_filter: targetDate || 'this month',
          row_count: filteredRows.length,
          totals: {
            gross_revenue_rupees: Math.round(totals.gross_revenue),
            google_spend_rupees: Math.round(totals.google_spend),
            meta_spend_rupees: Math.round(totals.meta_spend),
            total_ad_spend_rupees: Math.round(totals.google_spend + totals.meta_spend),
            payout_paid_rupees: Math.round(totals.payout_paid),
            agora_cost_rupees: Math.round(totals.agora_cost),
            net_revenue_rupees: Math.round(totals.gross_revenue - totals.google_spend - totals.meta_spend - totals.payout_paid - totals.agora_cost)
          },
          daily_breakdown: filteredRows.slice(0, 10).map((r: any) => ({
            date: fmtDate(r.date),
            revenue_rupees: Math.round(r.revenue || 0),
            google_spend_rupees: Math.round(r.google_spend || 0),
            meta_spend_rupees: Math.round(r.meta_spend || 0)
          }))
        },
        summaryHint: targetDate ? `Revenue for ${fmtDate(targetDate)}` : `Revenue this month`
      }
    } catch (e: any) {
      console.error('Voice: Revenue error:', e?.message)
      return { data: { error: true }, summaryHint: 'Revenue data unavailable' }
    }
  },

  meta_ads: async (params) => {
    try {
      const res = await metaAdsAPI.list()
      let rows = res.data?.reports || res.data?.rows || res.data || []
      if (!Array.isArray(rows)) rows = []
      console.log('Voice: Meta ads fetched', rows.length, 'records')

      // Filter by project if specified
      if (params.project) {
        const proj = params.project.toLowerCase()
        rows = rows.filter((r: any) => (r.project || '').toLowerCase().includes(proj))
      }

      // Group by project
      const byProject: Record<string, { spend: number; impressions: number; reach: number; clicks: number; count: number }> = {}
      for (const r of rows) {
        const proj = r.project || 'Unknown'
        if (!byProject[proj]) byProject[proj] = { spend: 0, impressions: 0, reach: 0, clicks: 0, count: 0 }
        byProject[proj].spend += r.amount_spent || r.spend || 0
        byProject[proj].impressions += r.impressions || 0
        byProject[proj].reach += r.reach || 0
        byProject[proj].clicks += r.clicks || r.link_clicks || 0
        byProject[proj].count += 1
      }

      // Send raw numbers — Claude will format as spoken Indian currency (lakhs, crores)
      return {
        data: {
          note: 'All spend amounts are in Indian Rupees. Format as spoken words using Indian system (lakhs, crores).',
          project_filter: params.project || null,
          total_rows: rows.length,
          by_project: Object.entries(byProject).map(([project, stats]) => ({
            project,
            total_spend_rupees: Math.round(stats.spend),
            impressions: stats.impressions,
            reach: stats.reach,
            clicks: stats.clicks,
            ad_count: stats.count
          })),
          recent_ads: rows.slice(0, 8).map((r: any) => ({
            project: r.project,
            campaign: r.campaign_name,
            ad_set: r.ad_set_name,
            spend_rupees: Math.round(r.amount_spent || r.spend || 0),
            impressions: r.impressions,
            reach: r.reach
          }))
        },
        summaryHint: `${rows.length} Meta ads`
      }
    } catch (e: any) {
      console.error('Voice: Meta ads error:', e?.response?.status, e?.message)
      if (e?.response?.status === 403) {
        return {
          data: {
            permission_denied: true,
            message: 'You do not have access to Meta Ads data. This is restricted to CEO, CFO, CMO, COO, Tech Lead, Marketing, and Growth Manager roles.'
          },
          summaryHint: 'No access to Meta Ads'
        }
      }
      return { data: { error: true, message: 'Failed to fetch Meta ads data.' }, summaryHint: 'Meta ads data unavailable' }
    }
  },

  google_ads: async (params) => {
    try {
      const res = await googleAdsAPI.list()
      let rows = res.data?.reports || res.data?.rows || res.data || []
      if (!Array.isArray(rows)) rows = []
      console.log('Voice: Google ads fetched', rows.length, 'records')

      // Filter by project if specified
      if (params.project) {
        const proj = params.project.toLowerCase()
        rows = rows.filter((r: any) => (r.project || '').toLowerCase().includes(proj))
      }

      // Group by project
      const byProject: Record<string, { cost: number; clicks: number; impressions: number; conversions: number; count: number }> = {}
      for (const r of rows) {
        const proj = r.project || 'Unknown'
        if (!byProject[proj]) byProject[proj] = { cost: 0, clicks: 0, impressions: 0, conversions: 0, count: 0 }
        byProject[proj].cost += r.cost || 0
        byProject[proj].clicks += r.clicks || 0
        byProject[proj].impressions += r.impressions || 0
        byProject[proj].conversions += r.conversions || 0
        byProject[proj].count += 1
      }

      // Send raw numbers — Claude will format as spoken Indian currency (lakhs, crores)
      return {
        data: {
          note: 'All cost amounts are in Indian Rupees. Format as spoken words using Indian system (lakhs, crores).',
          project_filter: params.project || null,
          total_rows: rows.length,
          by_project: Object.entries(byProject).map(([project, stats]) => ({
            project,
            total_cost_rupees: Math.round(stats.cost),
            clicks: stats.clicks,
            impressions: stats.impressions,
            conversions: stats.conversions,
            campaign_count: stats.count
          })),
          recent_campaigns: rows.slice(0, 8).map((r: any) => ({
            project: r.project,
            campaign: r.campaign_name,
            cost_rupees: Math.round(r.cost || 0),
            clicks: r.clicks,
            ctr: r.ctr ? `${(r.ctr * 100).toFixed(2)}%` : null
          }))
        },
        summaryHint: `${rows.length} Google ads`
      }
    } catch (e: any) {
      console.error('Voice: Google ads error:', e?.response?.status, e?.message)
      if (e?.response?.status === 403) {
        return {
          data: {
            permission_denied: true,
            message: 'You do not have access to Google Ads data. This is restricted to CEO, CFO, CMO, COO, Tech Lead, Marketing, and Growth Manager roles.'
          },
          summaryHint: 'No access to Google Ads'
        }
      }
      return { data: { error: true, message: 'Failed to fetch Google ads data.' }, summaryHint: 'Google ads data unavailable' }
    }
  },

  invoices: async (params) => {
    try {
      const queryParams: Record<string, string> = {}
      if (params.status) queryParams.status = params.status
      // Add date range if specified
      if (params.from) queryParams.from = params.from
      if (params.to) queryParams.to = params.to

      const res = await invoicesAPI.list(queryParams)
      // API returns { submissions: [...] }
      let invoices = res.data?.submissions || res.data?.invoices || res.data?.items || []
      if (!Array.isArray(invoices)) invoices = []

      console.log('Voice: Invoices fetched:', invoices.length)

      // Filter by date if "yesterday" or specific date requested
      if (params.date) {
        const today = new Date()
        let targetDate: string | null = null
        const dateStr = params.date.toLowerCase()

        if (dateStr === 'yesterday') {
          const d = new Date(today)
          d.setDate(d.getDate() - 1)
          targetDate = d.toLocaleDateString('en-CA')
        } else if (dateStr === 'today') {
          targetDate = today.toLocaleDateString('en-CA')
        } else if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
          targetDate = dateStr
        }

        if (targetDate) {
          invoices = invoices.filter((inv: any) => {
            const invDate = (inv.invoiceDate || inv.invoice_date || inv.created_at || '').substring(0, 10)
            return invDate === targetDate
          })
        }
      }

      // Group by status
      const byStatus: Record<string, number> = {}
      let totalAmount = 0
      for (const inv of invoices) {
        const status = inv.status || 'pending'
        byStatus[status] = (byStatus[status] || 0) + 1
        totalAmount += parseFloat(inv.amount) || 0
      }

      // Group by uploader
      const byUploader: Record<string, number> = {}
      for (const inv of invoices) {
        const uploader = inv.uploadedByName || inv.uploaded_by_name || 'Unknown'
        byUploader[uploader] = (byUploader[uploader] || 0) + 1
      }

      // Send raw numbers — Claude will format as spoken Indian currency (lakhs, crores)
      return {
        data: {
          note: 'All amounts are in Indian Rupees. Format as spoken words using Indian system (lakhs, crores).',
          date_filter: params.date || null,
          status_filter: params.status || 'all',
          total_count: invoices.length,
          total_amount_rupees: Math.round(totalAmount),
          by_status: byStatus,
          by_uploader: byUploader,
          invoices: invoices.slice(0, 12).map((inv: any) => ({
            vendor: inv.vendorName || inv.vendor_name || inv.fileName || 'Unknown',
            amount_rupees: Math.round(parseFloat(inv.amount) || 0),
            date: fmtDate(inv.invoiceDate || inv.invoice_date || inv.created_at),
            invoice_number: inv.invoiceNumber || inv.invoice_number,
            category: inv.category,
            status: inv.status || 'pending',
            uploaded_by: inv.uploadedByName || inv.uploaded_by_name,
            notes: inv.notes
          }))
        },
        summaryHint: `${invoices.length} invoices`
      }
    } catch (e: any) {
      console.error('Voice: Invoices error:', e?.message)
      return { data: { error: true }, summaryHint: 'Invoices data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // DASHBOARD
  // ─────────────────────────────────────────────────────────────────────────────

  dashboard: async () => {
    try {
      const res = await dashboardAPI.status()
      const data = res.data || {}

      // Extract user sign-in status
      const users = data.users || []
      const signedIn = users.filter((u: any) => u.tessaSignIn?.signedIn === true)
      const notSignedIn = users.filter((u: any) => u.tessaSignIn?.signedIn !== true)

      return {
        data: {
          total_users: users.length,
          signed_in_count: signedIn.length,
          not_signed_in_count: notSignedIn.length,
          signed_in_users: signedIn.slice(0, 10).map((u: any) => ({
            name: u.name,
            signed_in_at: u.tessaSignIn?.signedInAt
          })),
          not_signed_in_users: notSignedIn.slice(0, 10).map((u: any) => u.name),
          raw_data: data
        },
        summaryHint: `${signedIn.length}/${users.length} users signed in`
      }
    } catch (e: any) {
      console.error('Voice: Dashboard error:', e?.message)
      return { data: { error: true }, summaryHint: 'Dashboard data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // EMPLOYEES / ORG
  // ─────────────────────────────────────────────────────────────────────────────

  employees: async (params) => {
    try {
      const res = await employeesAPI.list()
      let employees = res.data?.employees || res.data || []
      if (!Array.isArray(employees)) employees = []

      // Filter by department/team if specified
      if (params.department) {
        const dept = params.department.toLowerCase()
        employees = employees.filter((e: any) =>
          (e.department || '').toLowerCase().includes(dept) ||
          (e.team || '').toLowerCase().includes(dept)
        )
      }

      // Group by department
      const byDepartment: Record<string, number> = {}
      for (const e of employees) {
        const dept = e.department || e.team || 'Unknown'
        byDepartment[dept] = (byDepartment[dept] || 0) + 1
      }

      return {
        data: {
          total_count: employees.length,
          by_department: byDepartment,
          employees: employees.slice(0, 20).map((e: any) => ({
            name: e.name || `${e.firstName || ''} ${e.lastName || ''}`.trim(),
            email: e.email,
            department: e.department || e.team,
            role: e.role || e.designation || e.position,
            phone: e.phone
          }))
        },
        summaryHint: `${employees.length} employees`
      }
    } catch (e: any) {
      console.error('Voice: Employees error:', e?.message)
      return { data: { error: true }, summaryHint: 'Employee data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // LEAVE
  // ─────────────────────────────────────────────────────────────────────────────

  leave_status: async (params) => {
    try {
      const res = await leaveAPI.myLeaves()
      let leaves = res.data?.leaves || res.data || []
      if (!Array.isArray(leaves)) leaves = []

      // Filter by status if specified
      if (params.status) {
        const status = params.status.toLowerCase()
        leaves = leaves.filter((l: any) => (l.status || '').toLowerCase() === status)
      }

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const l of leaves) {
        const status = l.status || 'pending'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      // Get balance
      let balance = null
      try {
        const balRes = await leaveAPI.balance()
        balance = balRes.data
      } catch { /* ignore */ }

      return {
        data: {
          total_requests: leaves.length,
          by_status: byStatus,
          balance: balance,
          leaves: leaves.slice(0, 10).map((l: any) => ({
            type: l.type || l.leaveType || l.leave_type,
            from_date: l.fromDate || l.from_date || l.startDate,
            to_date: l.toDate || l.to_date || l.endDate,
            days: l.days || l.totalDays,
            status: l.status || 'pending',
            reason: l.reason
          }))
        },
        summaryHint: `${leaves.length} leave requests`
      }
    } catch (e: any) {
      console.error('Voice: Leave error:', e?.message)
      return { data: { error: true }, summaryHint: 'Leave data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // RELEASES
  // ─────────────────────────────────────────────────────────────────────────────

  releases: async (params) => {
    try {
      const res = await releasesAPI.list()
      let releases = res.data?.releases || res.data || []
      if (!Array.isArray(releases)) releases = []

      // Filter by status if specified
      if (params.status) {
        const status = params.status.toLowerCase()
        releases = releases.filter((r: any) => (r.status || '').toLowerCase() === status)
      }

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const r of releases) {
        const status = r.status || 'planned'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      return {
        data: {
          total_count: releases.length,
          by_status: byStatus,
          releases: releases.slice(0, 10).map((r: any) => ({
            name: r.name || r.title,
            version: r.version,
            status: r.status || 'planned',
            release_date: r.releaseDate || r.release_date || r.targetDate,
            project: r.project || r.projectName,
            description: r.description
          }))
        },
        summaryHint: `${releases.length} releases`
      }
    } catch (e: any) {
      console.error('Voice: Releases error:', e?.message)
      return { data: { error: true }, summaryHint: 'Releases data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // SCRIPTS
  // ─────────────────────────────────────────────────────────────────────────────

  scripts: async (params) => {
    try {
      const res = await scriptsAPI.list({ scope: 'library' })
      let scripts = res.data?.data || res.data?.scripts || res.data || []
      if (!Array.isArray(scripts)) scripts = []

      // Filter by category if specified
      if (params.category) {
        const cat = params.category.toLowerCase()
        scripts = scripts.filter((s: any) => (s.category || '').toLowerCase().includes(cat))
      }

      // Group by category
      const byCategory: Record<string, number> = {}
      for (const s of scripts) {
        const cat = s.category || 'Uncategorized'
        byCategory[cat] = (byCategory[cat] || 0) + 1
      }

      return {
        data: {
          total_count: scripts.length,
          by_category: byCategory,
          scripts: scripts.slice(0, 15).map((s: any) => ({
            title: s.title || s.name,
            category: s.category,
            description: s.description,
            content: s.content?.substring(0, 200) // Preview only
          }))
        },
        summaryHint: `${scripts.length} scripts`
      }
    } catch (e: any) {
      console.error('Voice: Scripts error:', e?.message)
      const status = e?.response?.status
      if (status === 403) {
        return { data: { error: true, permission_denied: true }, summaryHint: 'No access to scripts' }
      }
      return { data: { error: true }, summaryHint: 'Scripts data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // AGILE - STORIES
  // ─────────────────────────────────────────────────────────────────────────────

  stories: async (params) => {
    try {
      const queryParams: Record<string, string> = {}
      if (params.status) queryParams.status = params.status
      if (params.sprint) queryParams.sprint = params.sprint

      const res = await storiesAPI.list(queryParams)
      let stories = res.data?.stories || res.data || []
      if (!Array.isArray(stories)) stories = []

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const s of stories) {
        const status = s.status || 'backlog'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      // Calculate total points
      let totalPoints = 0
      let completedPoints = 0
      for (const s of stories) {
        const points = s.points || s.storyPoints || 0
        totalPoints += points
        if (s.status === 'done' || s.status === 'completed') {
          completedPoints += points
        }
      }

      return {
        data: {
          total_count: stories.length,
          total_story_points: totalPoints,
          completed_points: completedPoints,
          by_status: byStatus,
          stories: stories.slice(0, 15).map((s: any) => ({
            title: s.title || s.name,
            status: s.status || 'backlog',
            points: s.points || s.storyPoints,
            priority: s.priority,
            sprint: s.sprintName || s.sprint,
            assignee: s.assigneeName || s.assignee,
            epic: s.epicName || s.epic
          }))
        },
        summaryHint: `${stories.length} stories (${totalPoints} points)`
      }
    } catch (e: any) {
      console.error('Voice: Stories error:', e?.message)
      return { data: { error: true }, summaryHint: 'Stories data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // AGILE - EPICS
  // ─────────────────────────────────────────────────────────────────────────────

  epics: async (params) => {
    try {
      const res = await epicsAPI.list()
      let epics = res.data?.epics || res.data || []
      if (!Array.isArray(epics)) epics = []

      // Filter by status if specified
      if (params.status) {
        const status = params.status.toLowerCase()
        epics = epics.filter((e: any) => (e.status || '').toLowerCase() === status)
      }

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const e of epics) {
        const status = e.status || 'open'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      return {
        data: {
          total_count: epics.length,
          by_status: byStatus,
          epics: epics.slice(0, 12).map((e: any) => ({
            title: e.title || e.name,
            status: e.status || 'open',
            project: e.projectName || e.project,
            description: e.description,
            story_count: e.storyCount || e.stories?.length,
            progress: e.progress
          }))
        },
        summaryHint: `${epics.length} epics`
      }
    } catch (e: any) {
      console.error('Voice: Epics error:', e?.message)
      return { data: { error: true }, summaryHint: 'Epics data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // AGILE - BUGS
  // ─────────────────────────────────────────────────────────────────────────────

  bugs: async (params) => {
    try {
      const queryParams: Record<string, string> = {}
      if (params.status) queryParams.status = params.status
      if (params.priority) queryParams.priority = params.priority

      const res = await bugsAPI.list(queryParams)
      let bugs = res.data?.bugs || res.data || []
      if (!Array.isArray(bugs)) bugs = []

      // Group by status
      const byStatus: Record<string, number> = {}
      for (const b of bugs) {
        const status = b.status || 'open'
        byStatus[status] = (byStatus[status] || 0) + 1
      }

      // Group by severity/priority
      const bySeverity: Record<string, number> = {}
      for (const b of bugs) {
        const sev = b.severity || b.priority || 'medium'
        bySeverity[sev] = (bySeverity[sev] || 0) + 1
      }

      return {
        data: {
          total_count: bugs.length,
          by_status: byStatus,
          by_severity: bySeverity,
          bugs: bugs.slice(0, 15).map((b: any) => ({
            title: b.title || b.name,
            status: b.status || 'open',
            severity: b.severity || b.priority || 'medium',
            assignee: b.assigneeName || b.assignee,
            project: b.projectName || b.project,
            sprint: b.sprintName || b.sprint,
            description: b.description
          }))
        },
        summaryHint: `${bugs.length} bugs`
      }
    } catch (e: any) {
      console.error('Voice: Bugs error:', e?.message)
      return { data: { error: true }, summaryHint: 'Bugs data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // AGILE DASHBOARD
  // ─────────────────────────────────────────────────────────────────────────────

  agile_dashboard: async () => {
    try {
      const res = await agileDashboardAPI.dashboard()
      const stats = res.data || {}
      return {
        data: {
          ...stats,
          note: 'Agile board overview'
        },
        summaryHint: 'Agile dashboard'
      }
    } catch (e: any) {
      console.error('Voice: Agile dashboard error:', e?.message)
      return { data: { error: true }, summaryHint: 'Agile data unavailable' }
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // MORNING BRIEFING (comprehensive overview)
  // ─────────────────────────────────────────────────────────────────────────────

  morning_briefing: async (_params, userRole) => {
    const [pendingRes, meetingsRes, signoffRes, tasksRes, escalationsRes] = await Promise.all([
      pendingWorkAPI.list().catch(() => ({ data: null })),
      meetingsAPI.list({ portal: userRole || 'ops' }).catch(() => ({ data: null })),
      signoffAPI.status().catch(() => ({ data: null })),
      tasksAPI.list({ filter: 'all' }).catch(() => ({ data: null })),
      escalationsAPI.list({ status: 'open,in_progress' }).catch(() => ({ data: null }))
    ])

    const pending = pendingRes.data || {}
    const meetingItems = meetingsRes.data?.items || []
    const signoffData = signoffRes.data || {}
    const tasks = tasksRes.data?.tasks || []
    const escalations = escalationsRes.data?.items || escalationsRes.data?.data || []

    const todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
    const todayMeetings = meetingItems.filter((m: any) =>
      m.recurrence === 'daily_weekdays' || (m.dayOfWeek || '').toLowerCase() === todayName
    )

    const overdue = tasks.filter((t: any) => t.is_overdue)
    const inProgress = tasks.filter((t: any) => t.status === 'in_progress')
    const blocked = tasks.filter((t: any) => t.blocker_status === 'blocked')
    const urgent = tasks.filter((t: any) => t.priority === 'urgent' && t.status !== 'completed')

    const actionItemsPending = Array.isArray(pending.actionItems) ? pending.actionItems : []
    const dailyReportPending = Array.isArray(pending.dailyReport) ? pending.dailyReport : []
    const openEscalations = Array.isArray(escalations) ? escalations : []

    return {
      data: {
        today: new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }),
        sign_in_status: {
          signed_in: pending.signedIn,
          signed_in_at: pending.signedInAt
        },
        meetings: {
          today_count: todayMeetings.length,
          meetings: todayMeetings.map(fullMeeting)
        },
        tasks: {
          total: tasks.length,
          in_progress: inProgress.length,
          overdue: overdue.length,
          blocked: blocked.length,
          urgent: urgent.length,
          overdue_tasks: overdue.map(fullTask),
          blocked_tasks: blocked.map(fullTask),
          urgent_tasks: urgent.map(fullTask)
        },
        pending_work: {
          action_items: actionItemsPending.length,
          daily_report_fields: dailyReportPending.length,
          top_action_items: actionItemsPending.slice(0, 5).map(fullActionItem)
        },
        escalations: {
          open_count: openEscalations.length,
          items: openEscalations.slice(0, 3).map(fullEscalation)
        },
        sign_off_status: {
          can_sign_off: signoffData.canSignOff,
          pending_items: (signoffData.items || []).filter((i: any) => i.status === 'pending').length
        }
      },
      summaryHint: 'Morning briefing'
    }
  },

  // ─────────────────────────────────────────────────────────────────────────────
  // CONVERSATIONAL
  // ─────────────────────────────────────────────────────────────────────────────

  greeting: async () => ({ data: { type: 'greeting' }, summaryHint: 'greeting' }),

  help: async () => ({
    data: {
      type: 'help',
      capabilities: [
        'Tasks: "What are my tasks?", "Show overdue tasks", "Details on [task name]"',
        'Meetings: "What meetings do I have today?", "What was discussed in [meeting name]?", "Show me the agenda for standup"',
        'Daily Reports: "How is my daily report?", "What fields are missing?"',
        'KPIs: "What are my KPIs?", "How am I doing on KPIs?"',
        'Action Items: "What action items do I have?", "Show pending action items"',
        'Escalations: "Are there any escalations?", "Show open escalations"',
        'Sign In/Off: "Sign me in", "What do I need to sign off?"',
        'Morning Briefing: "Good morning", "Give me a briefing"',
        'Sprint: "How is the sprint going?"'
      ]
    },
    summaryHint: 'help'
  }),

  unknown: async () => ({ data: { type: 'unknown' }, summaryHint: 'unknown' }),

  // Aliases
  pending_tasks: async (params, userRole) => intentHandlers.pending_work(params, userRole),
  sign_off: async (params, userRole) => intentHandlers.sign_off_action(params, userRole)
}

export async function orchestrate(
  intent: string,
  params: Record<string, string> = {},
  userRole?: string
): Promise<OrchestratorResult> {
  const handler = intentHandlers[intent]
  if (!handler) {
    console.log('Voice: Unknown intent:', intent)
    return { data: { type: 'unknown', requested_intent: intent }, summaryHint: 'unknown intent' }
  }

  try {
    console.log('Voice: Fetching data for intent:', intent, 'params:', params)
    const result = await handler(params, userRole)
    console.log('Voice: Data prepared:', result.summaryHint)
    return result
  } catch (error: any) {
    console.error(`Voice orchestrator error for intent "${intent}":`, error)
    return {
      data: { error: true, message: error?.message || 'Failed to fetch data', intent },
      summaryHint: 'error'
    }
  }
}
