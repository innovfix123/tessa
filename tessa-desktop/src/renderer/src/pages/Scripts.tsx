import { useState, useEffect, useCallback } from 'react'
import { scriptsAPI } from '@/api/client'
import { Loader, EmptyState } from '@/components/ui'
import type { ScriptConfig, ScriptStats, ScriptLibraryItem } from '@/lib/types'
import { classNames } from '@/lib/utils'
import toast from 'react-hot-toast'
import { FileText, Copy, BookmarkPlus, Trash2, RefreshCw, Sparkles, BarChart3 } from 'lucide-react'

// ── Config from portal or sensible fallbacks ──

const defaultConfig: ScriptConfig = {
  languages: [
    { value: 'english', label: 'English' },
    { value: 'hindi', label: 'Hindi' },
    { value: 'hinglish', label: 'Hinglish' }
  ],
  categories: [
    { value: 'educational', label: 'Educational' },
    { value: 'promotional', label: 'Promotional' },
    { value: 'entertainment', label: 'Entertainment' },
    { value: 'motivational', label: 'Motivational' }
  ],
  isStatsOnly: false
}

function getConfig(): ScriptConfig {
  const raw = (window as any).__PORTAL_CONFIG?.scripts
  if (raw && raw.languages?.length && raw.categories?.length) return raw as ScriptConfig
  return defaultConfig
}

// ── Main Component ──

export default function Scripts(): JSX.Element {
  const config = getConfig()

  if (config.isStatsOnly) return <StatsView />
  return <GenerateView config={config} />
}

// ═══════════════════════════════════════════════════
// Generate Mode (normal users)
// ═══════════════════════════════════════════════════

type GenerateTab = 'generate' | 'library'

interface GeneratedScript {
  body: string
  generation_id?: number
}

function GenerateView({ config }: { config: ScriptConfig }) {
  const [tab, setTab] = useState<GenerateTab>('generate')

  return (
    <div className="max-w-5xl">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-bold text-zinc-100">Scripts</h2>
        <div className="flex gap-1 bg-surface-2 rounded-lg p-1">
          {(['generate', 'library'] as const).map((t) => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className={classNames(
                'px-4 py-1.5 rounded-md text-sm font-medium transition-colors',
                tab === t
                  ? 'bg-brand-600 text-white'
                  : 'text-zinc-400 hover:text-zinc-200'
              )}
            >
              {t === 'generate' ? 'Generate' : 'Library'}
            </button>
          ))}
        </div>
      </div>

      {tab === 'generate' ? (
        <GenerateTab config={config} />
      ) : (
        <LibraryTab config={config} />
      )}
    </div>
  )
}

// ── Generate Tab ──

function GenerateTab({ config }: { config: ScriptConfig }) {
  const [language, setLanguage] = useState(config.languages[0]?.value ?? '')
  const [category, setCategory] = useState(config.categories[0]?.value ?? '')
  const [count, setCount] = useState(5)
  const [brief, setBrief] = useState('')
  const [generating, setGenerating] = useState(false)
  const [status, setStatus] = useState('')
  const [results, setResults] = useState<GeneratedScript[]>([])

  const generate = async () => {
    if (!language || !category) {
      toast.error('Please select language and category')
      return
    }
    setGenerating(true)
    setStatus('Generating scripts...')
    setResults([])
    try {
      const res = await scriptsAPI.generate({
        language,
        category,
        count,
        creative_brief: brief || undefined
      })
      const data = res.data
      const scripts: string[] = data.scripts ?? data.data?.scripts ?? []
      const genId = data.id ?? data.data?.id
      setResults(scripts.map((body: string) => ({ body, generation_id: genId })))
      setStatus(`Generated ${scripts.length} script${scripts.length !== 1 ? 's' : ''}`)
      toast.success(`Generated ${scripts.length} scripts`)
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Generation failed'
      setStatus(msg)
      toast.error(msg)
    } finally {
      setGenerating(false)
    }
  }

  const copyScript = async (body: string) => {
    try {
      await navigator.clipboard.writeText(body)
      toast.success('Copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const saveToLibrary = async (script: GeneratedScript) => {
    try {
      await scriptsAPI.saveLibrary({
        body: script.body,
        language,
        category,
        script_generation_id: script.generation_id
      })
      toast.success('Saved to library')
    } catch (err: any) {
      toast.error(err?.response?.data?.message || 'Failed to save')
    }
  }

  return (
    <div>
      {/* Form */}
      <div className="card p-5 mb-6">
        <div className="grid grid-cols-2 gap-4 mb-4">
          <div>
            <label className="block text-sm font-medium text-zinc-400 mb-1">Language</label>
            <select
              value={language}
              onChange={(e) => setLanguage(e.target.value)}
              className="input w-full"
            >
              {config.languages.map((l) => (
                <option key={l.value} value={l.value}>{l.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-zinc-400 mb-1">Category</label>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="input w-full"
            >
              {config.categories.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-zinc-400 mb-1">Count</label>
            <select
              value={count}
              onChange={(e) => setCount(Number(e.target.value))}
              className="input w-full"
            >
              {Array.from({ length: 10 }, (_, i) => i + 1).map((n) => (
                <option key={n} value={n}>{n}</option>
              ))}
            </select>
          </div>
        </div>

        <div className="mb-4">
          <label className="block text-sm font-medium text-zinc-400 mb-1">Creative Brief</label>
          <textarea
            value={brief}
            onChange={(e) => setBrief(e.target.value)}
            className="input w-full h-24 resize-none"
            placeholder="Describe your content idea, target audience, tone, or any specific instructions..."
          />
        </div>

        <div className="flex items-center gap-4">
          <button
            onClick={generate}
            disabled={generating}
            className="btn btn-primary inline-flex items-center gap-2"
          >
            {generating ? (
              <>
                <span className="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                Generating...
              </>
            ) : (
              <>
                <Sparkles className="h-4 w-4" />
                Generate
              </>
            )}
          </button>
          {status && <span className="text-sm text-zinc-500">{status}</span>}
        </div>
      </div>

      {/* Results */}
      {results.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {results.map((script, idx) => (
            <div key={idx} className="card p-4">
              <p className="text-sm text-zinc-300 whitespace-pre-wrap mb-4 leading-relaxed">
                {script.body}
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => copyScript(script.body)}
                  className="btn btn-sm btn-ghost inline-flex items-center gap-1.5"
                >
                  <Copy className="h-3.5 w-3.5" />
                  Copy
                </button>
                <button
                  onClick={() => saveToLibrary(script)}
                  className="btn btn-sm btn-ghost inline-flex items-center gap-1.5 text-brand-400 hover:text-brand-300"
                >
                  <BookmarkPlus className="h-3.5 w-3.5" />
                  Save to library
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Library Tab ──

function LibraryTab({ config }: { config: ScriptConfig }) {
  const [items, setItems] = useState<ScriptLibraryItem[]>([])
  const [loading, setLoading] = useState(true)
  const [language, setLanguage] = useState('')
  const [category, setCategory] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = { scope: 'library' }
      if (language) params.language = language
      if (category) params.category = category
      const res = await scriptsAPI.list(params)
      setItems(res.data.items ?? res.data.data ?? res.data ?? [])
    } catch {
      toast.error('Failed to load library')
    } finally {
      setLoading(false)
    }
  }, [language, category])

  useEffect(() => { load() }, [load])

  const copyScript = async (body: string) => {
    try {
      await navigator.clipboard.writeText(body)
      toast.success('Copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const remove = async (id: number) => {
    try {
      await scriptsAPI.destroyLibrary(id)
      setItems((prev) => prev.filter((i) => i.id !== id))
      toast.success('Removed from library')
    } catch {
      toast.error('Failed to remove')
    }
  }

  return (
    <div>
      {/* Filters */}
      <div className="flex items-center gap-3 mb-5">
        <select
          value={language}
          onChange={(e) => setLanguage(e.target.value)}
          className="input"
        >
          <option value="">All Languages</option>
          {config.languages.map((l) => (
            <option key={l.value} value={l.value}>{l.label}</option>
          ))}
        </select>

        <select
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          className="input"
        >
          <option value="">All Categories</option>
          {config.categories.map((c) => (
            <option key={c.value} value={c.value}>{c.label}</option>
          ))}
        </select>

        <button
          onClick={load}
          className="btn btn-ghost inline-flex items-center gap-1.5"
        >
          <RefreshCw className="h-4 w-4" />
          Refresh
        </button>
      </div>

      {/* Content */}
      {loading ? (
        <Loader label="Loading library..." />
      ) : items.length === 0 ? (
        <EmptyState
          icon={FileText}
          title="No saved scripts"
          description="Generate scripts and save them to your library."
        />
      ) : (
        <div className="space-y-3">
          {items.map((item) => (
            <div key={item.id} className="card p-4">
              <div className="flex items-start justify-between gap-4">
                <p className="text-sm text-zinc-300 whitespace-pre-wrap leading-relaxed flex-1">
                  {item.body}
                </p>
                <div className="flex gap-1 shrink-0">
                  <button
                    onClick={() => copyScript(item.body)}
                    className="btn btn-sm btn-ghost"
                    title="Copy"
                  >
                    <Copy className="h-3.5 w-3.5" />
                  </button>
                  <button
                    onClick={() => remove(item.id)}
                    className="btn btn-sm btn-ghost text-red-400 hover:text-red-300"
                    title="Remove"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
              <div className="flex gap-2 mt-2">
                <span className="text-xs bg-surface-3 text-zinc-500 px-2 py-0.5 rounded">
                  {item.language}
                </span>
                <span className="text-xs bg-surface-3 text-zinc-500 px-2 py-0.5 rounded">
                  {item.category}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ═══════════════════════════════════════════════════
// Stats Mode (CEO only)
// ═══════════════════════════════════════════════════

function StatsView() {
  const [stats, setStats] = useState<ScriptStats | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await scriptsAPI.stats()
      setStats(res.data.stats ?? res.data)
    } catch {
      toast.error('Failed to load script stats')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  if (loading) return <Loader label="Loading script stats..." />
  if (!stats) return <div className="text-sm text-zinc-500 text-center py-12">No stats available.</div>

  return (
    <div className="max-w-5xl">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-bold text-zinc-100">Script Stats</h2>
        <button onClick={load} className="btn btn-ghost inline-flex items-center gap-1.5">
          <RefreshCw className="h-4 w-4" />
          Refresh
        </button>
      </div>

      {/* Stat Cards */}
      <div className="grid grid-cols-3 gap-4 mb-8">
        <StatCard label="Generation Runs" value={stats.total_generations} />
        <StatCard label="Scripts Produced" value={stats.total_scripts_generated} />
        <StatCard label="Saved to Library" value={stats.library_items_saved} />
      </div>

      {/* Tables */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        {/* By Language */}
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-zinc-300 mb-3">By Language</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-zinc-500 border-b border-zinc-800">
                <th className="pb-2">Language</th>
                <th className="pb-2 text-right">Generations</th>
              </tr>
            </thead>
            <tbody>
              {stats.by_language.map((row) => (
                <tr key={row.language} className="border-b border-zinc-800/50">
                  <td className="py-2 text-zinc-300">{row.label || row.language}</td>
                  <td className="py-2 text-right text-zinc-400">{row.generations}</td>
                </tr>
              ))}
              {stats.by_language.length === 0 && (
                <tr><td colSpan={2} className="py-4 text-center text-zinc-600">No data</td></tr>
              )}
            </tbody>
          </table>
        </div>

        {/* By Category */}
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-zinc-300 mb-3">By Category</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-zinc-500 border-b border-zinc-800">
                <th className="pb-2">Category</th>
                <th className="pb-2 text-right">Generations</th>
              </tr>
            </thead>
            <tbody>
              {stats.by_category.map((row) => (
                <tr key={row.category} className="border-b border-zinc-800/50">
                  <td className="py-2 text-zinc-300 capitalize">{row.category}</td>
                  <td className="py-2 text-right text-zinc-400">{row.generations}</td>
                </tr>
              ))}
              {stats.by_category.length === 0 && (
                <tr><td colSpan={2} className="py-4 text-center text-zinc-600">No data</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* By Team Member */}
      <div className="card p-4 mb-8">
        <h3 className="text-sm font-semibold text-zinc-300 mb-3">By Team Member</h3>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-zinc-500 border-b border-zinc-800">
              <th className="pb-2">Name</th>
              <th className="pb-2 text-right">Generations</th>
            </tr>
          </thead>
          <tbody>
            {stats.by_user.map((row) => (
              <tr key={row.name} className="border-b border-zinc-800/50">
                <td className="py-2 text-zinc-300">{row.name}</td>
                <td className="py-2 text-right text-zinc-400">{row.generations}</td>
              </tr>
            ))}
            {stats.by_user.length === 0 && (
              <tr><td colSpan={2} className="py-4 text-center text-zinc-600">No data</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {/* Recent Activity */}
      {stats.recent.length > 0 && (
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-zinc-300 mb-3">Recent Activity</h3>
          <div className="space-y-2">
            {stats.recent.map((entry, idx) => (
              <div
                key={idx}
                className="flex items-center justify-between py-2 border-b border-zinc-800/50 last:border-0"
              >
                <div>
                  <span className="text-sm text-zinc-300">{entry.user_name}</span>
                  <span className="text-xs text-zinc-600 mx-2">generated</span>
                  <span className="text-sm text-zinc-400">
                    {entry.script_count} {entry.language} / {entry.category}
                  </span>
                </div>
                <span className="text-xs text-zinc-600">
                  {entry.created_at
                    ? new Date(entry.created_at).toLocaleDateString('en-IN', {
                        day: 'numeric',
                        month: 'short',
                        hour: '2-digit',
                        minute: '2-digit'
                      })
                    : ''}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Stat Card ──

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="card p-5 text-center">
      <div className="text-3xl font-bold text-zinc-100 mb-1">
        {(value ?? 0).toLocaleString()}
      </div>
      <div className="text-sm text-zinc-500">{label}</div>
    </div>
  )
}
