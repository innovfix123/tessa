export function escapeHtml(str: string): string {
  const map: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }
  return String(str).replace(/[&<>"']/g, (c) => map[c] || c)
}

export function formatDate(d: Date): string {
  const y = d.getFullYear()
  const m = d.getMonth() + 1
  const day = d.getDate()
  return `${y}-${m < 10 ? '0' : ''}${m}-${day < 10 ? '0' : ''}${day}`
}

export function addDays(d: Date, n: number): Date {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

export function startOfWeek(d: Date): Date {
  const r = new Date(d)
  const day = r.getDay()
  const diff = day === 0 ? -6 : 1 - day
  r.setDate(r.getDate() + diff)
  r.setHours(0, 0, 0, 0)
  return r
}

export function weekKey(d: Date): string {
  return formatDate(startOfWeek(d))
}

export function dateKey(d: Date): string {
  return formatDate(d)
}

export function prettyDate(s: string): string {
  if (!s) return ''
  const d = new Date(s + 'T00:00:00')
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
}

export function prettyDateTime(s: string): string {
  if (!s) return ''
  const d = new Date(s)
  return d.toLocaleString('en-IN', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
  })
}

export function relativeTime(s: string): string {
  const now = Date.now()
  const then = new Date(s).getTime()
  const diff = now - then
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.floor(hrs / 24)
  if (days < 7) return `${days}d ago`
  return prettyDate(s.slice(0, 10))
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

export function formatINR(n: number): string {
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n)
}

export function formatNum(n: number | string): string {
  const v = typeof n === 'string' ? parseFloat(n) : n
  if (isNaN(v)) return String(n)
  return v.toLocaleString('en-IN')
}

export function stringToColor(str: string): string {
  let hash = 0
  for (let i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash)
  }
  const h = Math.abs(hash % 360)
  return `hsl(${h}, 50%, 45%)`
}

export function initials(name: string): string {
  return name
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}

export function classNames(...args: (string | false | null | undefined)[]): string {
  return args.filter(Boolean).join(' ')
}

export function formatTessaReply(text: string): string {
  if (!text || typeof text !== 'string') return ''
  let s = escapeHtml(text)
  s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
  s = s.replace(/\*(.+?)\*/g, '<em>$1</em>')
  s = s.replace(/`(.+?)`/g, '<code>$1</code>')

  const lines = s.split(/\n/)
  const out: string[] = []
  let inList = false
  const paraLines: string[] = []
  const tableRows: string[][] = []

  function flushPara() {
    if (paraLines.length) {
      out.push('<p>' + paraLines.join('<br>') + '</p>')
      paraLines.length = 0
    }
  }

  function flushTable() {
    if (!tableRows.length) return
    let html = '<div class="tessa-table"><table><thead><tr>'
    tableRows[0].forEach((cell) => { html += '<th>' + cell + '</th>' })
    html += '</tr></thead><tbody>'
    for (let r = 1; r < tableRows.length; r++) {
      html += '<tr>'
      tableRows[r].forEach((cell) => { html += '<td>' + cell + '</td>' })
      html += '</tr>'
    }
    html += '</tbody></table></div>'
    out.push(html)
    tableRows.length = 0
  }

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    const bullet = /^[\-\*]\s+(.+)$/.exec(line)
    const isTableRow = /^\s*\|.+\|\s*$/.test(line)
    const isTableSep = /^\s*\|[\s\-:|]+\|\s*$/.test(line)
    const h2 = /^##\s+(.+)$/.exec(line)
    const h3 = /^###\s+(.+)$/.exec(line)
    const isHr = /^[\*\-_]{3,}\s*$/.test(line.trim())

    if (bullet) {
      flushPara(); flushTable()
      if (!inList) { out.push('<ul>'); inList = true }
      out.push('<li>' + bullet[1] + '</li>')
    } else if (isTableRow && !isTableSep) {
      flushPara()
      if (inList) { out.push('</ul>'); inList = false }
      const cells = line.split('|').slice(1, -1).map((c) => c.trim())
      if (cells.length) tableRows.push(cells)
    } else if (isTableSep) {
      flushPara()
      if (inList) { out.push('</ul>'); inList = false }
    } else if (h3) {
      flushPara(); flushTable()
      if (inList) { out.push('</ul>'); inList = false }
      out.push('<h4>' + h3[1] + '</h4>')
    } else if (h2) {
      flushPara(); flushTable()
      if (inList) { out.push('</ul>'); inList = false }
      out.push('<h3>' + h2[1] + '</h3>')
    } else if (isHr) {
      flushPara(); flushTable()
      if (inList) { out.push('</ul>'); inList = false }
      out.push('<hr>')
    } else if (line.trim()) {
      flushTable()
      if (inList) { out.push('</ul>'); inList = false }
      paraLines.push(line)
    } else {
      flushPara(); flushTable()
      if (inList) { out.push('</ul>'); inList = false }
    }
  }

  flushPara()
  flushTable()
  if (inList) out.push('</ul>')

  return out.join('')
}

export function truncate(s: string, len: number): string {
  if (s.length <= len) return s
  return s.slice(0, len) + '…'
}
