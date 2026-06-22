import { useState, useEffect, useCallback, useRef } from 'react'
import { templatesAPI } from '@/api/client'
import { Loader, ConfirmDialog } from '@/components/ui'
import { classNames } from '@/lib/utils'
import { FileText } from 'lucide-react'
import toast from 'react-hot-toast'

/* ── Types ── */

interface TemplateItem {
  id: number
  sectionTitle: string | null
  pointQuestion: string | null
  sortOrder: number
}

interface Template {
  id: number
  name: string
  items: TemplateItem[]
}

interface Section {
  id: number
  title: string
  points: TemplateItem[]
}

/* ── Helpers ── */

function buildSections(items: TemplateItem[]): Section[] {
  const sections: Section[] = []
  let current: Section | null = null

  for (const item of items) {
    if (item.pointQuestion === null) {
      current = { id: item.id, title: item.sectionTitle || '', points: [] }
      sections.push(current)
    } else if (current) {
      current.points.push(item)
    }
  }

  return sections
}

function templateMeta(items: TemplateItem[]): string {
  const sections = items.filter((i) => i.pointQuestion === null).length
  const points = items.filter((i) => i.pointQuestion !== null).length
  return `${sections} section${sections !== 1 ? 's' : ''}, ${points} point${points !== 1 ? 's' : ''}`
}

/* ── Component ── */

export default function Templates(): JSX.Element {
  const [templates, setTemplates] = useState<Template[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [newName, setNewName] = useState('')
  const [newSection, setNewSection] = useState('')
  const [newPoints, setNewPoints] = useState<Record<number, string>>({})
  const [editingItem, setEditingItem] = useState<number | null>(null)
  const [editValue, setEditValue] = useState('')
  const [confirmDelete, setConfirmDelete] = useState<{ type: 'template' | 'section' | 'point'; id: number } | null>(null)

  const editRef = useRef<HTMLInputElement>(null)

  /* ── Fetch ── */

  const fetchTemplates = useCallback(async () => {
    try {
      const res = await templatesAPI.list()
      const data: Template[] = res.data?.items || []
      setTemplates(data)
    } catch {
      toast.error('Failed to load templates')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchTemplates()
  }, [fetchTemplates])

  /* ── Derived ── */

  const filtered = templates.filter((t) =>
    t.name.toLowerCase().includes(search.toLowerCase())
  )

  const selected = templates.find((t) => t.id === selectedId) || null
  const sections = selected ? buildSections(selected.items) : []

  /* ── Template CRUD ── */

  const createTemplate = async () => {
    const name = newName.trim()
    if (!name) return
    try {
      await templatesAPI.post({ action: 'create_template', name })
      setNewName('')
      toast.success('Template created')
      await fetchTemplates()
    } catch {
      toast.error('Failed to create template')
    }
  }

  const renameTemplate = async () => {
    if (!selected) return
    const name = prompt('Rename template:', selected.name)
    if (!name || name.trim() === '' || name.trim() === selected.name) return
    try {
      await templatesAPI.post({ action: 'update_template', id: selected.id, name: name.trim() })
      toast.success('Template renamed')
      await fetchTemplates()
    } catch {
      toast.error('Failed to rename template')
    }
  }

  const deleteTemplate = async () => {
    if (!confirmDelete || confirmDelete.type !== 'template') return
    try {
      await templatesAPI.post({ action: 'delete_template', id: confirmDelete.id })
      if (selectedId === confirmDelete.id) setSelectedId(null)
      toast.success('Template deleted')
      await fetchTemplates()
    } catch {
      toast.error('Failed to delete template')
    } finally {
      setConfirmDelete(null)
    }
  }

  /* ── Section / Point CRUD ── */

  const addSection = async () => {
    if (!selected) return
    const title = newSection.trim()
    if (!title) return
    try {
      await templatesAPI.post({ action: 'add_item', templateId: selected.id, sectionTitle: title })
      setNewSection('')
      toast.success('Section added')
      await fetchTemplates()
    } catch {
      toast.error('Failed to add section')
    }
  }

  const addPoint = async (sectionId: number) => {
    if (!selected) return
    const question = (newPoints[sectionId] || '').trim()
    if (!question) return
    try {
      // Find the last item in this section to place the point after it
      const section = sections.find((s) => s.id === sectionId)
      const lastItem = section?.points.length
        ? section.points[section.points.length - 1]
        : null
      await templatesAPI.post({
        action: 'add_item',
        templateId: selected.id,
        pointQuestion: question,
        ...(lastItem ? { afterItemId: lastItem.id } : { afterItemId: sectionId })
      })
      setNewPoints((prev) => ({ ...prev, [sectionId]: '' }))
      toast.success('Discussion point added')
      await fetchTemplates()
    } catch {
      toast.error('Failed to add discussion point')
    }
  }

  const saveEdit = async (item: TemplateItem) => {
    const value = editValue.trim()
    if (!value) {
      cancelEdit()
      return
    }
    const isSection = item.pointQuestion === null
    const unchanged = isSection
      ? value === item.sectionTitle
      : value === item.pointQuestion
    if (unchanged) {
      cancelEdit()
      return
    }
    try {
      await templatesAPI.post({
        action: 'update_item',
        id: item.id,
        ...(isSection ? { sectionTitle: value } : { pointQuestion: value })
      })
      toast.success('Item updated')
      setEditingItem(null)
      await fetchTemplates()
    } catch {
      toast.error('Failed to update item')
    }
  }

  const deleteItem = async () => {
    if (!confirmDelete || confirmDelete.type === 'template') return
    try {
      await templatesAPI.post({ action: 'delete_item', id: confirmDelete.id })
      toast.success('Item deleted')
      await fetchTemplates()
    } catch {
      toast.error('Failed to delete item')
    } finally {
      setConfirmDelete(null)
    }
  }

  /* ── Inline edit helpers ── */

  const startEdit = (item: TemplateItem) => {
    setEditingItem(item.id)
    setEditValue(item.pointQuestion ?? item.sectionTitle ?? '')
    setTimeout(() => editRef.current?.focus(), 0)
  }

  const cancelEdit = () => {
    setEditingItem(null)
    setEditValue('')
  }

  const handleEditKeyDown = (e: React.KeyboardEvent, item: TemplateItem) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      saveEdit(item)
    } else if (e.key === 'Escape') {
      cancelEdit()
    }
  }

  /* ── Confirm dialog handler ── */

  const handleConfirmDelete = () => {
    if (!confirmDelete) return
    if (confirmDelete.type === 'template') {
      deleteTemplate()
    } else {
      deleteItem()
    }
  }

  /* ── Render ── */

  if (loading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Loader />
      </div>
    )
  }

  return (
    <div className="flex h-full">
      {/* ── Left Sidebar ── */}
      <div className="w-72 border-r border-zinc-800 flex flex-col">
        {/* Search */}
        <div className="p-3 border-b border-zinc-800">
          <input
            type="text"
            placeholder="Search templates..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="input-field w-full text-sm"
          />
        </div>

        {/* Create */}
        <div className="p-3 border-b border-zinc-800">
          <div className="flex gap-2">
            <input
              type="text"
              placeholder="New template name"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && createTemplate()}
              className="input-field flex-1 text-sm"
            />
            <button
              onClick={createTemplate}
              disabled={!newName.trim()}
              className="btn-primary text-sm px-3 py-1.5 whitespace-nowrap"
            >
              Create
            </button>
          </div>
        </div>

        {/* Template list */}
        <div className="flex-1 overflow-y-auto">
          {filtered.length === 0 ? (
            <div className="p-4 text-center text-zinc-500 text-sm">
              No templates found
            </div>
          ) : (
            filtered.map((t) => (
              <button
                key={t.id}
                onClick={() => setSelectedId(t.id)}
                className={classNames(
                  'w-full text-left px-4 py-3 border-b border-zinc-800/50 hover:bg-zinc-800/50 transition-colors',
                  selectedId === t.id && 'bg-zinc-800 border-l-2 border-l-blue-500'
                )}
              >
                <div className="font-medium text-sm text-zinc-200 truncate">
                  {t.name}
                </div>
                <div className="text-xs text-zinc-500 mt-0.5">
                  {templateMeta(t.items)}
                </div>
              </button>
            ))
          )}
        </div>

        {/* Count badge */}
        <div className="p-3 border-t border-zinc-800 text-center">
          <span className="inline-flex items-center gap-1.5 text-xs text-zinc-400 bg-zinc-800 rounded-full px-3 py-1">
            <span className="font-semibold text-zinc-200">{templates.length}</span>
            template{templates.length !== 1 ? 's' : ''}
          </span>
        </div>
      </div>

      {/* ── Right Detail Panel ── */}
      <div className="flex-1 overflow-y-auto">
        {!selected ? (
          <div className="flex flex-col items-center justify-center h-full text-center">
            <FileText className="h-12 w-12 text-zinc-700 mb-3" />
            <h3 className="text-base font-medium text-zinc-400">Select a template</h3>
            <p className="mt-1 text-sm text-zinc-500">Choose a template from the sidebar to view its agenda structure</p>
          </div>
        ) : (
          <div className="p-6 max-w-3xl">
            {/* Header */}
            <div className="flex items-start justify-between mb-6">
              <h2 className="text-xl font-semibold text-zinc-100">{selected.name}</h2>
              <div className="flex gap-2">
                <button
                  onClick={renameTemplate}
                  className="btn-secondary text-sm px-3 py-1.5"
                >
                  Rename
                </button>
                <button
                  onClick={() => setConfirmDelete({ type: 'template', id: selected.id })}
                  className="btn-secondary text-sm px-3 py-1.5 text-red-400 hover:text-red-300"
                >
                  Delete
                </button>
              </div>
            </div>

            {/* Add Section */}
            <div className="flex gap-2 mb-6">
              <input
                type="text"
                placeholder="Add a section..."
                value={newSection}
                onChange={(e) => setNewSection(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && addSection()}
                className="input-field flex-1 text-sm"
              />
              <button
                onClick={addSection}
                disabled={!newSection.trim()}
                className="btn-primary text-sm px-4 py-1.5"
              >
                Add Section
              </button>
            </div>

            {/* Sections tree */}
            {sections.length === 0 ? (
              <div className="text-zinc-500 text-sm py-8 text-center">
                No sections yet. Add one above.
              </div>
            ) : (
              <div className="space-y-4">
                {sections.map((section, sIdx) => (
                  <div
                    key={section.id}
                    className="border border-zinc-800 rounded-lg overflow-hidden"
                  >
                    {/* Section header */}
                    <div className="flex items-center gap-3 px-4 py-3 bg-zinc-800/50">
                      <span className="flex-shrink-0 w-7 h-7 rounded-full bg-blue-600/20 text-blue-400 text-xs font-bold flex items-center justify-center">
                        {sIdx + 1}
                      </span>

                      {editingItem === section.id ? (
                        <input
                          ref={editRef}
                          type="text"
                          value={editValue}
                          onChange={(e) => setEditValue(e.target.value)}
                          onBlur={() => {
                            const item = selected.items.find((i) => i.id === section.id)
                            if (item) saveEdit(item)
                          }}
                          onKeyDown={(e) => {
                            const item = selected.items.find((i) => i.id === section.id)
                            if (item) handleEditKeyDown(e, item)
                          }}
                          className="input-field flex-1 text-sm"
                        />
                      ) : (
                        <span className="flex-1 font-medium text-sm text-zinc-200">
                          {section.title}
                        </span>
                      )}

                      <div className="flex gap-1">
                        {editingItem !== section.id && (
                          <button
                            onClick={() => {
                              const item = selected.items.find((i) => i.id === section.id)
                              if (item) startEdit(item)
                            }}
                            className="p-1.5 rounded hover:bg-zinc-700 text-zinc-400 hover:text-zinc-200 transition-colors"
                            title="Edit section"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                          </button>
                        )}
                        <button
                          onClick={() => setConfirmDelete({ type: 'section', id: section.id })}
                          className="p-1.5 rounded hover:bg-zinc-700 text-zinc-400 hover:text-red-400 transition-colors"
                          title="Delete section"
                        >
                          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        </button>
                      </div>
                    </div>

                    {/* Points */}
                    <div className="divide-y divide-zinc-800/50">
                      {section.points.map((point, pIdx) => (
                        <div
                          key={point.id}
                          className="flex items-center gap-3 px-4 py-2.5 pl-8 hover:bg-zinc-800/30 transition-colors"
                        >
                          <span className="flex-shrink-0 text-xs text-zinc-500 font-mono w-8">
                            {sIdx + 1}.{pIdx + 1}
                          </span>

                          {editingItem === point.id ? (
                            <input
                              ref={editRef}
                              type="text"
                              value={editValue}
                              onChange={(e) => setEditValue(e.target.value)}
                              onBlur={() => saveEdit(point)}
                              onKeyDown={(e) => handleEditKeyDown(e, point)}
                              className="input-field flex-1 text-sm"
                            />
                          ) : (
                            <span className="flex-1 text-sm text-zinc-300">
                              {point.pointQuestion}
                            </span>
                          )}

                          <div className="flex gap-1">
                            {editingItem !== point.id && (
                              <button
                                onClick={() => startEdit(point)}
                                className="p-1.5 rounded hover:bg-zinc-700 text-zinc-400 hover:text-zinc-200 transition-colors"
                                title="Edit point"
                              >
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                              </button>
                            )}
                            <button
                              onClick={() => setConfirmDelete({ type: 'point', id: point.id })}
                              className="p-1.5 rounded hover:bg-zinc-700 text-zinc-400 hover:text-red-400 transition-colors"
                              title="Delete point"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          </div>
                        </div>
                      ))}

                      {/* Add point input */}
                      <div className="flex items-center gap-2 px-4 py-2.5 pl-8">
                        <span className="flex-shrink-0 text-xs text-zinc-600 font-mono w-8">
                          {sIdx + 1}.{section.points.length + 1}
                        </span>
                        <input
                          type="text"
                          placeholder="Add discussion point..."
                          value={newPoints[section.id] || ''}
                          onChange={(e) =>
                            setNewPoints((prev) => ({ ...prev, [section.id]: e.target.value }))
                          }
                          onKeyDown={(e) => e.key === 'Enter' && addPoint(section.id)}
                          className="input-field flex-1 text-sm"
                        />
                        <button
                          onClick={() => addPoint(section.id)}
                          disabled={!(newPoints[section.id] || '').trim()}
                          className="btn-primary text-xs px-3 py-1.5"
                        >
                          Add
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* ── Confirm Dialog ── */}
      <ConfirmDialog
        open={confirmDelete !== null}
        onClose={() => setConfirmDelete(null)}
        onConfirm={handleConfirmDelete}
        title={confirmDelete ? `Delete ${confirmDelete.type}?` : ''}
        message={
          confirmDelete?.type === 'template'
            ? 'This will permanently delete the template and all its sections and points.'
            : confirmDelete?.type === 'section'
              ? 'This will delete the section and all its discussion points.'
              : 'This will delete the discussion point.'
        }
        confirmLabel="Delete"
        danger
      />
    </div>
  )
}
