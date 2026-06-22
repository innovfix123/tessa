import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { metaAdsAPI } from '@/api/client'
import { Modal, Loader, EmptyState } from '@/components/ui'
import type { MetaAdRow, AdCoverageDay, AdSummary } from '@/lib/types'
import { classNames, formatNum } from '@/lib/utils'
import toast from 'react-hot-toast'
import { BarChart3, Upload, FileSpreadsheet, X } from 'lucide-react'

// ── Helpers ──

function fmtINR(n: number | null | undefined): string {
  if (n == null || isNaN(Number(n))) return '\u2014'
  return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })
}

function todayStr(): string {
  const d = new Date()
  const y = d.getFullYear()
  const m = d.getMonth() + 1
  const day = d.getDate()
  return `${y}-${m < 10 ? '0' : ''}${m}-${day < 10 ? '0' : ''}${day}`
}

const DAY_ABBR = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']

// ── Component ──

export default function MetaAds(): JSX.Element {
  // Data state
  const [reports, setReports] = useState<MetaAdRow[]>([])
  const [summary, setSummary] = useState<AdSummary | null>(null)
  const [projects, setProjects] = useState<Record<string, string>>({})
  const [coverage, setCoverage] = useState<Record<string, AdCoverageDay[]>>({})
  const [loading, setLoading] = useState(true)

  // Filter state
  const [activeProject, setActiveProject] = useState<string>('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [campaignSearch, setCampaignSearch] = useState('')
  const [appliedFilters, setAppliedFilters] = useState<{
    project: string; from: string; to: string; campaign: string
  }>({ project: '', from: '', to: '', campaign: '' })

  // Pagination state
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  // Upload modal state
  const [uploadOpen, setUploadOpen] = useState(false)
  const [uploadProject, setUploadProject] = useState('')
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const dropRef = useRef<HTMLDivElement>(null)

  // Dynamic project options from API (no hardcoded fallback)
  const projectOptions = useMemo(
    () => Object.entries(projects).map(([key, label]) => ({ key, label: String(label) })),
    [projects]
  )

  // Set default upload project when projects load
  useEffect(() => {
    if (!uploadProject && projectOptions.length > 0) {
      setUploadProject(projectOptions[0].key)
    }
  }, [projectOptions, uploadProject])

  // ── Fetch data ──

  const fetchData = useCallback(async (
    proj?: string, from?: string, to?: string, campaign?: string
  ) => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (proj) params.project = proj
      if (from) params.from = from
      if (to) params.to = to
      if (campaign) params.campaign = campaign

      const res = await metaAdsAPI.list(params)
      const d = res.data
      setReports(d.reports || [])
      setSummary(d.summary || null)
      setProjects(d.projects || {})
      setCoverage(d.coverage || {})
      setPage(1)
    } catch {
      toast.error('Failed to load Meta Ad reports')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchData()
  }, [fetchData])

  // ── Filter handlers ──

  function handleFilter() {
    const f = { project: activeProject, from: fromDate, to: toDate, campaign: campaignSearch }
    setAppliedFilters(f)
    fetchData(f.project, f.from, f.to, f.campaign)
  }

  function handleClear() {
    setActiveProject('')
    setFromDate('')
    setToDate('')
    setCampaignSearch('')
    setAppliedFilters({ project: '', from: '', to: '', campaign: '' })
    fetchData()
  }

  function handleProjectTab(key: string) {
    setActiveProject(key)
    const f = { project: key, from: fromDate, to: toDate, campaign: campaignSearch }
    setAppliedFilters(f)
    fetchData(f.project, f.from, f.to, f.campaign)
  }

  function handleCoverageTileClick(projKey: string, day: AdCoverageDay) {
    if (day.uploaded) {
      // Filter to that date + project
      setActiveProject(projKey)
      setFromDate(day.date)
      setToDate(day.date)
      const f = { project: projKey, from: day.date, to: day.date, campaign: '' }
      setCampaignSearch('')
      setAppliedFilters(f)
      fetchData(f.project, f.from, f.to, f.campaign)
    } else {
      // Open upload modal with that project pre-selected
      setUploadProject(projKey)
      setUploadFile(null)
      setUploadOpen(true)
    }
  }

  const hasFilters = appliedFilters.project || appliedFilters.from || appliedFilters.to || appliedFilters.campaign

  // ── Upload handlers ──

  function handleFileDrop(e: React.DragEvent) {
    e.preventDefault()
    e.stopPropagation()
    const f = e.dataTransfer.files?.[0]
    if (f && f.name.endsWith('.csv')) {
      setUploadFile(f)
    } else {
      toast.error('Please drop a CSV file')
    }
  }

  function handleFileSelect(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0]
    if (f) setUploadFile(f)
  }

  async function handleUpload() {
    if (!uploadFile) {
      toast.error('Please select a CSV file')
      return
    }
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('action', 'upload')
      fd.append('project', uploadProject)
      fd.append('file', uploadFile)
      await metaAdsAPI.upload(fd)
      toast.success('CSV uploaded and imported successfully')
      setUploadOpen(false)
      setUploadFile(null)
      fetchData(appliedFilters.project, appliedFilters.from, appliedFilters.to, appliedFilters.campaign)
    } catch (err: any) {
      toast.error(err?.response?.data?.message || 'Upload failed')
    } finally {
      setUploading(false)
    }
  }

  // ── Pagination ──

  const totalRows = reports.length
  const totalPages = Math.max(1, Math.ceil(totalRows / perPage))
  const safePage = Math.min(page, totalPages)
  const startIdx = (safePage - 1) * perPage
  const endIdx = Math.min(startIdx + perPage, totalRows)
  const pageRows = reports.slice(startIdx, endIdx)

  function buildPageNumbers(): (number | '...')[] {
    const pages: (number | '...')[] = []
    const delta = 2
    const left = Math.max(1, safePage - delta)
    const right = Math.min(totalPages, safePage + delta)
    if (left > 1) {
      pages.push(1)
      if (left > 2) pages.push('...')
    }
    for (let i = left; i <= right; i++) pages.push(i)
    if (right < totalPages) {
      if (right < totalPages - 1) pages.push('...')
      pages.push(totalPages)
    }
    return pages
  }

  // ── Render ──

  if (loading && reports.length === 0) {
    return <Loader label="Loading Meta Ads Reports..." />
  }

  const projectKeys = Object.keys(projects)

  return (
    <div className="space-y-5">
      {/* ── Header ── */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-zinc-100">
          Meta Ads Reports
          {totalRows > 0 && (
            <span className="ml-2 text-sm font-normal text-zinc-500">
              ({totalRows.toLocaleString()} rows)
            </span>
          )}
        </h2>
      </div>

      {/* ── Project Tabs ── */}
      {projectKeys.length > 0 && (
        <div className="flex flex-wrap gap-2">
          <button
            onClick={() => handleProjectTab('')}
            className={classNames(
              'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
              activeProject === ''
                ? 'bg-brand-600 text-white'
                : 'bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200'
            )}
          >
            All
          </button>
          {projectKeys.map((key) => (
            <button
              key={key}
              onClick={() => handleProjectTab(key)}
              className={classNames(
                'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                activeProject === key
                  ? 'bg-brand-600 text-white'
                  : 'bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200'
              )}
            >
              {projects[key]}
            </button>
          ))}
        </div>
      )}

      {/* ── Upload Tracker ── */}
      {Object.keys(coverage).length > 0 && (
        <div className="card">
          <h3 className="text-sm font-semibold text-zinc-300 mb-3">Upload Tracker (last 7 days)</h3>
          <div className="space-y-3">
            {Object.entries(coverage).map(([projKey, days]) => {
              const uploadedCount = days.filter((d) => d.uploaded).length
              return (
                <div key={projKey}>
                  <div className="flex items-center gap-2 mb-1.5">
                    <span className="text-xs font-medium text-zinc-300">
                      {projects[projKey] || projKey}
                    </span>
                    <span className={classNames(
                      'text-[10px] font-bold px-1.5 py-0.5 rounded',
                      uploadedCount === 7
                        ? 'bg-emerald-500/15 text-emerald-400'
                        : 'bg-amber-500/15 text-amber-400'
                    )}>
                      {uploadedCount}/7
                    </span>
                  </div>
                  <div className="flex gap-1.5">
                    {days.map((day) => {
                      const dateLabel = day.date.slice(5) // MM-DD
                      const dayAbbr = day.day || DAY_ABBR[new Date(day.date + 'T00:00:00').getDay()]
                      return (
                        <button
                          key={day.date}
                          onClick={() => handleCoverageTileClick(projKey, day)}
                          className={classNames(
                            'flex flex-col items-center justify-center rounded-lg px-2 py-1.5 min-w-[56px] text-[10px] transition-colors border',
                            day.uploaded
                              ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20'
                              : 'bg-red-500/10 border-red-500/30 text-red-400 hover:bg-red-500/20'
                          )}
                          title={day.uploaded
                            ? `${day.date}: ${day.rows || 0} rows, Spend: ${fmtINR(day.spend)}`
                            : `${day.date}: Missing — click to upload`
                          }
                        >
                          <span className="font-semibold">{dayAbbr}</span>
                          <span className="opacity-70">{dateLabel}</span>
                          {day.uploaded ? (
                            <span className="font-medium mt-0.5">{fmtINR(day.spend)}</span>
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
        </div>
      )}

      {/* ── Summary Cards ── */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
          {([
            { label: 'Total Spend (INR)', value: summary.total_spend },
            { label: 'Impressions', value: summary.total_impressions },
            { label: 'Reach', value: summary.total_reach },
            { label: 'App Installs', value: summary.total_installs },
            { label: 'Results', value: summary.total_results },
            { label: '1st Purchases', value: summary.total_first_purchases ?? summary.total_purchases }
          ] as { label: string; value: number | null | undefined }[]).map((card) => (
            <div key={card.label} className="rounded border border-zinc-800 bg-surface-2 py-2 px-3 text-center">
              <div className="text-[14px] font-bold text-zinc-100 leading-tight">{fmtINR(card.value)}</div>
              <div className="text-[9px] uppercase tracking-wider text-zinc-500">{card.label}</div>
            </div>
          ))}
        </div>
      )}

      {/* ── Filters Row ── */}
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-[11px] text-zinc-500 mb-1">From</label>
          <input
            type="date"
            value={fromDate}
            onChange={(e) => setFromDate(e.target.value)}
            className="input-field text-xs w-36"
          />
        </div>
        <div>
          <label className="block text-[11px] text-zinc-500 mb-1">To</label>
          <input
            type="date"
            value={toDate}
            onChange={(e) => setToDate(e.target.value)}
            className="input-field text-xs w-36"
          />
        </div>
        <div>
          <label className="block text-[11px] text-zinc-500 mb-1">Campaign</label>
          <input
            type="text"
            value={campaignSearch}
            onChange={(e) => setCampaignSearch(e.target.value)}
            placeholder="Search campaign..."
            className="input-field text-xs w-48"
          />
        </div>
        <button onClick={handleFilter} className="btn-primary text-xs">
          Filter
        </button>
        {hasFilters && (
          <button onClick={handleClear} className="btn-secondary text-xs">
            Clear
          </button>
        )}
        <div className="ml-auto">
          <button
            onClick={() => { setUploadFile(null); setUploadOpen(true) }}
            className="btn-primary text-xs flex items-center gap-1.5"
          >
            <Upload className="h-3.5 w-3.5" />
            + Upload CSV
          </button>
        </div>
      </div>

      {/* ── Data Table ── */}
      {loading ? (
        <Loader label="Loading..." />
      ) : reports.length === 0 ? (
        <EmptyState
          icon={BarChart3}
          title="No Meta Ad Reports"
          description="Upload a CSV file to import ad performance data."
        />
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border border-zinc-800">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-surface-3">
                  <th className="px-3 py-2.5 text-left text-[11px] font-medium text-zinc-400 whitespace-nowrap">Date</th>
                  <th className="px-3 py-2.5 text-left text-[11px] font-medium text-zinc-400 whitespace-nowrap">Campaign</th>
                  <th className="px-3 py-2.5 text-left text-[11px] font-medium text-zinc-400 whitespace-nowrap">Ad Set</th>
                  <th className="px-3 py-2.5 text-left text-[11px] font-medium text-zinc-400 whitespace-nowrap">Ad</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Spend</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Reach</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Impr.</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Results</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">CPR</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Installs</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">CPI</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">Purchases</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">CPP</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">CPC</th>
                  <th className="px-3 py-2.5 text-right text-[11px] font-medium text-zinc-400 whitespace-nowrap">CTR%</th>
                </tr>
              </thead>
              <tbody>
                {pageRows.map((row) => (
                  <tr
                    key={row.id}
                    className="border-t border-zinc-800/50 hover:bg-surface-1 transition-colors"
                  >
                    <td className="px-3 py-2 text-zinc-300 whitespace-nowrap">
                      {row.reporting_starts}
                    </td>
                    <td
                      className="px-3 py-2 text-zinc-300 max-w-[160px] truncate"
                      title={row.campaign_name}
                    >
                      {row.campaign_name}
                    </td>
                    <td
                      className="px-3 py-2 text-zinc-400 max-w-[140px] truncate"
                      title={row.ad_set_name}
                    >
                      {row.ad_set_name}
                    </td>
                    <td
                      className="px-3 py-2 text-zinc-400 max-w-[140px] truncate"
                      title={row.ad_name}
                    >
                      {row.ad_name}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-200 font-medium">
                      {fmtINR(row.amount_spent)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-300">
                      {fmtINR(row.reach)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-300">
                      {fmtINR(row.impressions)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-300">
                      {fmtINR(row.results)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-400">
                      {fmtINR(row.cost_per_result)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-300">
                      {fmtINR(row.app_installs)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-400">
                      {fmtINR(row.cost_per_install)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-300">
                      {fmtINR(row.new_user_first_purchase)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-400">
                      {fmtINR(row.cost_per_first_purchase)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-400">
                      {fmtINR(row.cpc)}
                    </td>
                    <td className="px-3 py-2 text-right text-zinc-400">
                      {row.ctr != null ? Number(row.ctr).toFixed(2) + '%' : '\u2014'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* ── Pagination ── */}
          <div className="flex flex-wrap items-center justify-between gap-3 text-xs">
            <div className="text-zinc-500">
              Showing {totalRows === 0 ? 0 : startIdx + 1}\u2013{endIdx} of {totalRows.toLocaleString()}
            </div>

            <div className="flex items-center gap-1">
              <button
                onClick={() => setPage(1)}
                disabled={safePage <= 1}
                className="px-2 py-1 rounded bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30 transition-colors"
              >
                First
              </button>
              <button
                onClick={() => setPage(safePage - 1)}
                disabled={safePage <= 1}
                className="px-2 py-1 rounded bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30 transition-colors"
              >
                Prev
              </button>

              {buildPageNumbers().map((p, i) =>
                p === '...' ? (
                  <span key={`ellipsis-${i}`} className="px-1 text-zinc-600">&hellip;</span>
                ) : (
                  <button
                    key={p}
                    onClick={() => setPage(p)}
                    className={classNames(
                      'px-2.5 py-1 rounded transition-colors',
                      p === safePage
                        ? 'bg-brand-600 text-white'
                        : 'bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200'
                    )}
                  >
                    {p}
                  </button>
                )
              )}

              <button
                onClick={() => setPage(safePage + 1)}
                disabled={safePage >= totalPages}
                className="px-2 py-1 rounded bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30 transition-colors"
              >
                Next
              </button>
              <button
                onClick={() => setPage(totalPages)}
                disabled={safePage >= totalPages}
                className="px-2 py-1 rounded bg-surface-3 border border-zinc-800 text-zinc-400 hover:text-zinc-200 disabled:opacity-30 transition-colors"
              >
                Last
              </button>
            </div>

            <div className="flex items-center gap-2">
              <label className="text-zinc-500">Per page:</label>
              <select
                value={perPage}
                onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}
                className="input-field text-xs w-16 py-1"
              >
                <option value={25}>25</option>
                <option value={50}>50</option>
                <option value={100}>100</option>
              </select>
            </div>
          </div>
        </>
      )}

      {/* ── Upload Modal ── */}
      <Modal
        open={uploadOpen}
        onClose={() => setUploadOpen(false)}
        title="Upload Meta Ads CSV"
        width="max-w-md"
        footer={
          <button
            onClick={handleUpload}
            disabled={uploading || !uploadFile}
            className="btn-primary text-sm disabled:opacity-50"
          >
            {uploading ? 'Uploading...' : 'Upload & Import'}
          </button>
        }
      >
        <div className="space-y-4">
          {/* Project select */}
          <div>
            <label className="block text-xs font-medium text-zinc-400 mb-1">Project</label>
            <select
              value={uploadProject}
              onChange={(e) => setUploadProject(e.target.value)}
              className="input-field text-sm w-full"
            >
              {projectOptions.map((p) => (
                <option key={p.key} value={p.key}>{p.label}</option>
              ))}
            </select>
          </div>

          {/* Dropzone */}
          <div>
            <label className="block text-xs font-medium text-zinc-400 mb-1">CSV File</label>
            <div
              ref={dropRef}
              onClick={() => fileInputRef.current?.click()}
              onDragOver={(e) => { e.preventDefault(); e.stopPropagation() }}
              onDragEnter={(e) => { e.preventDefault(); e.stopPropagation() }}
              onDrop={handleFileDrop}
              className={classNames(
                'flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 cursor-pointer transition-colors',
                uploadFile
                  ? 'border-emerald-500/40 bg-emerald-500/5'
                  : 'border-zinc-700 bg-surface-3 hover:border-zinc-600'
              )}
            >
              {uploadFile ? (
                <div className="flex items-center gap-2 text-sm text-emerald-400">
                  <FileSpreadsheet className="h-5 w-5" />
                  <span className="font-medium">{uploadFile.name}</span>
                  <button
                    onClick={(e) => { e.stopPropagation(); setUploadFile(null) }}
                    className="ml-1 p-0.5 rounded hover:bg-zinc-700 text-zinc-400 hover:text-zinc-200"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                </div>
              ) : (
                <>
                  <Upload className="h-8 w-8 text-zinc-600 mb-2" />
                  <p className="text-sm text-zinc-400">Click or drag &amp; drop CSV file here</p>
                  <p className="text-[11px] text-zinc-600 mt-1">Accepts .csv files only</p>
                </>
              )}
            </div>
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv"
              onChange={handleFileSelect}
              className="hidden"
            />
          </div>
        </div>
      </Modal>
    </div>
  )
}
