export interface User {
  id: number
  name: string
  email: string
  role: string
  designation?: string
  department?: string
  avatar?: string
}

export interface PortalConfig {
  features: string[]
  title: string
  userName: string
  roleName: string
  people: Person[]
  kpiDefinitions?: KpiGroup[]
  hasPreviousMinutes?: boolean
  showNextMeetingBanner?: boolean
  layout?: string
}

export interface Person {
  id: number
  name: string
  role?: string
  designation?: string
}

// ── Meetings ──

export interface Meeting {
  id: number
  title: string
  day: string
  time: string
  owner_id: number
  owner?: Person
  recurrence: 'daily' | 'weekly' | 'one_time'
  portal?: string
  attendees?: Person[]
  attendee_ids?: number[]
  agenda_template_id?: number | null
  skip_dates?: string[]
}

export interface AgendaSection {
  id: number
  meeting_id: number
  title: string
  sort_order: number
  points: DiscussionPoint[]
}

export interface DiscussionPoint {
  id: number
  section_id: number
  question: string
  notes?: string
  sort_order: number
}

export interface ActionItem {
  id: number
  meeting_id: number
  title: string
  owner_id: number
  owner?: Person
  status: string
  priority: string
  deadline?: string
  comment?: string
  kpi_field?: string
  week_key: string
  carried_from?: string
  created_at: string
}

export interface MeetingNote {
  id: number
  meeting_id: number
  content: string
  week_key: string
  day?: string
  created_at: string
}

export interface AgendaTemplate {
  id: number
  name: string
  sections: AgendaTemplateSection[]
}

export interface AgendaTemplateSection {
  id: number
  title: string
  sort_order: number
  points: AgendaTemplatePoint[]
}

export interface AgendaTemplatePoint {
  id: number
  question: string
  sort_order: number
}

// ── KPI ──

export interface KpiGroup {
  key: string
  label: string
  fields: KpiField[]
}

export interface KpiField {
  key: string
  label: string
  type: string
  aggregation?: string
  unit?: string
  group: string
}

export interface KpiEntry {
  field_key: string
  value: string | number
  date: string
  user_id: number
}

// ── Tessa AI ──

export interface TessaChat {
  id: number | string
  title?: string
  created_at: string
  updated_at?: string
}

export interface TessaMessage {
  id: number | string
  role: 'user' | 'assistant'
  content: string
  created_at: string
}

// ── Tasks ──

export interface TessaTask {
  id: number
  title: string
  description?: string
  status: string
  priority: string
  deadline?: string
  creator_id: number
  creator?: Person
  assignee_id?: number
  assignee?: Person
  participants?: Person[]
  ai_summary?: string
  blocker_notes?: string
  created_at: string
  updated_at: string
}

export interface TaskMessage {
  id: number
  task_id: number
  user_id: number
  user?: Person
  content: string
  is_read?: boolean
  created_at: string
}

// ── Daily Reports ──

export interface DailyReport {
  id: number
  user_id: number
  date: string
  fields: Record<string, string | number>
  status?: string
}

// ── Escalations ──
// Source: EscalationController + Escalation model

export interface Escalation {
  id: number
  title: string
  description?: string
  severity: 'P0' | 'P1' | 'P2' | 'P3'
  status: 'open' | 'in_progress' | 'escalated' | 'resolved' | 'closed'
  category: 'app_crash' | 'bug' | 'payment' | 'creator' | 'user_complaint' | 'other'
  raised_by?: number
  raised_by_name?: string
  assigned_to_role?: string
  resolved_by?: number
  resolution_note?: string
  created_at: string
}

// ── Releases ──
// Source: ReleaseController + Release model

export interface Release {
  id: number
  title: string
  version: string
  projectId: number
  projectName: string
  status: 'planned' | 'in_progress' | 'testing' | 'released' | 'delayed' | 'cancelled'
  description?: string
  progress: number
  plannedDate: string
  actualDate?: string
  isDelayed?: boolean
  daysOverdue?: number
  created_at: string
}

export interface ReleaseProject {
  id: number
  name: string
}

// ── Tickets ──
// Source: TicketController + Ticket model

export interface Ticket {
  id: number
  title: string
  description?: string
  status: 'open' | 'in_progress' | 'resolved' | 'closed'
  priority: 'low' | 'medium' | 'high'
  category: 'technical' | 'ai'
  assigneeId?: number
  assigneeName?: string
  reporterId?: number
  reporterName?: string
  resolvedAt?: string
  createdAt: string
}

// ── Invoices ──
// Source: InvoiceSubmissionController + InvoiceSubmission model

export interface InvoiceSubmission {
  id: number
  vendorName: string
  amount: number
  invoiceDate: string
  invoiceNumber?: string
  category?: string
  filePath?: string
  fileName?: string
  notes?: string
  status: 'pending' | 'reviewed' | 'approved' | 'rejected'
  userName?: string
  aiExtractedVendor?: string
  aiExtractedAmount?: number
  aiExtractedDate?: string
  matchConfidence?: number
  verificationStatus?: 'pending' | 'verified' | 'mismatch' | 'no_match'
  matchedTransactionId?: number
  created_at: string
}

export interface BankTransaction {
  id: number
  date: string
  description: string
  reference?: string
  amount: number
  type: 'credit' | 'debit'
  balance?: number
  bankName?: string
  matchStatus: 'unmatched' | 'matched' | 'ignored'
  matchedInvoice?: { vendorName: string; amount: number }
}

export interface ReconciliationStats {
  totalTransactions: number
  matched: number
  unmatchedTransactions: number
  unmatchedInvoices: number
}

// ── Leave ──
// Source: LeaveController + LeaveType + LeaveRequest models

export interface LeaveType {
  id: number
  name: string
  slug: string
  requires_approval: boolean
  is_active: boolean
  gender_restricted?: string
}

export interface LeaveRequest {
  id: number
  user_id: number
  user?: Person
  leave_type_id: number
  leave_type?: LeaveType
  start_date: string
  end_date: string
  total_days: number
  reason?: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  reviewer?: Person
  reviewer_note?: string
  reviewed_at?: string
  created_at: string
}

// ── Employees ──
// Source: EmployeeController

export interface EmployeeDoc {
  label: string
  uploaded: boolean
  path?: string
}

export interface Employee {
  id: number
  name: string
  email: string
  designation?: string
  role?: string
  personal_mobile?: string
  personal_email?: string
  emergency_contact_name?: string
  emergency_contact_number?: string
  gender?: string
  employment_type?: 'full_time' | 'internship'
  joining_date?: string
  reporting_manager?: string
  projects?: string
  experienced?: boolean
  hourly_rate?: string
  documents?: Record<string, EmployeeDoc>
  docs_complete: number
  docs_total: number
  can_edit_docs?: boolean
}

export interface EmployeeStats {
  total: number
  full_time: number
  internship: number
  docs_complete: number
  docs_pending: number
}

// ── Scripts ──
// Source: ScriptGenerationController + scripts.js

export interface ScriptGeneration {
  id: number
  language: string
  category: string
  topic?: string
  creative_brief?: string
  requested_count: number
  scripts: string[]
}

export interface ScriptLibraryItem {
  id: number
  body: string
  language: string
  category: string
}

export interface ScriptConfig {
  languages: { value: string; label: string }[]
  categories: { value: string; label: string }[]
  isStatsOnly?: boolean
}

export interface ScriptStats {
  total_generations: number
  total_scripts_generated: number
  library_items_saved: number
  by_language: { language: string; label?: string; generations: number }[]
  by_category: { category: string; generations: number }[]
  by_user: { name: string; generations: number }[]
  recent: { user_name: string; language: string; category: string; script_count: number; created_at: string }[]
}

// ── Meta Ad Reports ──
// Source: MetaAdReportController + MetaAdReport model

export interface MetaAdRow {
  id: number
  project: string
  campaign_name: string
  ad_set_name: string
  ad_name: string
  reach: number
  impressions: number
  frequency?: number
  results?: number
  amount_spent: number
  cost_per_result?: number
  cpc?: number
  cpm?: number
  ctr?: number
  app_installs?: number
  cost_per_install?: number
  new_user_first_purchase?: number
  cost_per_first_purchase?: number
  reporting_starts: string
  reporting_ends?: string
}

export interface AdCoverageDay {
  date: string
  day: string
  uploaded: boolean
  rows?: number
  spend?: number
}

export interface AdSummary {
  total_spend: number
  total_impressions?: number
  total_reach?: number
  total_installs?: number
  total_results?: number
  total_first_purchases?: number
  total_purchases?: number
  total_purchase_value?: number
}

// ── Google Ad Reports ──
// Source: GoogleAdReportController + GoogleAdReport model

export interface GoogleAdRow {
  id: number
  project: string
  campaign_name: string
  currency_code?: string
  cost: number
  avg_cpc?: number
  ctr?: number
  cpi?: number
  cpr?: number
  cpftd?: number
  cp_d1mp?: number
  purchases?: number
  cpp?: number
  purchase_value?: number
  reporting_date: string
}

// ── Revenue ──
// Source: RevenueController + portal.js renderRevenue()

export interface RevenueRow {
  date: string
  revenue: number
  google_spend: number
  meta_spend: number
  payout_paid: number
  agora_cost_inr: number
}

// ── Admin ──
// Source: AdminApiController + admin.js

export interface AdminMeetingRow {
  title: string
  owner: string
  time: string
  recurrence: string
  portal: string
  attendees: string[]
  agendaStatus: 'filled' | 'partial' | 'empty'
  agendaFilled: number
  agendaTotal: number
  notesStatus: 'written' | 'empty'
  rowColor: 'yellow' | 'red' | 'green'
}

export interface AdminDailyRow {
  userName: string
  role: string
  filledCount: number
  totalFields: number
  status: 'submitted' | 'partial' | 'missing' | 'n/a'
}

export interface AdminAttendanceRow {
  meetingKey: string
  title: string
  owner: string
  time: string
  portal: string
  attendees: { userId: number; userName: string; status: 'present' | 'absent' | 'no_data'; source: string | null }[]
  present: number
  total: number
  rate: number
  hasData: boolean
}

// ── Agile ──

export interface Squad {
  id: number
  name: string
  project_id?: number
  project?: { name: string }
  members?: Person[]
}

export interface Sprint {
  id: number
  name: string
  status: string
  start_date: string
  end_date: string
  goal?: string
  project_id?: number
  squad_id?: number
  total_points?: number
  completed_points?: number
}

export interface Epic {
  id: number
  title: string
  description?: string
  project_id?: number
  squad_id?: number
  target_date?: string
  status?: string
  progress?: number
  story_count?: number
}

export interface Story {
  id: number
  title: string
  description?: string
  acceptance_criteria?: string
  status: string
  priority: string
  story_points?: number
  sprint_id?: number | null
  epic_id?: number | null
  project_id?: number
  assignee_id?: number | null
  assignee?: Person
  labels?: Label[]
  type?: 'story' | 'bug'
  severity?: string
  created_at: string
}

export type Bug = Story

export interface Label {
  id: number
  name: string
  color?: string
}

// ── Dashboard ──

export interface DashboardStatus {
  own_team?: DashboardTeamRow[]
  other_teams?: DashboardTeamRow[]
  meetings_today?: DashboardMeetingRow[]
  daily_status?: DashboardDailyRow[]
}

export interface DashboardTeamRow {
  user_id: number
  name: string
  signed_in: boolean
  signed_in_at?: string
  status?: string
}

export interface DashboardMeetingRow {
  id: number
  title: string
  time: string
  agenda_status: string
  notes_status: string
}

export interface DashboardDailyRow {
  user_id: number
  name: string
  filled_count: number
  total_fields: number
  status: string
}

// ── Voice Assistant ──

export type VoiceState = 'idle' | 'listening' | 'processing' | 'speaking' | 'follow_up' | 'error'

export type VoiceIntent =
  | 'pending_tasks'
  | 'my_tasks'
  | 'sprint_status'
  | 'meetings_today'
  | 'daily_report_status'
  | 'kpi_summary'
  | 'sign_in'
  | 'sign_off'
  | 'escalations'
  | 'morning_briefing'
  | 'greeting'
  | 'help'
  | 'unknown'

export interface VoiceIntentResult {
  intent: VoiceIntent
  params: Record<string, string>
  confidence: number
}
