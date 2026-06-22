import { useState, useEffect, useCallback } from 'react'
import { creativeUploadsAPI } from '@/api/client'
import { escapeHtml } from '@/lib/utils'
import { X, Pencil, Trash2 } from 'lucide-react'
import toast from 'react-hot-toast'

interface Upload {
  id: number; file_name: string; content?: string; uploaded_by_name?: string
}

interface Props {
  userId: string; fieldKey: string; fieldLabel: string; reportDate: string
  onClose: () => void; onChanged: () => void
}

export default function ScriptPanel({ userId, fieldKey, fieldLabel, reportDate, onClose, onChanged }: Props): JSX.Element {
  const [scripts, setScripts] = useState<Upload[]>([])
  const [loading, setLoading] = useState(true)
  const [content, setContent] = useState('')
  const [editId, setEditId] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)

  const dateLabel = (() => {
    try {
      return new Date(reportDate + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })
    } catch { return reportDate }
  })()

  const loadScripts = useCallback(async () => {
    setLoading(true)
    try {
      const res = await creativeUploadsAPI.list({ user_id: userId, report_date: reportDate, field_key: fieldKey })
      setScripts(res.data?.uploads || [])
    } catch { toast.error('Failed to load scripts') }
    finally { setLoading(false) }
  }, [userId, reportDate, fieldKey])

  useEffect(() => { loadScripts() }, [loadScripts])

  async function handleSave() {
    if (!content.trim()) { toast.error('Please enter script content'); return }
    setSaving(true)
    try {
      if (editId) {
        await creativeUploadsAPI.post({ action: 'delete', id: editId })
      }
      await creativeUploadsAPI.post({
        action: 'save_text', user_id: userId, field_key: fieldKey, report_date: reportDate, content: content.trim()
      })
      setContent('')
      setEditId(null)
      toast.success('Script saved')
      loadScripts()
      onChanged()
    } catch { toast.error('Save failed') }
    finally { setSaving(false) }
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this script?')) return
    try {
      await creativeUploadsAPI.post({ action: 'delete', id })
      loadScripts()
      onChanged()
    } catch { toast.error('Delete failed') }
  }

  function handleEdit(u: Upload) {
    setContent(u.content || '')
    setEditId(u.id)
  }

  return (
    <div className="rounded-lg border border-zinc-800 bg-surface-1 p-4 mt-3">
      <div className="flex items-center justify-between mb-3">
        <div className="text-sm font-medium text-zinc-200">{fieldLabel} — {dateLabel}</div>
        <button onClick={onClose} className="btn-icon p-1"><X className="h-4 w-4" /></button>
      </div>

      {/* Compose area — large textarea with Save button on right */}
      <div className="mb-4">
        <textarea
          value={content}
          onChange={(e) => setContent(e.target.value)}
          rows={8}
          placeholder="Paste or type your script here..."
          className="textarea-field text-sm mb-2"
        />
        <div className="flex items-center justify-end gap-2">
          {editId && (
            <button onClick={() => { setEditId(null); setContent('') }} className="btn-ghost text-xs">Cancel</button>
          )}
          <button onClick={handleSave} disabled={saving} className="btn-primary text-sm px-5">
            {saving ? (editId ? 'Updating...' : 'Saving...') : (editId ? 'Update Script' : 'Save Script')}
          </button>
        </div>
      </div>

      {/* Script cards — horizontal grid matching the portal layout */}
      {loading ? (
        <div className="text-xs text-zinc-500">Loading scripts...</div>
      ) : scripts.length === 0 ? (
        <div className="text-xs text-zinc-600 text-center py-4">No scripts yet. Paste your script above and save.</div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {scripts.map((u) => (
            <div key={u.id} className="rounded-md border border-zinc-800 bg-surface-3 p-3 flex flex-col">
              {/* Header: "by Name" + Edit + Delete buttons */}
              <div className="flex items-center gap-2 mb-2">
                {u.uploaded_by_name && (
                  <span className="text-xs text-zinc-400">by {u.uploaded_by_name}</span>
                )}
                <button onClick={() => handleEdit(u)}
                  className="text-[11px] text-zinc-500 border border-zinc-700 rounded px-2 py-0.5 hover:text-zinc-200 hover:border-zinc-600">
                  Edit
                </button>
                <button onClick={() => handleDelete(u.id)}
                  className="text-[11px] text-zinc-500 border border-zinc-700 rounded px-2 py-0.5 hover:text-red-400 hover:border-red-500/30">
                  Delete
                </button>
              </div>
              {/* Content preview */}
              <div className="text-xs text-zinc-400 whitespace-pre-wrap flex-1 leading-relaxed">
                {u.content ? (u.content.length > 300 ? u.content.slice(0, 300) + '...' : u.content) : ''}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
