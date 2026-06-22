import { useState, useEffect, useCallback, useRef } from 'react'
import { creativeUploadsAPI } from '@/api/client'
import { formatFileSize } from '@/lib/utils'
import { X, Trash2, Upload, Play } from 'lucide-react'
import toast from 'react-hot-toast'

interface FileUpload {
  id: number; file_name: string; file_path?: string; file_size: number
  file_type?: string; uploaded_by_name?: string
}

interface Props {
  userId: string; fieldKey: string; fieldLabel: string; reportDate: string
  acceptAttr?: string; maxMb?: number
  onClose: () => void; onChanged: () => void
}

const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif']
const VIDEO_EXTS = ['mp4', 'mov', 'avi', 'mkv', 'webm']

export default function UploadPanel({ userId, fieldKey, fieldLabel, reportDate, acceptAttr, maxMb = 10, onClose, onChanged }: Props): JSX.Element {
  const [files, setFiles] = useState<FileUpload[]>([])
  const [loading, setLoading] = useState(true)
  const [dragging, setDragging] = useState(false)
  const [uploading, setUploading] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  const dateLabel = (() => {
    try {
      return new Date(reportDate + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })
    } catch { return reportDate }
  })()

  const acceptDisplay = (acceptAttr || '').replace(/\./g, '').toUpperCase().replace(/,/g, ', ')

  const loadFiles = useCallback(async () => {
    setLoading(true)
    try {
      const res = await creativeUploadsAPI.list({ user_id: userId, report_date: reportDate, field_key: fieldKey })
      setFiles(res.data?.uploads || [])
    } catch { toast.error('Failed to load uploads') }
    finally { setLoading(false) }
  }, [userId, reportDate, fieldKey])

  useEffect(() => { loadFiles() }, [loadFiles])

  async function uploadFiles(fileList: FileList) {
    setUploading(true)
    const errors: string[] = []
    for (let i = 0; i < fileList.length; i++) {
      const file = fileList[i]
      if (file.size > maxMb * 1024 * 1024) {
        errors.push(`${file.name} (too large)`)
        continue
      }
      const fd = new FormData()
      fd.append('action', 'upload')
      fd.append('user_id', userId)
      fd.append('field_key', fieldKey)
      fd.append('report_date', reportDate)
      fd.append('file', file)
      try {
        await creativeUploadsAPI.upload(fd)
      } catch {
        errors.push(`${file.name}: upload failed`)
      }
    }
    if (errors.length) toast.error('Some files failed:\n' + errors.join('\n'))
    setUploading(false)
    loadFiles()
    onChanged()
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this file?')) return
    try {
      await creativeUploadsAPI.post({ action: 'delete', id })
      loadFiles()
      onChanged()
    } catch { toast.error('Delete failed') }
  }

  function thumbHtml(u: FileUpload) {
    const ext = (u.file_type || '').toLowerCase()
    if (IMAGE_EXTS.includes(ext) && u.file_path) {
      return <img src={u.file_path} alt="" className="h-12 w-12 object-cover rounded" />
    }
    if (VIDEO_EXTS.includes(ext)) {
      return <div className="h-12 w-12 rounded bg-zinc-800 flex items-center justify-center"><Play className="h-4 w-4 text-zinc-400" /><span className="text-[8px] text-zinc-500 ml-0.5">{ext.toUpperCase()}</span></div>
    }
    return <div className="h-12 w-12 rounded bg-zinc-800 flex items-center justify-center text-[10px] text-zinc-500 font-bold">{ext.toUpperCase() || '?'}</div>
  }

  return (
    <div className="rounded-lg border border-zinc-800 bg-surface-1 p-4 mt-3">
      <div className="flex items-center justify-between mb-3">
        <div className="text-sm font-medium text-zinc-200">{fieldLabel} — {dateLabel}</div>
        <button onClick={onClose} className="btn-icon p-1"><X className="h-4 w-4" /></button>
      </div>

      {/* Dropzone */}
      <div
        onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => { e.preventDefault(); setDragging(false); if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files) }}
        onClick={() => inputRef.current?.click()}
        className={`rounded-lg border-2 border-dashed p-6 text-center cursor-pointer transition-colors mb-3 ${
          dragging ? 'border-brand-500 bg-brand-500/5' : 'border-zinc-700 hover:border-zinc-600'
        }`}
      >
        <Upload className="h-6 w-6 text-zinc-600 mx-auto mb-2" />
        <div className="text-xs text-zinc-400">Drop files here or <span className="text-brand-400 font-medium">click to browse</span></div>
        <div className="text-[10px] text-zinc-600 mt-1">Accepted: {acceptDisplay || 'All files'} (max {maxMb}MB)</div>
        <input ref={inputRef} type="file" multiple accept={acceptAttr} className="hidden"
          onChange={(e) => { if (e.target.files?.length) uploadFiles(e.target.files); e.target.value = '' }} />
      </div>

      {uploading && <div className="text-xs text-brand-400 mb-2">Uploading...</div>}

      {/* File grid */}
      {loading ? (
        <div className="text-xs text-zinc-500">Loading uploads...</div>
      ) : files.length === 0 ? (
        <div className="text-xs text-zinc-600 text-center py-4">No files uploaded yet. Drop files above to get started.</div>
      ) : (
        <div className="space-y-2">
          {files.map((u) => (
            <div key={u.id} className="flex items-center gap-3 rounded-md border border-zinc-800 bg-surface-3 p-2">
              {u.file_path ? (
                <a href={u.file_path} target="_blank" rel="noreferrer" className="shrink-0">{thumbHtml(u)}</a>
              ) : (
                <div className="shrink-0">{thumbHtml(u)}</div>
              )}
              <div className="flex-1 min-w-0">
                {u.file_path ? (
                  <a href={u.file_path} target="_blank" rel="noreferrer" className="text-xs text-zinc-300 hover:text-brand-400 truncate block">{u.file_name}</a>
                ) : (
                  <span className="text-xs text-zinc-300 truncate block">{u.file_name}</span>
                )}
                <span className="text-[10px] text-zinc-600">
                  {formatFileSize(u.file_size)}{u.uploaded_by_name ? ` · by ${u.uploaded_by_name}` : ''}
                </span>
              </div>
              <button onClick={() => handleDelete(u.id)} className="p-1.5 text-zinc-600 hover:text-red-400 rounded shrink-0" title="Delete">
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
