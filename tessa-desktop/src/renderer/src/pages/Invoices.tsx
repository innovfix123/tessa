/**
 * Invoices — sourced from portal.js renderInvoices() lines 4173-4371
 * Matches the web portal layout exactly (screenshot reference).
 * Single page: title + count, full-width date filters, action row, invoice list.
 * No reconciliation tab (only visible to accountant role in portal).
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { invoicesAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { TESSA_URL } from '@/lib/constants'
import toast from 'react-hot-toast'
import { FileText } from 'lucide-react'

/* ── Helpers ── */

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

/* ── Main Component ── */

export default function Invoices() {
  const [submissions, setSubmissions] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [isReviewer, setIsReviewer] = useState(false)
  const [canDownloadAll, setCanDownloadAll] = useState(false)

  // Filters
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  // Selection
  const [selected, setSelected] = useState<Set<number>>(new Set())

  // Upload modal
  const [showUpload, setShowUpload] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (dateFrom) params.from = dateFrom
      if (dateTo) params.to = dateTo
      const res = await invoicesAPI.list(params)
      const d = res.data || {}
      setSubmissions(d.submissions || [])
      setIsReviewer(d.isReviewer === true)
      setCanDownloadAll(d.canDownloadAll === true)
      setSelected(new Set())
    } catch {
      toast.error('Failed to load invoices')
    } finally {
      setLoading(false)
    }
  }, [dateFrom, dateTo])

  useEffect(() => { load() }, [load])

  // Filter action
  function handleFilter() { load() }
  function handleClear() { setDateFrom(''); setDateTo('') }

  // Select all
  function toggleSelectAll() {
    if (selected.size === submissions.length) {
      setSelected(new Set())
    } else {
      setSelected(new Set(submissions.map((s: any) => s.id)))
    }
  }

  function toggleSelect(id: number) {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id); else next.add(id)
      return next
    })
  }

  // Delete selected
  async function handleDeleteSelected() {
    const ids = Array.from(selected)
    if (!ids.length) { toast.error('Select at least one invoice.'); return }
    if (!confirm(`Delete ${ids.length} invoice(s)? This cannot be undone.`)) return
    try {
      await invoicesAPI.post({ action: 'delete', ids })
      toast.success('Deleted')
      load()
    } catch { toast.error('Delete failed') }
  }

  // Download selected
  function handleDownloadSelected() {
    const ids = Array.from(selected)
    if (!ids.length) { toast.error('Select at least one invoice.'); return }
    const isDev = import.meta.env.DEV
    const base = isDev ? '' : TESSA_URL
    window.open(`${base}/api/invoice-submissions/download-all?ids=${ids.join(',')}`, '_blank')
  }

  const title = isReviewer ? 'Invoice Collection' : 'My Invoices'

  if (loading) return <Loader label="Loading invoices..." />

  return (
    <div className="space-y-4">
      {/* Header: title + count */}
      <div className="flex items-center justify-between">
        <h2 className="text-[15px] font-semibold text-zinc-100">{title}</h2>
        {submissions.length > 0 && (
          <span className="rounded-md border border-zinc-700 bg-surface-2 px-3 py-1 text-[12px] text-zinc-400">
            {submissions.length} invoice{submissions.length !== 1 ? 's' : ''}
          </span>
        )}
      </div>

      {/* Filter card — full-width date inputs stacked, matching portal */}
      <div className="rounded-lg border border-zinc-800 bg-surface-2 px-5 py-4 space-y-3">
        <div>
          <label className="block text-[12px] text-zinc-500 mb-1">From</label>
          <input
            type="date"
            value={dateFrom}
            onChange={e => setDateFrom(e.target.value)}
            className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 transition-colors"
          />
        </div>
        <div>
          <label className="block text-[12px] text-zinc-500 mb-1">To</label>
          <input
            type="date"
            value={dateTo}
            onChange={e => setDateTo(e.target.value)}
            className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 transition-colors"
          />
        </div>

        {/* Action row: Filter, delete icon, Download Selected, + Submit Invoice */}
        <div className="flex items-center gap-2 flex-wrap">
          <button onClick={handleFilter} className="rounded-md bg-zinc-700 border border-zinc-600 px-4 py-1.5 text-[12px] font-medium text-zinc-200 hover:bg-zinc-600 transition-colors">
            Filter
          </button>
          {(dateFrom || dateTo) && (
            <button onClick={handleClear} className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:text-zinc-200 transition-colors">
              Clear
            </button>
          )}
          <button
            onClick={handleDeleteSelected}
            className="rounded-md border border-zinc-700 bg-zinc-800 p-1.5 text-zinc-500 hover:text-red-400 hover:border-red-500/40 transition-colors"
            title="Delete Selected"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
          {canDownloadAll && (
            <button onClick={handleDownloadSelected} className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:text-zinc-200 transition-colors">
              Download Selected
            </button>
          )}

          <div className="flex-1" />

          <button
            onClick={() => setShowUpload(true)}
            className="rounded-md bg-brand-600 hover:bg-brand-500 px-4 py-1.5 text-[12px] font-medium text-white transition-colors"
          >
            + Submit Invoice
          </button>
        </div>
      </div>

      {/* Invoice list */}
      {submissions.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <FileText className="h-10 w-10 text-zinc-700 mb-3" strokeWidth={1.5} />
          <div className="text-[13px] text-zinc-400">No invoices found</div>
          <div className="text-[11px] text-zinc-600 mt-1">Upload your first invoice using the button above</div>
        </div>
      ) : (
        <div className="rounded-lg border border-zinc-800 bg-surface-2">
          {/* Select all row */}
          <label className="flex items-center gap-3 px-5 py-3 border-b border-zinc-800 cursor-pointer hover:bg-zinc-800/30 transition-colors">
            <input
              type="checkbox"
              checked={selected.size === submissions.length && submissions.length > 0}
              onChange={toggleSelectAll}
              className="accent-brand-600"
            />
            <span className="text-[12px] text-zinc-400">Select all {submissions.length} invoices</span>
          </label>

          {/* Invoice rows */}
          {submissions.map((s: any) => {
            const dateStr = s.invoiceDate
              ? new Date(s.invoiceDate).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
              : ''
            const invNo = s.invoiceNumber || ('INV' + s.id)
            const displayName = [s.vendorName, s.amount, dateStr, invNo].filter(Boolean).join(' — ')
            const filePath = s.filePath
            const isDev = import.meta.env.DEV
            const fileUrl = filePath
              ? (filePath.startsWith('http') ? filePath : `${isDev ? '' : TESSA_URL}${filePath.startsWith('/') ? '' : '/'}${filePath}`)
              : null

            return (
              <div key={s.id} className="flex items-start gap-3 px-5 py-3 border-b border-zinc-800/60 last:border-b-0 hover:bg-zinc-800/20 transition-colors">
                <input
                  type="checkbox"
                  checked={selected.has(s.id)}
                  onChange={() => toggleSelect(s.id)}
                  className="accent-brand-600 mt-1 shrink-0"
                />
                <div className="min-w-0 flex-1">
                  {/* File link line: icon + vendor — amount — date — invoice# */}
                  <div className="flex items-center gap-2">
                    <FileText className="h-4 w-4 text-zinc-600 shrink-0" />
                    {fileUrl ? (
                      <a
                        href={fileUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[13px] text-zinc-200 hover:text-brand-400 transition-colors truncate"
                      >
                        {displayName}
                      </a>
                    ) : (
                      <span className="text-[13px] text-zinc-400">{displayName}</span>
                    )}
                  </div>
                  {/* Meta line: Uploaded by Name + notes */}
                  <div className="mt-0.5 text-[11px] text-zinc-500">
                    {isReviewer && s.userName && (
                      <span>Uploaded by <strong className="text-zinc-300">{s.userName}</strong></span>
                    )}
                    {s.notes && (
                      <span>{isReviewer && s.userName ? ' · ' : ''}{s.notes}</span>
                    )}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Upload Modal */}
      {showUpload && (
        <UploadModal
          onClose={() => setShowUpload(false)}
          onUploaded={() => { setShowUpload(false); load() }}
        />
      )}
    </div>
  )
}

/* ── Upload Modal — sourced from portal.js showInvoiceUploadModal() lines 4293-4371 ── */

function UploadModal({ onClose, onUploaded }: { onClose: () => void; onUploaded: () => void }) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [dragOver, setDragOver] = useState(false)

  function handleFile(f: File) {
    if (f.size > 10 * 1024 * 1024) { toast.error('File too large (max 10MB)'); return }
    setFile(f)
  }

  async function handleSubmit() {
    if (!file) { toast.error('Please select a file.'); return }
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('action', 'submit')
      fd.append('file', file)
      await invoicesAPI.create(fd)
      toast.success('Invoice uploaded')
      onUploaded()
    } catch (err: any) {
      const data = err?.response?.data
      let msg = 'Upload failed'
      if (data?.errors) {
        const first = Object.keys(data.errors)[0]
        if (first) msg = data.errors[first].join(', ')
      } else if (data?.message) msg = data.message
      toast.error(msg)
      setUploading(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="w-full max-w-md rounded-lg border border-zinc-800 bg-surface-1 shadow-xl">
        <div className="flex items-center justify-between border-b border-zinc-800 px-5 py-3">
          <h3 className="text-[14px] font-semibold text-zinc-100">Submit Invoice</h3>
          <button onClick={onClose} className="text-zinc-500 hover:text-zinc-300 text-lg leading-none">&times;</button>
        </div>

        <div className="px-5 py-4 space-y-3">
          <label className="block text-[12px] text-zinc-400 mb-1">Invoice File (PDF, JPG, PNG)</label>

          {/* Dropzone */}
          <div
            className={`flex flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 cursor-pointer transition-colors ${
              dragOver ? 'border-brand-500 bg-brand-600/5' : 'border-zinc-700 bg-zinc-800/30 hover:border-zinc-600'
            }`}
            onClick={() => fileRef.current?.click()}
            onDragOver={e => { e.preventDefault(); setDragOver(true) }}
            onDragLeave={() => setDragOver(false)}
            onDrop={e => { e.preventDefault(); setDragOver(false); if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]) }}
          >
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-zinc-600 mb-2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" />
            </svg>
            <div className="text-[12px] text-zinc-400">
              Drop invoice here or <span className="text-brand-400 underline">click to browse</span>
            </div>
            <div className="text-[10px] text-zinc-600 mt-1">PDF, JPG, PNG, WEBP — max 10MB</div>
            <input
              type="file"
              ref={fileRef}
              className="hidden"
              accept=".pdf,.jpg,.jpeg,.png,.webp"
              onChange={e => { if (e.target.files?.[0]) handleFile(e.target.files[0]); e.target.value = '' }}
            />
          </div>

          {/* File preview */}
          {file && (
            <div className="text-[12px] text-zinc-300">
              {file.name} ({formatFileSize(file.size)})
            </div>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-zinc-800 px-5 py-3">
          <button onClick={onClose} className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:bg-zinc-700 transition-colors">
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={uploading}
            className="rounded-md bg-brand-600 px-4 py-1.5 text-[12px] font-medium text-white hover:bg-brand-500 disabled:opacity-50 transition-colors"
          >
            {uploading ? 'Uploading & extracting...' : 'Upload'}
          </button>
        </div>
      </div>
    </div>
  )
}
