import { useState, useEffect, useCallback } from 'react'
import type { ExpandedMeeting } from '@/pages/Meetings'
import { meetingsAPI } from '@/api/client'
import { Loader } from '@/components/ui'
import { escapeHtml } from '@/lib/utils'
import toast from 'react-hot-toast'

interface Props {
  meeting: ExpandedMeeting
  weekKey: string
}

function formatMinutes(raw: string): string {
  if (!raw?.trim()) return ''

  function formatInline(text: string): string {
    let s = escapeHtml(text).replace(/:white_check_mark:/g, '✅')
    s = s.replace(/(^|[^A-Za-z0-9_])(@[A-Za-z0-9_.-]+)/g,
      (_, pre, mention) => `${pre}<span class="text-brand-400 font-medium">${mention}</span>`)
    s = s.replace(/\[([^\]]+)\]/g,
      '<span class="text-zinc-500 text-xs">[$1]</span>')
    return s
  }

  const lines = raw.split(/\r?\n/)
  const html: string[] = []
  let listItems: string[] = []

  function flushList() {
    if (!listItems.length) return
    html.push(`<ul class="list-disc ml-5 space-y-0.5 text-sm text-zinc-300 mb-2">${listItems.join('')}</ul>`)
    listItems = []
  }

  lines.forEach((line) => {
    const trimmed = line.trim()
    if (!trimmed) { flushList(); return }

    const isBullet = /^\*\s+/.test(trimmed)
    const isIndented = /^[\t ]+\*\s+/.test(line)

    if (isBullet) {
      const content = trimmed.replace(/^\*\s+/, '')
      if (isIndented) {
        listItems.push(`<li>${formatInline(content)}</li>`)
      } else {
        flushList()
        html.push(`<div class="font-semibold text-sm text-zinc-200 mt-3 mb-1">${formatInline(content)}</div>`)
      }
      return
    }

    flushList()
    html.push(`<div class="text-sm text-zinc-400 border-t border-zinc-800/50 pt-1 mt-1">${formatInline(trimmed)}</div>`)
  })

  flushList()
  return html.join('')
}

export default function PreviousMinutesTab({ meeting, weekKey }: Props): JSX.Element {
  const [html, setHtml] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await meetingsAPI.notes({
        meeting_id: meeting.id,
        week_key: weekKey,
        include_previous: '1',
        recurrence: meeting.recurrence || '',
        day: meeting.day || ''
      })
      const prev = res.data?.previousNote || ''
      setHtml(prev ? formatMinutes(prev) : '')
    } catch {
      toast.error('Failed to load previous minutes')
    } finally {
      setLoading(false)
    }
  }, [meeting.id, weekKey, meeting.recurrence, meeting.day])

  useEffect(() => { load() }, [load])

  if (loading) return <Loader size="sm" label="Loading previous minutes..." />

  return (
    <div>
      <h4 className="section-title mb-4">Minutes from Previous Meeting</h4>
      {html ? (
        <div className="card" dangerouslySetInnerHTML={{ __html: html }} />
      ) : (
        <p className="text-sm text-zinc-500 text-center py-8">No notes from previous meeting.</p>
      )}
    </div>
  )
}
