import axios from 'axios'
import { TESSA_URL } from '@/lib/constants'

const isDev = import.meta.env.DEV

// Always use relative URLs — proxied in both dev (Vite) and prod (Electron local server).
// This keeps everything same-origin so session cookies work without cross-origin hacks.
const api = axios.create({
  baseURL: '',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
    }
    return Promise.reject(error)
  }
)

export default api

function postForm(url: string, data: FormData) {
  return api.post(url, data, {
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}

// ── Portal Config (extract from web page HTML) ──

export async function fetchPortalConfig() {
  // Strategy 1: Fetch the portal HTML page and extract __PORTAL_CONFIG from the script tag.
  // This works in dev (via proxy) and sometimes in prod if cookies pass.
  try {
    // Always use /__portal/ — proxied in both dev (Vite) and prod (Electron server)
    const url = '/__portal/'
    console.log('[PortalConfig] Fetching HTML from:', url)
    const res = await api.get(url, {
      headers: { Accept: 'text/html' },
      responseType: 'text',
      // Don't treat HTML redirect-to-login as error
      validateStatus: (s) => s < 400
    })
    const html: string = res.data
    console.log('[PortalConfig] Got HTML, length:', html.length)
    const marker = 'window.__PORTAL_CONFIG = '
    const idx = html.indexOf(marker)
    if (idx >= 0) {
      const jsonStart = idx + marker.length
      const scriptEnd = html.indexOf('</script>', jsonStart)
      if (scriptEnd >= 0) {
        let jsonStr = html.substring(jsonStart, scriptEnd).trim()
        if (jsonStr.endsWith(';')) jsonStr = jsonStr.slice(0, -1)
        const config = JSON.parse(jsonStr)
        console.log('[PortalConfig] SUCCESS — features:', config.features?.length)
        return config
      }
    }
    console.warn('[PortalConfig] Marker not found in HTML (may be login page redirect)')
  } catch (err: any) {
    console.warn('[PortalConfig] HTML fetch failed:', err?.message || err)
  }

  // Strategy 2: Use the session endpoint to get basic user info.
  // This always works since it's a JSON API endpoint with cookies.
  try {
    console.log('[PortalConfig] Falling back to session endpoint')
    const res = await api.get('/api/auth/session')
    const data = res.data
    if (data?.authenticated && data?.user) {
      // Build a minimal config from session data
      const config = {
        features: data.features || [],
        title: data.title || 'Portal',
        userName: data.user?.name || '',
        roleName: data.roleName || data.user?.role || '',
        people: data.people || [],
        kpiDefinitions: data.kpiDefinitions || []
      }
      if (config.features.length > 0) {
        console.log('[PortalConfig] Got config from session endpoint, features:', config.features.length)
        return config
      }
    }
  } catch (err: any) {
    console.warn('[PortalConfig] Session fallback failed:', err?.message || err)
  }

  console.warn('[PortalConfig] All strategies failed — will use role-based fallback')
  return null
}

// ── Fallback feature list per role (derived from PermissionSeeder + DashboardController) ──
// Source: database/seeders/PermissionSeeder.php → feature.* permissions
// Source: app/Http/Controllers/DashboardController.php → featureMap + force-adds (tessa, tasks, profile, leave)
// Source: app/Models/Role.php → all SLUG_* constants

export function getFallbackFeatures(role: string): string[] {
  const slug = role.toLowerCase().replace(/\s+/g, '_')
  return ROLE_FEATURES[slug] || DEFAULT_FEATURES
}

const DEFAULT_FEATURES = [
  'dashboard', 'tessa', 'tasks', 'meetings', 'calendar',
  'org', 'templates', 'profile', 'leave'
]

const ROLE_FEATURES: Record<string, string[]> = {
  // C-suite
  ceo: [
    'mission', 'tessa', 'tasks', 'meetings', 'calendar', 'mkpi',
    'escalations', 'org', 'templates', 'scripts', 'invoices',
    'meta_ads', 'employees', 'profile', 'leave'
  ],
  coo: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'invoices', 'meta_ads',
    'mission', 'employees', 'profile', 'leave'
  ],
  cmo: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'scripts', 'invoices', 'meta_ads',
    'mission', 'profile', 'leave'
  ],
  cfo: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'org', 'templates',
    'invoices', 'meta_ads', 'mission', 'employees', 'profile', 'leave'
  ],

  // Operations
  ops: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'templates', 'profile', 'leave'
  ],
  product_manager: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'templates', 'profile', 'leave'
  ],

  // Tech
  tech_lead: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'invoices', 'meta_ads', 'profile', 'agile', 'leave'
  ],
  full_stack_developer: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar',
    'org', 'templates', 'profile', 'agile', 'leave'
  ],
  gen_ai_developer: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'tickets', 'profile', 'agile', 'leave'
  ],
  qa_analyst: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'profile', 'agile', 'leave'
  ],
  data_analyst: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'profile', 'leave'
  ],
  business_analyst: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'org', 'templates', 'profile', 'leave'
  ],

  // Marketing & Content
  marketing: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'meta_ads', 'profile', 'leave'
  ],
  growth_manager: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'meta_ads', 'profile', 'leave'
  ],
  content_lead: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'scripts', 'tickets', 'profile', 'leave'
  ],
  content_creator: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'scripts', 'tickets', 'profile', 'leave'
  ],
  social_media: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'scripts', 'profile', 'leave'
  ],
  video_editor: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'scripts', 'profile', 'leave'
  ],
  graphic_designer: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'scripts', 'profile', 'leave'
  ],

  // Support & Finance
  hr: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'employees', 'profile', 'leave'
  ],
  technical_support: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi',
    'escalations', 'org', 'templates', 'profile', 'leave'
  ],
  accountant: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'org', 'templates',
    'invoices', 'profile', 'leave'
  ],

  // Admin
  admin: [
    'dashboard', 'tessa', 'tasks', 'meetings', 'calendar', 'daily', 'kpi', 'mkpi',
    'escalations', 'org', 'templates', 'releases', 'scripts', 'tickets',
    'invoices', 'meta_ads', 'google_ads', 'mission', 'employees', 'profile', 'agile', 'leave'
  ]
}

// ── Auth ──

export const authAPI = {
  session: () => api.get('/api/auth/session'),
  login: (email: string, password: string) =>
    api.post('/api/auth/login', { email, password }),
  logout: () => api.post('/api/auth/logout'),
  changePassword: (current_password: string, new_password: string) =>
    api.post('/api/auth/change-password', { current_password, new_password })
}

// ── Meetings ──

export const meetingsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/meetings', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/meetings', data),
  notes: (params: Record<string, string>) => api.get('/api/meeting-notes', { params }),
  saveNote: (data: Record<string, unknown>) => api.post('/api/meeting-notes', data),
  agendaSections: (params: Record<string, string>) => api.get('/api/agenda-sections', { params }),
  postAgendaSection: (data: Record<string, unknown>) => api.post('/api/agenda-sections', data),
  discussionPoints: (params?: Record<string, string>) => api.get('/api/discussion-points', { params }),
  postDiscussionPoint: (data: Record<string, unknown>) => api.post('/api/discussion-points', data),
  actionItems: (params: Record<string, string>) => api.get('/api/action-items', { params }),
  postActionItem: (data: Record<string, unknown>) => api.post('/api/action-items', data),
  attendance: (params: Record<string, string>) => api.get('/api/meeting-attendance', { params }),
  overrideAttendance: (data: Record<string, unknown>) => api.post('/api/meeting-attendance', data)
}

// ── Agenda Templates ──

export const templatesAPI = {
  list: () => api.get('/api/agenda-templates'),
  post: (data: Record<string, unknown>) => api.post('/api/agenda-templates', data)
}

// ── Dashboard ──

export const dashboardAPI = {
  status: () => api.get('/api/dashboard-status')
}

// ── KPI ──

export const kpiAPI = {
  definitions: (params?: Record<string, string>) => api.get('/api/kpi-definitions', { params }),
  postDefinition: (data: Record<string, unknown>) => api.post('/api/kpi-definitions', data),
  entries: (params?: Record<string, string>) => api.get('/api/kpi', { params }),
  postEntry: (data: Record<string, unknown>) => api.post('/api/kpi', data)
}

// ── Daily Reports ──

export const dailyReportsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/daily-reports', { params }),
  submit: (data: Record<string, unknown>) => api.post('/api/daily-reports', data)
}

// ── Creative Uploads ──

export const creativeUploadsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/creative-uploads', { params }),
  upload: (data: FormData) => postForm('/api/creative-uploads', data),
  post: (data: Record<string, unknown>) => api.post('/api/creative-uploads', data)
}

// ── Escalations ──

export const escalationsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/escalations', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/escalations', data)
}

// ── Sign Off ──

export const signoffAPI = {
  status: (params?: Record<string, string>) => api.get('/api/signoff-status', { params }),
  submit: (data: Record<string, unknown>) => api.post('/api/signoff', data),
  destroy: () => api.delete('/api/signoff'),
  signIn: () => api.post('/api/signin'),
  undoSignIn: () => api.delete('/api/signin')
}

// ── Pending Work ──

export const pendingWorkAPI = {
  list: () => api.get('/api/pending-work')
}

// ── Tessa AI ──

export const tessaAPI = {
  chats: () => api.get('/api/tessa/chats'),
  chat: (data: Record<string, unknown>) => api.post('/api/tessa/chat', data),
  messages: (chatId: number | string) => api.get(`/api/tessa/chats/${chatId}/messages`),
  updateChat: (chatId: number | string, data: Record<string, unknown>) =>
    api.patch(`/api/tessa/chats/${chatId}`, data),
  deleteChat: (chatId: number | string) => api.delete(`/api/tessa/chats/${chatId}`)
}

// ── Tasks ──

export const tasksAPI = {
  list: (params?: Record<string, string>) => api.get('/api/tessa/tasks', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/tessa/tasks', data),
  get: (id: number) => api.get(`/api/tessa/tasks/${id}`),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/tessa/tasks/${id}`, data),
  destroy: (id: number) => api.delete(`/api/tessa/tasks/${id}`),
  thread: (id: number) => api.get(`/api/tessa/tasks/${id}/thread`),
  postThread: (id: number, data: Record<string, unknown>) =>
    api.post(`/api/tessa/tasks/${id}/thread`, data),
  invite: (id: number, data: Record<string, unknown>) =>
    api.post(`/api/tessa/tasks/${id}/invite`, data)
}

// ── Releases ──

export const releasesAPI = {
  list: (params?: Record<string, string>) => api.get('/api/releases', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/releases', data),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/releases/${id}`, data),
  destroy: (id: number) => api.delete(`/api/releases/${id}`)
}

// ── Scripts ──

export const scriptsAPI = {
  stats: () => api.get('/api/scripts/stats'),
  list: (params?: Record<string, string>) => api.get('/api/scripts', { params }),
  generate: (data: Record<string, unknown>) => api.post('/api/scripts/generate', data),
  saveLibrary: (data: Record<string, unknown>) => api.post('/api/scripts/library', data),
  destroyLibrary: (id: number) => api.delete(`/api/scripts/library/${id}`)
}

// ── Tickets ──

export const ticketsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/tickets', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/tickets', data),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/tickets/${id}`, data)
}

// ── Invoices ──

export const invoicesAPI = {
  list: (params?: Record<string, string>) => api.get('/api/invoice-submissions', { params }),
  create: (data: FormData) => postForm('/api/invoice-submissions', data),
  post: (data: Record<string, unknown>) => api.post('/api/invoice-submissions', data),
  downloadAll: (params?: Record<string, string>) =>
    api.get('/api/invoice-submissions/download-all', { params, responseType: 'blob' }),
  reconciliation: (params?: Record<string, string>) => api.get('/api/invoice-reconciliation', { params })
}

// ── Agile: Squads ──

export const squadsAPI = {
  list: () => api.get('/api/squads'),
  create: (data: Record<string, unknown>) => api.post('/api/squads', data),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/squads/${id}`, data),
  addMember: (id: number, data: Record<string, unknown>) =>
    api.post(`/api/squads/${id}/members`, data),
  removeMember: (id: number, userId: number) =>
    api.delete(`/api/squads/${id}/members/${userId}`)
}

// ── Agile: Sprints ──

export const sprintsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/sprints', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/sprints', data),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/sprints/${id}`, data),
  activate: (id: number) => api.post(`/api/sprints/${id}/activate`),
  review: (id: number) => api.post(`/api/sprints/${id}/review`),
  close: (id: number) => api.post(`/api/sprints/${id}/close`),
  board: (id: number) => api.get(`/api/sprints/${id}/board`),
  burndown: (id: number) => api.get(`/api/sprints/${id}/burndown`)
}

// ── Agile: Epics ──

export const epicsAPI = {
  list: () => api.get('/api/epics'),
  create: (data: Record<string, unknown>) => api.post('/api/epics', data),
  get: (id: number) => api.get(`/api/epics/${id}`),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/epics/${id}`, data),
  destroy: (id: number) => api.delete(`/api/epics/${id}`)
}

// ── Agile: Stories ──

export const storiesAPI = {
  list: (params?: Record<string, string>) => api.get('/api/stories', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/stories', data),
  get: (id: number) => api.get(`/api/stories/${id}`),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/stories/${id}`, data),
  destroy: (id: number) => api.delete(`/api/stories/${id}`),
  move: (id: number, data: Record<string, unknown>) => api.patch(`/api/stories/${id}/move`, data),
  bulkMove: (data: Record<string, unknown>) => api.post('/api/stories/bulk-move', data)
}

// ── Agile: Bugs ──

export const bugsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/bugs', { params }),
  create: (data: Record<string, unknown>) => api.post('/api/bugs', data),
  update: (id: number, data: Record<string, unknown>) => api.put(`/api/bugs/${id}`, data),
  move: (id: number, data: Record<string, unknown>) => api.patch(`/api/bugs/${id}/move`, data)
}

// ── Agile: Labels ──

export const labelsAPI = {
  list: () => api.get('/api/labels'),
  create: (data: Record<string, unknown>) => api.post('/api/labels', data),
  destroy: (id: number) => api.delete(`/api/labels/${id}`)
}

// ── Agile: Dashboard + Velocity ──

export const agileDashboardAPI = {
  dashboard: () => api.get('/api/agile/dashboard'),
  velocity: () => api.get('/api/agile/velocity')
}

// ── Agile: AI ──

export const agileAiAPI = {
  writeStory: (data: Record<string, unknown>) => api.post('/api/agile/ai/write-story', data),
  writeBug: (data: Record<string, unknown>) => api.post('/api/agile/ai/write-bug', data),
  planSprint: (data: Record<string, unknown>) => api.post('/api/agile/ai/plan-sprint', data),
  standupSummary: (data: Record<string, unknown>) => api.post('/api/agile/ai/standup-summary', data),
  sprintRetro: (data: Record<string, unknown>) => api.post('/api/agile/ai/sprint-retro', data),
  epicInsights: (data: Record<string, unknown>) => api.post('/api/agile/ai/epic-insights', data),
  suggestAssignee: (data: Record<string, unknown>) => api.post('/api/agile/ai/suggest-assignee', data),
  prioritizeBacklog: (data: Record<string, unknown>) => api.post('/api/agile/ai/prioritize-backlog', data),
  predictVelocity: (data: Record<string, unknown>) => api.post('/api/agile/ai/predict-velocity', data),
  reviewNudge: (data: Record<string, unknown>) => api.post('/api/agile/ai/review-nudge', data),
  validateAcceptance: (data: Record<string, unknown>) => api.post('/api/agile/ai/validate-acceptance', data)
}

// ── Employees ──

export const employeesAPI = {
  list: (params?: Record<string, string>) => api.get('/api/employees', { params }),
  post: (data: Record<string, unknown>) => api.post('/api/employees', data),
  upload: (data: FormData) => postForm('/api/employees', data),
  profile: () => api.get('/api/profile'),
  updateProfile: (data: Record<string, unknown> | FormData) =>
    data instanceof FormData ? postForm('/api/profile', data) : api.post('/api/profile', data)
}

// ── Leave ──

export const leaveAPI = {
  types: () => api.get('/api/leave/types'),
  requests: (params?: Record<string, string>) => api.get('/api/leave/requests', { params }),
  submit: (data: Record<string, unknown>) => api.post('/api/leave/requests', data),
  review: (id: number, data: Record<string, unknown>) =>
    api.post(`/api/leave/requests/${id}/review`, data),
  cancel: (id: number) => api.post(`/api/leave/requests/${id}/cancel`),
  teamPending: () => api.get('/api/leave/team-pending')
}

// ── Meta Ad Reports ──

export const metaAdsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/meta-ad-reports', { params }),
  upload: (data: FormData) => postForm('/api/meta-ad-reports', data),
  post: (data: Record<string, unknown>) => api.post('/api/meta-ad-reports', data)
}

// ── Google Ad Reports ──

export const googleAdsAPI = {
  list: (params?: Record<string, string>) => api.get('/api/google-ad-reports', { params }),
  upload: (data: FormData) => postForm('/api/google-ad-reports', data),
  post: (data: Record<string, unknown>) => api.post('/api/google-ad-reports', data)
}

// ── Revenue ──

export const revenueAPI = {
  dailyPayout: (params?: Record<string, string>) => api.get('/api/revenue/daily-payout', { params })
}

// ── HR Applicants ──

export const hrApplicantsAPI = {
  list: () => api.get('/api/hr-applicants'),
  update: (id: number, data: Record<string, unknown>) => api.patch(`/api/hr-applicants/${id}`, data)
}

// ── Admin ──

export const adminAPI = {
  meetingsOverview: (params?: Record<string, string>) =>
    api.get('/api/admin/meetings-overview', { params }),
  dailyReportsOverview: (params?: Record<string, string>) =>
    api.get('/api/admin/daily-reports-overview', { params }),
  attendanceOverview: (params?: Record<string, string>) =>
    api.get('/api/meeting-attendance/overview', { params })
}
