/**
 * Team (Employees) — sourced from portal.js renderEmployees() lines 2487-2757
 * Matches the web portal screenshot exactly.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { employeesAPI } from '@/api/client'
import { Modal, Loader, EmptyState } from '@/components/ui'
import type { Employee, EmployeeStats, EmployeeDoc } from '@/lib/types'
import { stringToColor, initials, classNames } from '@/lib/utils'
import { TESSA_URL } from '@/lib/constants'
import toast from 'react-hot-toast'
import { Users } from 'lucide-react'

const KEY_DOCS = ['aadhar_front_path', 'pan_path', 'passport_photo_path', 'signed_offer_letter_path', 'nda_path']
const KEY_LABELS = ['Aadhar', 'PAN', 'Photo', 'Offer', 'NDA']

const GENDER_OPTS = [
  { value: '', label: 'Select Gender' },
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' }
]

export default function Employees() {
  const [employees, setEmployees] = useState<Employee[]>([])
  const [stats, setStats] = useState<EmployeeStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [expandedId, setExpandedId] = useState<number | null>(null)

  const [search, setSearch] = useState('')
  const [empType, setEmpType] = useState('')
  const [docStatus, setDocStatus] = useState('')

  const [editModal, setEditModal] = useState<Employee | null>(null)

  const fileRefs = useRef<Record<string, HTMLInputElement | null>>({})

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (search) params.search = search
      if (empType) params.employment_type = empType
      if (docStatus) params.doc_status = docStatus
      const res = await employeesAPI.list(params)
      const d = res.data || {}
      setEmployees(d.employees || [])
      setStats(d.stats || null)
    } catch {
      toast.error('Failed to load team')
    } finally {
      setLoading(false)
    }
  }, [search, empType, docStatus])

  useEffect(() => { load() }, [load])

  async function handleUploadDoc(userId: number, field: string, file: File) {
    const fd = new FormData()
    fd.append('action', 'upload_doc')
    fd.append('id', String(userId))
    fd.append('field', field)
    fd.append('file', file)
    try {
      await employeesAPI.upload(fd)
      toast.success('Document uploaded')
      load()
    } catch { toast.error('Upload failed') }
  }

  async function handleDeleteDoc(userId: number, field: string) {
    if (!confirm('Delete this document?')) return
    try {
      await employeesAPI.post({ action: 'delete_doc', id: userId, field })
      toast.success('Document deleted')
      load()
    } catch { toast.error('Delete failed') }
  }

  if (loading && !employees.length) return <Loader label="Loading team..." />

  return (
    <div className="space-y-4">
      {/* ── Header: "Team" + member count badge ── */}
      <div className="flex items-center gap-3">
        <h2 className="text-[17px] font-bold text-zinc-100">Team</h2>
        {stats && (
          <span className="rounded-md border border-zinc-700 bg-surface-2 px-2.5 py-0.5 text-[11px] text-zinc-400">
            {stats.total} members
          </span>
        )}
      </div>

      {/* ── Stats strip — 5 pill cards matching portal ── */}
      {stats && (
        <div className="flex gap-3">
          <StatPill num={stats.total} label="TOTAL" />
          <StatPill num={stats.full_time} label="FULL-TIME" />
          <StatPill num={stats.internship} label="INTERNSHIP" />
          <StatPill num={stats.docs_complete} label="DOCS COMPLETE" border="border-emerald-600/50" numColor="text-emerald-400" />
          <StatPill num={stats.docs_pending} label="DOCS PENDING" border="border-red-600/50" numColor="text-red-400" />
        </div>
      )}

      {/* ── Filters: search + type dropdown + docs dropdown ── */}
      <div className="flex gap-3">
        <input
          type="text"
          value={search}
          onChange={e => setSearch(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') load() }}
          placeholder="Search by name..."
          className="flex-1 rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-[13px] text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-zinc-500"
        />
        <select value={empType} onChange={e => { setEmpType(e.target.value); }}
          className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-[13px] text-zinc-300 focus:outline-none focus:border-zinc-500 w-36">
          <option value="">All Types</option>
          <option value="full_time">Full-time</option>
          <option value="internship">Internship</option>
        </select>
        <select value={docStatus} onChange={e => { setDocStatus(e.target.value); }}
          className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-[13px] text-zinc-300 focus:outline-none focus:border-zinc-500 w-36">
          <option value="">All Docs</option>
          <option value="complete">Docs Complete</option>
          <option value="incomplete">Docs Incomplete</option>
        </select>
      </div>

      {/* ── Employee list ── */}
      {employees.length === 0 ? (
        <EmptyState icon={Users} title="No team members found" />
      ) : (
        <div className="rounded-lg border border-zinc-800 bg-surface-2 divide-y divide-zinc-800/60">
          {employees.map(emp => {
            const color = stringToColor(emp.name)
            const init = initials(emp.name)
            const isFT = emp.employment_type === 'full_time'
            const isIntern = emp.employment_type === 'internship'
            const docs = emp.documents || {}
            const isExpanded = expandedId === emp.id

            return (
              <div key={emp.id}>
                {/* Summary row — clickable to expand */}
                <div
                  className="flex items-center gap-4 px-5 py-3 cursor-pointer hover:bg-zinc-800/30 transition-colors"
                  onClick={() => setExpandedId(isExpanded ? null : emp.id)}
                >
                  {/* Avatar + name + role + badge */}
                  <div className="flex items-center gap-3 min-w-[220px]">
                    <div
                      className="w-9 h-9 rounded-md flex items-center justify-center text-white text-sm font-bold shrink-0"
                      style={{ backgroundColor: color }}
                    >
                      {init}
                    </div>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-[13px] font-semibold text-zinc-100">{emp.name}</span>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <span className="text-[11px] text-zinc-500">{emp.designation || emp.role || ''}</span>
                        {isFT && <span className="rounded px-1.5 py-0.5 text-[9px] font-bold bg-emerald-600/20 text-emerald-400 border border-emerald-600/30">Full-time</span>}
                        {isIntern && <span className="rounded px-1.5 py-0.5 text-[9px] font-bold bg-blue-600/20 text-blue-400 border border-blue-600/30">Intern</span>}
                      </div>
                    </div>
                  </div>

                  {/* Contact details: MOBILE / EMAIL / EMERGENCY */}
                  <div className="flex-1 grid grid-cols-3 gap-x-4 text-[11px] min-w-0">
                    {emp.personal_mobile && (
                      <div><span className="text-zinc-600 uppercase text-[10px] tracking-wide">Mobile</span> <span className="text-zinc-300 ml-1">{emp.personal_mobile}</span></div>
                    )}
                    {emp.personal_email && (
                      <div className="truncate"><span className="text-zinc-600 uppercase text-[10px] tracking-wide">Email</span> <span className="text-zinc-300 ml-1">{emp.personal_email}</span></div>
                    )}
                    {emp.emergency_contact_name && (
                      <div className="truncate"><span className="text-zinc-600 uppercase text-[10px] tracking-wide">Emergency</span> <span className="text-zinc-300 ml-1">{emp.emergency_contact_name}{emp.emergency_contact_number ? ` (${emp.emergency_contact_number})` : ''}</span></div>
                    )}
                  </div>

                  {/* Doc icons: 5 key docs + score */}
                  <div className="flex items-center gap-1 shrink-0">
                    {KEY_DOCS.map((dk, i) => {
                      const d = docs[dk]
                      const up = d && d.uploaded
                      return (
                        <span
                          key={dk}
                          title={KEY_LABELS[i] + (up ? ' (uploaded)' : ' (missing)')}
                          className={classNames(
                            'w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold',
                            up ? 'bg-emerald-600/20 text-emerald-400' : 'bg-red-600/20 text-red-400'
                          )}
                        >
                          {up ? '✓' : '✗'}
                        </span>
                      )
                    })}
                    <span className="text-[11px] text-zinc-500 ml-1.5">{emp.docs_complete}/{emp.docs_total}</span>
                  </div>
                </div>

                {/* Expanded detail */}
                {isExpanded && (
                  <div className="px-5 py-4 bg-zinc-900/50 border-t border-zinc-800/60 space-y-4">
                    {/* Detail grid */}
                    <div className="grid grid-cols-4 gap-4 text-[12px]">
                      <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Office Email</span><span className="text-zinc-200">{emp.email}</span></div>
                      <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Reporting To</span><span className="text-zinc-200">{emp.reporting_manager || '—'}</span></div>
                      <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Projects</span><span className="text-zinc-200">{emp.projects || '—'}</span></div>
                      <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Joining Date</span><span className="text-zinc-200">{emp.joining_date || '—'}</span></div>
                      <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Experienced</span><span className="text-zinc-200">{emp.experienced ? 'Yes' : 'No'}</span></div>
                      {emp.hourly_rate && <div><span className="text-zinc-600 block text-[10px] uppercase tracking-wide">Hourly Rate</span><span className="text-zinc-200">${emp.hourly_rate}</span></div>}
                    </div>

                    {/* Documents grid */}
                    <div>
                      <h4 className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-2">Documents</h4>
                      <div className="grid grid-cols-7 gap-2">
                        {Object.entries(docs).map(([field, doc]) => (
                          <div key={field} className={classNames(
                            'rounded-lg border text-center px-2 py-2.5',
                            doc.uploaded ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-dashed border-zinc-700 bg-zinc-800/30'
                          )}>
                            <div className={classNames('text-[10px] font-semibold truncate', doc.uploaded ? 'text-emerald-400' : 'text-zinc-400')}>{doc.label}</div>
                            <div className={classNames('text-[9px] mt-0.5', doc.uploaded ? 'text-zinc-500' : 'text-zinc-600')}>{doc.uploaded ? 'Uploaded' : 'Missing'}</div>
                            <div className="mt-1.5">
                              {doc.uploaded ? (
                                <button onClick={(e) => { e.stopPropagation(); handleDeleteDoc(emp.id, field) }}
                                  className="w-full rounded border border-zinc-700 bg-zinc-800 px-1 py-0.5 text-[9px] text-zinc-400 hover:text-red-400 hover:border-red-500/40 transition-colors">
                                  Delete
                                </button>
                              ) : (
                                <>
                                  <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png"
                                    ref={el => { fileRefs.current[`${emp.id}_${field}`] = el }}
                                    onChange={e => { const f = e.target.files?.[0]; if (f) handleUploadDoc(emp.id, field, f); e.target.value = '' }} />
                                  <button onClick={(e) => { e.stopPropagation(); fileRefs.current[`${emp.id}_${field}`]?.click() }}
                                    className="w-full rounded border border-zinc-700 bg-zinc-800 px-1 py-0.5 text-[9px] text-zinc-400 hover:text-blue-400 hover:border-blue-500/40 transition-colors">
                                    Upload
                                  </button>
                                </>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Edit button */}
                    <button onClick={(e) => { e.stopPropagation(); setEditModal(emp) }}
                      className="rounded-md border border-zinc-700 bg-zinc-800 px-4 py-1.5 text-[12px] font-medium text-zinc-300 hover:bg-zinc-700 transition-colors">
                      Edit Details
                    </button>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* ── Edit Modal ── */}
      {editModal && (
        <EditModal emp={editModal} onClose={() => setEditModal(null)} onSaved={() => { setEditModal(null); load() }} />
      )}
    </div>
  )
}

/* ── Stats Pill ── */

function StatPill({ num, label, border, numColor }: { num: number; label: string; border?: string; numColor?: string }) {
  return (
    <div className={classNames('flex-1 rounded-xl border bg-surface-2 py-3 text-center', border || 'border-zinc-800')}>
      <div className={classNames('text-[22px] font-bold', numColor || 'text-zinc-100')}>{num}</div>
      <div className="text-[10px] uppercase tracking-wider text-zinc-500 mt-0.5">{label}</div>
    </div>
  )
}

/* ── Edit Modal — sourced from portal.js showEmpEditModal() lines 2707-2757 ── */

function EditModal({ emp, onClose, onSaved }: { emp: Employee; onClose: () => void; onSaved: () => void }) {
  const [designation, setDesignation] = useState(emp.designation || '')
  const [mobile, setMobile] = useState(emp.personal_mobile || '')
  const [email, setEmail] = useState(emp.personal_email || '')
  const [emgName, setEmgName] = useState(emp.emergency_contact_name || '')
  const [emgNum, setEmgNum] = useState(emp.emergency_contact_number || '')
  const [gender, setGender] = useState(emp.gender || '')
  const [type, setType] = useState(emp.employment_type || 'full_time')
  const [joining, setJoining] = useState(emp.joining_date || '')
  const [saving, setSaving] = useState(false)

  async function handleSave() {
    setSaving(true)
    try {
      await employeesAPI.post({
        action: 'update', id: emp.id,
        designation, gender: gender || null,
        personal_mobile: mobile, personal_email: email,
        emergency_contact_name: emgName, emergency_contact_number: emgNum,
        employment_type: type, joining_date: joining || null
      })
      toast.success('Updated')
      onSaved()
    } catch { toast.error('Save failed'); setSaving(false) }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" onClick={e => { if (e.target === e.currentTarget) onClose() }}>
      <div className="w-full max-w-md rounded-lg border border-zinc-800 bg-surface-1 shadow-xl">
        <div className="flex items-center justify-between border-b border-zinc-800 px-5 py-3">
          <h3 className="text-[14px] font-semibold text-zinc-100">Edit — {emp.name}</h3>
          <button onClick={onClose} className="text-zinc-500 hover:text-zinc-300 text-lg leading-none">&times;</button>
        </div>
        <div className="px-5 py-4 grid grid-cols-2 gap-3">
          <Field label="Designation" value={designation} onChange={setDesignation} />
          <Field label="Personal Mobile" value={mobile} onChange={setMobile} />
          <Field label="Personal Email" value={email} onChange={setEmail} />
          <Field label="Emergency Contact" value={emgName} onChange={setEmgName} />
          <Field label="Emergency Number" value={emgNum} onChange={setEmgNum} />
          <div>
            <label className="block text-[11px] text-zinc-500 mb-1">Gender</label>
            <select value={gender} onChange={e => setGender(e.target.value)}
              className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500">
              <option value="">— Select —</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
            </select>
          </div>
          <div>
            <label className="block text-[11px] text-zinc-500 mb-1">Employment Type</label>
            <select value={type} onChange={e => setType(e.target.value)}
              className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500">
              <option value="full_time">Full-time</option><option value="internship">Internship</option>
            </select>
          </div>
          <div>
            <label className="block text-[11px] text-zinc-500 mb-1">Joining Date</label>
            <input type="date" value={joining} onChange={e => setJoining(e.target.value)}
              className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500" />
          </div>
        </div>
        <div className="flex items-center justify-end gap-2 border-t border-zinc-800 px-5 py-3">
          <button onClick={onClose} className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:bg-zinc-700 transition-colors">Cancel</button>
          <button onClick={handleSave} disabled={saving}
            className="rounded-md bg-brand-600 px-4 py-1.5 text-[12px] font-medium text-white hover:bg-brand-500 disabled:opacity-50 transition-colors">
            {saving ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  )
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="block text-[11px] text-zinc-500 mb-1">{label}</label>
      <input type="text" value={value} onChange={e => onChange(e.target.value)} placeholder={label}
        className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500" />
    </div>
  )
}
