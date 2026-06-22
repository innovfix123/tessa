/**
 * Google Ads Reports — sourced from portal.js renderGoogleAds() lines 3699-4025
 * Matches the web portal screenshot exactly.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { googleAdsAPI } from '@/api/client'
import { Modal, Loader, EmptyState } from '@/components/ui'
import type { GoogleAdRow, AdCoverageDay, AdSummary } from '@/lib/types'
import { classNames } from '@/lib/utils'
import toast from 'react-hot-toast'
import { BarChart3, Upload, X, ChevronLeft, ChevronRight, FileSpreadsheet } from 'lucide-react'

const fmtINR = (n: number | null | undefined): string =>
  n == null ? '—' : Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })

const PAGE_SIZES = [25, 50, 100] as const

export default function GoogleAds() {
  const [reports, setReports] = useState<GoogleAdRow[]>([])
  const [summary, setSummary] = useState<AdSummary | null>(null)
  const [projects, setProjects] = useState<Record<string, string>>({})
  const [coverage, setCoverage] = useState<Record<string, AdCoverageDay[]>>({})
  const [loading, setLoading] = useState(true)

  const [activeProject, setActiveProject] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [campaignSearch, setCampaignSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [uploadOpen, setUploadOpen] = useState(false)
  const [uploadPreselect, setUploadPreselect] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (activeProject) params.project = activeProject
      if (dateFrom) params.from = dateFrom
      if (dateTo) params.to = dateTo
      if (campaignSearch) params.campaign = campaignSearch
      const res = await googleAdsAPI.list(params)
      const d = res.data || {}
      setReports(d.reports || [])
      setSummary(d.summary || null)
      setProjects(d.projects || {})
      setCoverage(d.coverage || {})
    } catch {
      toast.error('Failed to load Google Ads data')
    } finally {
      setLoading(false)
    }
  }, [activeProject, dateFrom, dateTo, campaignSearch])

  useEffect(() => { load() }, [load])

  const totalRows = reports.length
  const totalPages = Math.max(1, Math.ceil(totalRows / perPage))
  const startIdx = (page - 1) * perPage
  const pageRows = reports.slice(startIdx, startIdx + perPage)
  const projectKeys = Object.keys(projects)

  if (loading && !reports.length) return <Loader label="Loading Google Ads data..." />

  return (
    <div className="space-y-4">
      {/* ── Header: title + row count badge ── */}
      <div className="flex items-center gap-3">
        <h2 className="text-[17px] font-bold text-zinc-100">Google Ads Reports</h2>
        <span className="rounded-md border border-zinc-700 bg-surface-2 px-2.5 py-0.5 text-[11px] text-zinc-400">
          {totalRows} rows
        </span>
      </div>

      {/* ── Project tabs — rounded pill buttons ── */}
      <div className="flex gap-2">
        <button
          onClick={() => { setActiveProject(''); setPage(1) }}
          className={classNames(
            'rounded-full px-4 py-1.5 text-[12px] font-medium transition-colors',
            !activeProject ? 'bg-red-600 text-white' : 'bg-zinc-800 text-zinc-400 hover:text-zinc-200 border border-zinc-700'
          )}
        >
          All
        </button>
        {projectKeys.map(k => (
          <button
            key={k}
            onClick={() => { setActiveProject(k); setPage(1) }}
            className={classNames(
              'rounded-full px-4 py-1.5 text-[12px] font-medium transition-colors',
              activeProject === k ? 'bg-red-600 text-white' : 'bg-zinc-800 text-zinc-400 hover:text-zinc-200 border border-zinc-700'
            )}
          >
            {projects[k]}
          </button>
        ))}
      </div>

      {/* ── Upload Tracker — per project, 7-day tiles ── */}
      {projectKeys.length > 0 && (
        <div className="rounded-lg border border-zinc-800 bg-surface-2 px-5 py-4">
          {/* Header row */}
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-baseline gap-2">
              <span className="text-[13px] font-semibold text-zinc-200">Upload Tracker</span>
              <span className="text-[11px] text-zinc-500">(last 7 days)</span>
            </div>
            <div className="flex items-center gap-4 text-[11px]">
              <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-emerald-500" /> Uploaded</span>
              <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-red-500" /> Missing</span>
            </div>
          </div>

          {/* Per-project rows */}
          {projectKeys.map(proj => {
            const days = coverage[proj] || []
            const upCount = days.filter(c => c.uploaded).length
            return (
              <div key={proj} className="mb-4 last:mb-0">
                <div className="flex items-baseline gap-2 mb-2">
                  <span className="text-[13px] font-semibold text-zinc-200">{projects[proj]}</span>
                  <span className="text-[11px] text-zinc-500">{upCount}/7</span>
                </div>
                <div className="flex gap-1.5">
                  {days.map(c => {
                    const dateObj = new Date(c.date + 'T00:00:00')
                    const dateLabel = dateObj.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
                    const uploaded = c.uploaded
                    return (
                      <button
                        key={c.date}
                        onClick={() => {
                          if (uploaded) {
                            setActiveProject(proj)
                            setDateFrom(c.date)
                            setDateTo(c.date)
                            setPage(1)
                          } else {
                            setUploadPreselect(proj)
                            setUploadOpen(true)
                          }
                        }}
                        className={classNames(
                          'flex flex-col items-center justify-center rounded-lg px-2 py-1.5 min-w-[56px] text-[10px] transition-colors border',
                          uploaded
                            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20'
                            : 'bg-red-500/10 border-red-500/30 text-red-400 hover:bg-red-500/20'
                        )}
                        title={uploaded
                          ? `${c.date}: ${c.rows || 0} rows, Spend: ${fmtINR(c.spend ?? 0)}`
                          : `${c.date}: Missing — click to upload`
                        }
                      >
                        <span className="font-semibold">{c.day.substring(0, 2)}</span>
                        <span className="opacity-70">{dateLabel}</span>
                        {uploaded ? (
                          <span className="font-medium mt-0.5">{fmtINR(c.spend ?? 0)}</span>
                        ) : (
                          <span className="font-bold text-sm mt-0.5">!</span>
                        )}
                      </button>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* ── Summary cards — 3 in a row, full width like portal ── */}
      {summary && (
        <div className="grid grid-cols-3 gap-3">
          <div className="rounded-lg border border-zinc-800 bg-surface-2 py-4 text-center">
            <div className="text-[20px] font-bold text-zinc-100">{fmtINR(summary.total_spend)}</div>
            <div className="text-[10px] uppercase tracking-wider text-zinc-500 mt-1">Total Spend (INR)</div>
          </div>
          <div className="rounded-lg border border-zinc-800 bg-surface-2 py-4 text-center">
            <div className="text-[20px] font-bold text-zinc-100">{fmtINR(summary.total_purchases ?? 0)}</div>
            <div className="text-[10px] uppercase tracking-wider text-zinc-500 mt-1">Purchases</div>
          </div>
          <div className="rounded-lg border border-zinc-800 bg-surface-2 py-4 text-center">
            <div className="text-[20px] font-bold text-zinc-100">{fmtINR(summary.total_purchase_value ?? 0)}</div>
            <div className="text-[10px] uppercase tracking-wider text-zinc-500 mt-1">Purchase Value</div>
          </div>
        </div>
      )}

      {/* ── Filters — inline row: From, To, Search, Filter, + Upload CSV ── */}
      <div className="flex items-center gap-3 flex-wrap">
        <span className="text-[12px] text-zinc-500">From</span>
        <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)}
          className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 w-36" />
        <span className="text-[12px] text-zinc-500">To</span>
        <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)}
          className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500 w-36" />
        <input type="text" value={campaignSearch} onChange={e => setCampaignSearch(e.target.value)} placeholder="Search campaign..."
          className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-zinc-500 w-44" />
        <button onClick={() => { setPage(1); load() }}
          className="rounded-md bg-zinc-700 border border-zinc-600 px-4 py-1.5 text-[12px] font-medium text-zinc-200 hover:bg-zinc-600 transition-colors">
          Filter
        </button>
        {(dateFrom || dateTo || campaignSearch) && (
          <button onClick={() => { setDateFrom(''); setDateTo(''); setCampaignSearch(''); setActiveProject(''); setPage(1) }}
            className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:text-zinc-200 transition-colors">
            Clear
          </button>
        )}
        <div className="flex-1" />
        <button onClick={() => { setUploadPreselect(''); setUploadOpen(true) }}
          className="rounded-md bg-brand-600 hover:bg-brand-500 px-4 py-1.5 text-[12px] font-medium text-white transition-colors">
          + Upload CSV
        </button>
      </div>

      {/* ── Data Table ── */}
      {reports.length === 0 ? (
        <EmptyState icon={BarChart3} title="No Google Ads data" description="Upload a CSV export from Google Ads." />
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border border-zinc-800">
            <table className="w-full text-[12px]" style={{ borderCollapse: 'collapse' }}>
              <thead>
                <tr className="bg-zinc-800/80 text-[10px] uppercase tracking-wider">
                  <th className="px-3 py-2.5 text-left font-semibold text-zinc-400">Date</th>
                  <th className="px-3 py-2.5 text-left font-semibold text-zinc-400">Campaign</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">Cost</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">Avg CPC</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CTR%</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CPI</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CPR</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CPFTD</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CP D1MP</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">Purchases</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">CPP</th>
                  <th className="px-3 py-2.5 text-right font-semibold text-zinc-400">Purch. Value</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/50">
                {pageRows.map(row => (
                  <tr key={row.id} className="hover:bg-zinc-800/30 transition-colors">
                    <td className="px-3 py-2 text-zinc-300 whitespace-nowrap">{row.reporting_date}</td>
                    <td className="px-3 py-2 text-zinc-200 max-w-[240px] truncate" title={row.campaign_name}>{row.campaign_name}</td>
                    <td className="px-3 py-2 text-right text-zinc-200 whitespace-nowrap">{fmtINR(row.cost)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.avg_cpc)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{row.ctr != null ? Number(row.ctr).toFixed(2) + '%' : '—'}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.cpi)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.cpr)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.cpftd)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.cp_d1mp)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.purchases)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.cpp)}</td>
                    <td className="px-3 py-2 text-right text-zinc-300 whitespace-nowrap">{fmtINR(row.purchase_value)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* ── Pagination ── */}
          {totalRows > perPage && (
            <div className="flex items-center justify-between text-[12px] text-zinc-500">
              <span>Showing {startIdx + 1}–{Math.min(startIdx + perPage, totalRows)} of {totalRows}</span>
              <div className="flex items-center gap-1">
                <button onClick={() => setPage(1)} disabled={page <= 1} className="px-2 py-1 rounded border border-zinc-700 bg-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30">&laquo;</button>
                <button onClick={() => setPage(p => p - 1)} disabled={page <= 1} className="px-2 py-1 rounded border border-zinc-700 bg-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30">&lsaquo;</button>
                {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                  const start = Math.max(1, Math.min(page - 2, totalPages - 4))
                  const p = start + i
                  if (p > totalPages) return null
                  return (
                    <button key={p} onClick={() => setPage(p)}
                      className={classNames('px-2.5 py-1 rounded border text-zinc-400 hover:text-zinc-200',
                        p === page ? 'bg-brand-600 border-brand-600 text-white' : 'border-zinc-700 bg-zinc-800')}>
                      {p}
                    </button>
                  )
                })}
                <button onClick={() => setPage(p => p + 1)} disabled={page >= totalPages} className="px-2 py-1 rounded border border-zinc-700 bg-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30">&rsaquo;</button>
                <button onClick={() => setPage(totalPages)} disabled={page >= totalPages} className="px-2 py-1 rounded border border-zinc-700 bg-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30">&raquo;</button>
                <select value={perPage} onChange={e => { setPerPage(Number(e.target.value)); setPage(1) }}
                  className="ml-2 rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-[11px] text-zinc-400 focus:outline-none">
                  {PAGE_SIZES.map(n => <option key={n} value={n}>{n}/page</option>)}
                </select>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── Upload Modal ── */}
      {uploadOpen && (
        <UploadModal
          preselect={uploadPreselect}
          projects={projects}
          onClose={() => setUploadOpen(false)}
          onUploaded={() => { setUploadOpen(false); load() }}
        />
      )}
    </div>
  )
}

/* ── Upload Modal — sourced from portal.js showGoogleAdsUploadModal() ── */

function UploadModal({ preselect, projects, onClose, onUploaded }: {
  preselect: string; projects: Record<string, string>; onClose: () => void; onUploaded: () => void
}) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [proj, setProj] = useState(preselect || 'hima')
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [dragOver, setDragOver] = useState(false)

  async function handleSubmit() {
    if (!proj) { toast.error('Select a project.'); return }
    if (!file) { toast.error('Select a CSV file.'); return }
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('action', 'upload')
      fd.append('project', proj)
      fd.append('file', file)
      const res = await googleAdsAPI.upload(fd)
      const d = res.data || {}
      let msg = `Imported ${d.inserted || 0} rows.`
      if (d.skipped > 0) msg += ` Skipped ${d.skipped} duplicates.`
      toast.success(msg)
      onUploaded()
    } catch {
      toast.error('Upload failed. Please check your CSV.')
      setUploading(false)
    }
  }

  const projectKeys = Object.keys(projects)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" onClick={e => { if (e.target === e.currentTarget) onClose() }}>
      <div className="w-full max-w-md rounded-lg border border-zinc-800 bg-surface-1 shadow-xl">
        <div className="flex items-center justify-between border-b border-zinc-800 px-5 py-3">
          <h3 className="text-[14px] font-semibold text-zinc-100">Upload Google Ads CSV</h3>
          <button onClick={onClose} className="text-zinc-500 hover:text-zinc-300 text-lg leading-none">&times;</button>
        </div>
        <div className="px-5 py-4 space-y-3">
          <div>
            <label className="block text-[12px] text-zinc-400 mb-1">Project</label>
            <select value={proj} onChange={e => setProj(e.target.value)}
              className="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[13px] text-zinc-100 focus:outline-none focus:border-zinc-500">
              <option value="">-- Select Project --</option>
              {projectKeys.map(k => (
                <option key={k} value={k}>{projects[k] || k.charAt(0).toUpperCase() + k.slice(1)}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-[12px] text-zinc-400 mb-1">CSV File (exported from Google Ads)</label>
            <div
              className={classNames(
                'flex flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 cursor-pointer transition-colors',
                dragOver ? 'border-brand-500 bg-brand-600/5' : 'border-zinc-700 bg-zinc-800/30 hover:border-zinc-600'
              )}
              onClick={() => fileRef.current?.click()}
              onDragOver={e => { e.preventDefault(); setDragOver(true) }}
              onDragLeave={() => setDragOver(false)}
              onDrop={e => { e.preventDefault(); setDragOver(false); if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]) }}
            >
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-zinc-600 mb-2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <div className="text-[12px] text-zinc-400">Drop CSV here or <span className="text-brand-400 underline">click to browse</span></div>
              <div className="text-[10px] text-zinc-600 mt-1">CSV or TXT — max 20MB</div>
              <input type="file" ref={fileRef} className="hidden" accept=".csv,.txt" onChange={e => { if (e.target.files?.[0]) setFile(e.target.files[0]) }} />
            </div>
            {file && <div className="mt-2 text-[12px] text-zinc-300">{file.name} ({(file.size / 1024).toFixed(1)} KB)</div>}
          </div>
        </div>
        <div className="flex items-center justify-end gap-2 border-t border-zinc-800 px-5 py-3">
          <button onClick={onClose} className="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-[12px] text-zinc-400 hover:bg-zinc-700 transition-colors">Cancel</button>
          <button onClick={handleSubmit} disabled={uploading}
            className="rounded-md bg-brand-600 px-4 py-1.5 text-[12px] font-medium text-white hover:bg-brand-500 disabled:opacity-50 transition-colors">
            {uploading ? 'Uploading & importing...' : 'Upload & Import'}
          </button>
        </div>
      </div>
    </div>
  )
}
