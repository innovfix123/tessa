/**
 * My Profile — sourced from portal.js renderProfile() lines 2760-2909
 * Matches the web portal screenshot exactly.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { employeesAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { stringToColor, initials, classNames } from '@/lib/utils'
import type { Employee, EmployeeDoc } from '@/lib/types'
import toast from 'react-hot-toast'
import { TESSA_URL } from '@/lib/constants'

export default function Profile() {
  const [profile, setProfile] = useState<Employee | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [personalMobile, setPersonalMobile] = useState('')
  const [personalEmail, setPersonalEmail] = useState('')
  const [emergencyName, setEmergencyName] = useState('')
  const [emergencyNumber, setEmergencyNumber] = useState('')

  const fileRefs = useRef<Record<string, HTMLInputElement | null>>({})

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await employeesAPI.profile()
      const p: Employee = res.data.profile
      setProfile(p)
      setPersonalMobile(p.personal_mobile || '')
      setPersonalEmail(p.personal_email || '')
      setEmergencyName(p.emergency_contact_name || '')
      setEmergencyNumber(p.emergency_contact_number || '')
    } catch {
      toast.error('Failed to load profile')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const handleSave = async () => {
    setSaving(true)
    try {
      await employeesAPI.updateProfile({
        action: 'update',
        personal_mobile: personalMobile,
        personal_email: personalEmail,
        emergency_contact_name: emergencyName,
        emergency_contact_number: emergencyNumber
      })
      toast.success('Profile updated')
      load()
    } catch {
      toast.error('Failed to update profile')
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteDoc = async (field: string) => {
    if (!confirm('Delete this document?')) return
    try {
      await employeesAPI.updateProfile({ action: 'delete_doc', field })
      toast.success('Document deleted')
      load()
    } catch {
      toast.error('Failed to delete document')
    }
  }

  const handleUploadDoc = async (field: string, file: File) => {
    const fd = new FormData()
    fd.append('action', 'upload_doc')
    fd.append('field', field)
    fd.append('file', file)
    try {
      await employeesAPI.updateProfile(fd)
      toast.success('Document uploaded')
      load()
    } catch {
      toast.error('Failed to upload document')
    }
  }

  if (loading) return <Loader label="Loading profile..." />
  if (!profile) return <div className="text-sm text-zinc-500 text-center py-12">Could not load profile.</div>

  const color = stringToColor(profile.name)
  const init = initials(profile.name)
  const isFT = profile.employment_type === 'full_time'
  const docs: Record<string, EmployeeDoc> = profile.documents || {}
  const canEditDocs = profile.can_edit_docs === true

  return (
    <div className="space-y-5">
      {/* Page title */}
      <h2 className="text-[15px] font-semibold text-zinc-100">My Profile</h2>

      {/* Profile card — matches portal emp-card emp-card-expanded */}
      <div className="rounded-lg border border-zinc-800 bg-surface-2">
        {/* Top row: avatar + name + doc score */}
        <div className="flex items-center gap-4 px-5 py-4 border-b border-zinc-800">
          <div
            className="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-bold shrink-0"
            style={{ backgroundColor: color }}
          >
            {init}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <span className="text-[13px] font-semibold text-zinc-100">{profile.name}</span>
              <span className="text-[11px] text-zinc-400">{profile.designation || profile.role || ''}</span>
              {profile.employment_type && (
                <span className={classNames(
                  'rounded-full px-2 py-0.5 text-[10px] font-semibold border',
                  isFT
                    ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                    : 'bg-blue-500/15 text-blue-400 border-blue-500/30'
                )}>
                  {isFT ? 'Full-time' : 'Intern'}
                </span>
              )}
            </div>
          </div>
          <span className="text-[12px] text-zinc-500 shrink-0">
            {profile.docs_complete}/{profile.docs_total} docs
          </span>
        </div>

        {/* Detail grid — 4 columns matching portal */}
        <div className="grid grid-cols-4 gap-x-6 gap-y-3 px-5 py-4 border-b border-zinc-800">
          <DetailCell label="OFFICE EMAIL" value={profile.email} />
          <DetailCell label="REPORTING TO" value={profile.reporting_manager} />
          <DetailCell label="PROJECTS" value={profile.projects} />
          <DetailCell label="DESIGNATION" value={profile.designation} />
          <DetailCell label="JOINING DATE" value={profile.joining_date || '—'} />
          <DetailCell label="EMPLOYMENT" value={profile.employment_type || '—'} />
        </div>

        {/* Personal Details — 4-column inputs */}
        <div className="px-5 py-4 border-b border-zinc-800">
          <h3 className="text-[12px] font-bold text-zinc-300 uppercase tracking-wider mb-3">Personal Details</h3>
          <div className="grid grid-cols-4 gap-3">
            <FieldInput label="Personal Mobile" value={personalMobile} onChange={setPersonalMobile} />
            <FieldInput label="Personal Email" value={personalEmail} onChange={setPersonalEmail} />
            <FieldInput label="Emergency Contact Name" value={emergencyName} onChange={setEmergencyName} />
            <FieldInput label="Emergency Contact Number" value={emergencyNumber} onChange={setEmergencyNumber} />
          </div>
          <button
            onClick={handleSave}
            disabled={saving}
            className="mt-3 rounded-md bg-zinc-700 hover:bg-zinc-600 border border-zinc-600 px-4 py-1.5 text-[12px] font-medium text-zinc-200 transition-colors disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
        </div>

        {/* My Documents — grid of tiles matching portal */}
        <div className="px-5 py-4">
          <h3 className="text-[12px] font-bold text-zinc-300 uppercase tracking-wider mb-3">My Documents</h3>
          <div className="grid grid-cols-7 gap-2">
            {Object.entries(docs).map(([field, doc]) => {
              const uploaded = doc.uploaded
              return (
                <div
                  key={field}
                  className={classNames(
                    'rounded-lg border text-center px-2 py-3',
                    uploaded
                      ? 'border-emerald-500/40 bg-emerald-500/5'
                      : 'border-dashed border-zinc-700 bg-zinc-800/30'
                  )}
                >
                  <div className={classNames(
                    'text-[11px] font-semibold truncate',
                    uploaded ? 'text-emerald-400' : 'text-zinc-400'
                  )}>
                    {doc.label}
                  </div>
                  <div className={classNames(
                    'text-[10px] mt-0.5',
                    uploaded ? 'text-zinc-500' : 'text-zinc-600'
                  )}>
                    {uploaded ? 'Uploaded' : 'Missing'}
                  </div>
                  <div className="mt-2">
                    {uploaded ? (
                      canEditDocs ? (
                        <button
                          onClick={() => handleDeleteDoc(field)}
                          className="w-full rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-[10px] text-zinc-400 hover:text-red-400 hover:border-red-500/40 transition-colors"
                        >
                          Delete
                        </button>
                      ) : (
                        <span className="inline-block w-full rounded border border-amber-700/60 bg-amber-900/30 px-2 py-1 text-[10px] font-semibold text-amber-400 tracking-wide">
                          Locked
                        </span>
                      )
                    ) : (
                      <>
                        <input
                          type="file"
                          className="hidden"
                          accept=".pdf,.jpg,.jpeg,.png"
                          ref={(el) => { fileRefs.current[field] = el }}
                          onChange={(e) => {
                            const file = e.target.files?.[0]
                            if (file) handleUploadDoc(field, file)
                            e.target.value = ''
                          }}
                        />
                        <button
                          onClick={() => fileRefs.current[field]?.click()}
                          className="w-full rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-[10px] text-zinc-400 hover:text-blue-400 hover:border-blue-500/40 transition-colors"
                        >
                          Upload
                        </button>
                      </>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      </div>
    </div>
  )
}

function DetailCell({ label, value }: { label: string; value?: string }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wider text-zinc-500">{label}</div>
      <div className="text-[13px] text-zinc-200 mt-0.5">{value || '—'}</div>
    </div>
  )
}

function FieldInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="block text-[11px] text-zinc-500 mb-1">{label}</label>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-zinc-500 transition-colors"
        placeholder={label}
      />
    </div>
  )
}
