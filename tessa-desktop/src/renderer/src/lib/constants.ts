export const TESSA_URL = 'https://tessa.innovfix.ai'

export const FEATURE_LABELS: Record<string, string> = {
  dashboard: 'Dashboard',
  tessa: 'Tessa',
  tasks: 'Tasks',
  meetings: 'Meetings',
  calendar: 'Calendar',
  daily: 'Daily Reports',
  kpi: 'Team KPIs',
  mkpi: 'Marketing KPIs',
  escalations: 'Escalations',
  org: 'Org Chart',
  templates: 'Templates',
  signoff: 'Sign Off',
  releases: 'Releases',
  scripts: 'Scripts',
  tickets: 'Tickets',
  invoices: 'Invoices',
  meta_ads: 'Meta Ads',
  google_ads: 'Google Ads',
  mission: 'Mission',
  employees: 'Team',
  profile: 'My Profile',
  agile: 'Agile',
  leave: 'Leave',
  revenue: 'Revenue'
}

export const FEATURE_ROUTES: Record<string, string> = {
  dashboard: '/',
  tessa: '/tessa',
  tasks: '/tasks',
  meetings: '/meetings',
  calendar: '/calendar',
  daily: '/daily-reports',
  kpi: '/kpi',
  mkpi: '/marketing-kpi',
  escalations: '/escalations',
  org: '/org',
  templates: '/templates',
  signoff: '/signoff',
  releases: '/releases',
  scripts: '/scripts',
  tickets: '/tickets',
  invoices: '/invoices',
  meta_ads: '/meta-ads',
  google_ads: '/google-ads',
  mission: '/mission',
  employees: '/employees',
  profile: '/profile',
  agile: '/agile',
  leave: '/leave',
  revenue: '/revenue'
}

export const STATUS_COLORS: Record<string, string> = {
  pending: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
  in_progress: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  done: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
  completed: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
  open: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  closed: 'bg-zinc-700/50 text-zinc-400 border-zinc-600/20',
  cancelled: 'bg-zinc-700/50 text-zinc-500 border-zinc-600/20',
  blocked: 'bg-red-500/10 text-red-400 border-red-500/20',
  approved: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
  rejected: 'bg-red-500/10 text-red-400 border-red-500/20',
  active: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  review: 'bg-purple-500/10 text-purple-400 border-purple-500/20'
}

export const PRIORITY_COLORS: Record<string, string> = {
  critical: 'bg-red-500/10 text-red-400',
  high: 'bg-orange-500/10 text-orange-400',
  medium: 'bg-amber-500/10 text-amber-400',
  low: 'bg-zinc-700/50 text-zinc-400'
}

export const DAYS_OF_WEEK = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
export const DAYS_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']
