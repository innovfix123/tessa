/**
 * Voice Memory Service
 *
 * Handles learning, retrieval, and formatting of user memories for the voice assistant.
 * All intelligence lives here - the backend is just dumb storage.
 */

import type {
  VoiceMemory,
  MemoryMatch,
  MemoryType,
  LearningPattern,
  MemoryCreateRequest
} from '@/lib/memoryTypes'

// ── Learning Patterns ──
// These detect explicit memory commands from user speech

const LEARNING_PATTERNS: LearningPattern[] = [
  // Corrections: "when I say X, I mean Y"
  {
    regex: /when i say ["']?(.+?)["']?,?\s*i mean ["']?(.+?)["']?/i,
    type: 'correction',
    extract: (m) => ({ key_phrase: m[1].trim(), value: m[2].trim() })
  },
  // Corrections: "X actually means Y" / "X refers to Y"
  {
    regex: /["']?(.+?)["']?\s*(?:actually means|refers to|is actually)\s*["']?(.+?)["']?$/i,
    type: 'correction',
    extract: (m) => ({ key_phrase: m[1].trim(), value: m[2].trim() })
  },
  // Shortcuts: "my project is UNMAN"
  {
    regex: /(?:my|the)\s+(\w+)\s+(?:is|means|refers to)\s+["']?(.+?)["']?$/i,
    type: 'shortcut',
    extract: (m) => ({ key_phrase: `my ${m[1].toLowerCase()}`, value: m[2].trim() })
  },
  // Shortcuts: "call it X" / "refer to it as X"
  {
    regex: /(?:call it|refer to (?:it|that) as)\s+["']?(.+?)["']?$/i,
    type: 'shortcut',
    extract: (m) => ({ key_phrase: 'it', value: m[1].trim() })
  },
  // Preferences: "keep responses shorter" / "make answers brief"
  {
    regex: /(?:keep|make|i (?:want|prefer|like))\s+(?:responses?|answers?|replies?)\s+(.+)/i,
    type: 'preference',
    extract: (m) => ({
      value: `Response style: ${m[1].trim()}`,
      metadata: { category: 'response_style' }
    })
  },
  // Preferences: "be more detailed" / "give me more details"
  {
    regex: /(?:be more|give me more|i want more)\s+(detail|detailed|verbose|thorough)/i,
    type: 'preference',
    extract: () => ({
      value: 'Response style: more detailed and thorough',
      metadata: { category: 'response_style' }
    })
  },
  // Preferences: "be brief" / "keep it short"
  {
    regex: /(?:be|keep it)\s+(brief|short|concise|quick)/i,
    type: 'preference',
    extract: () => ({
      value: 'Response style: brief and concise',
      metadata: { category: 'response_style' }
    })
  },
  // Context: "I'm working on X" / "I'm focused on X"
  {
    regex: /i(?:'m| am)\s+(?:working on|focused on|focusing on)\s+["']?(.+?)["']?$/i,
    type: 'context',
    extract: (m) => ({
      value: `Currently focused on: ${m[1].trim()}`,
      metadata: { scope: 'current' }
    })
  },
  // Context: "remember that X"
  {
    regex: /remember (?:that\s+)?(.+)$/i,
    type: 'context',
    extract: (m) => ({
      value: m[1].trim(),
      metadata: { scope: 'general' }
    })
  },
  // Forget: "forget that" / "forget the shortcut for X"
  {
    regex: /(?:forget|remove|delete)\s+(?:that|the)?\s*(?:shortcut|correction|preference)?\s*(?:for|about)?\s*["']?(.+?)["']?$/i,
    type: null,
    extract: (m) => ({ deactivate: m[1].trim() })
  },
  // Forget: "nevermind" / "forget it"
  {
    regex: /^(?:never\s*mind|forget it|cancel that)$/i,
    type: null,
    extract: () => ({ deactivate: '__last__' })
  }
]

// ── Intent-Type Affinity Map ──
// Which memory types are most relevant for which intents

const INTENT_AFFINITY: Record<string, MemoryType[]> = {
  meeting_detail: ['context', 'shortcut', 'correction'],
  meetings_today: ['context', 'shortcut'],
  meetings_list: ['context', 'shortcut'],
  my_tasks: ['preference', 'context', 'shortcut'],
  task_detail: ['context', 'shortcut', 'correction'],
  sprint_status: ['shortcut', 'context'],
  revenue: ['shortcut', 'context'],
  meta_ads: ['shortcut', 'context'],
  google_ads: ['shortcut', 'context'],
  tickets: ['context', 'shortcut'],
  escalations: ['context'],
  morning_briefing: ['preference', 'context'],
  kpi_summary: ['context', 'shortcut'],
  daily_report_status: ['context']
}

/**
 * Score how relevant a memory is to the current query
 */
function scoreRelevance(memory: VoiceMemory, query: string, intent?: string): number {
  let score = 0
  const queryLower = query.toLowerCase()

  // 1. Exact key_phrase match: +1.0
  if (memory.key_phrase) {
    const keyLower = memory.key_phrase.toLowerCase()
    if (queryLower.includes(keyLower)) {
      score += 1.0
    }
  }

  // 2. Word overlap with value: +0.0-0.5
  const queryWords = queryLower.split(/\s+/).filter((w) => w.length > 2)
  const valueWords = memory.value.toLowerCase().split(/\s+/)
  const overlap = queryWords.filter((w) => valueWords.some((vw) => vw.includes(w))).length
  score += Math.min(0.5, overlap * 0.1)

  // 3. Type-intent affinity: +0.3
  if (intent && INTENT_AFFINITY[intent]?.includes(memory.type)) {
    score += 0.3
  }

  // 4. Recency boost: +0.0-0.2 (decays over 30 days)
  if (memory.last_used_at) {
    const daysSince = (Date.now() - new Date(memory.last_used_at).getTime()) / 86400000
    score += Math.max(0, 0.2 * (1 - daysSince / 30))
  }

  // 5. Use count boost: +0.0-0.1
  score += Math.min(0.1, memory.use_count * 0.01)

  // 6. Apply confidence multiplier
  score *= memory.confidence

  return score
}

/**
 * Find memories relevant to the current query
 */
export function findRelevantMemories(
  memories: VoiceMemory[],
  query: string,
  intent?: string,
  limit: number = 5
): MemoryMatch[] {
  if (!memories.length || !query.trim()) return []

  const scored: MemoryMatch[] = memories
    .filter((m) => m.is_active)
    .map((memory) => ({
      memory,
      score: scoreRelevance(memory, query, intent)
    }))
    .filter((m) => m.score > 0.1) // Only include somewhat relevant memories
    .sort((a, b) => b.score - a.score)

  // Always include all shortcuts with key_phrase match (they're essential for understanding)
  const shortcuts = scored.filter(
    (m) =>
      m.memory.type === 'shortcut' &&
      m.memory.key_phrase &&
      query.toLowerCase().includes(m.memory.key_phrase.toLowerCase())
  )

  // Always include all corrections with key_phrase match
  const corrections = scored.filter(
    (m) =>
      m.memory.type === 'correction' &&
      m.memory.key_phrase &&
      query.toLowerCase().includes(m.memory.key_phrase.toLowerCase())
  )

  // Get top remaining by score
  const others = scored.filter((m) => !shortcuts.includes(m) && !corrections.includes(m))

  // Combine: all matched shortcuts + all matched corrections + top others (up to limit)
  const result = [...shortcuts, ...corrections, ...others.slice(0, limit - shortcuts.length - corrections.length)]

  return result.slice(0, limit + 2) // Allow slightly over limit for essential matches
}

/**
 * Group memories by type
 */
function groupByType(memories: VoiceMemory[]): Record<MemoryType, VoiceMemory[]> {
  const grouped: Record<MemoryType, VoiceMemory[]> = {
    preference: [],
    correction: [],
    context: [],
    shortcut: []
  }

  for (const m of memories) {
    if (grouped[m.type]) {
      grouped[m.type].push(m)
    }
  }

  return grouped
}

/**
 * Format memories into a context string for Claude prompt injection
 */
export function formatMemoryContext(memories: VoiceMemory[]): string {
  if (!memories.length) return ''

  const grouped = groupByType(memories)
  const sections: string[] = []

  if (grouped.preference.length) {
    sections.push('PREFERENCES:\n' + grouped.preference.map((m) => `- ${m.value}`).join('\n'))
  }

  if (grouped.correction.length) {
    sections.push(
      'CORRECTIONS (user says X, means Y):\n' +
        grouped.correction.map((m) => `- "${m.key_phrase}" -> ${m.value}`).join('\n')
    )
  }

  if (grouped.shortcut.length) {
    sections.push(
      'SHORTCUTS:\n' + grouped.shortcut.map((m) => `- "${m.key_phrase}" = ${m.value}`).join('\n')
    )
  }

  if (grouped.context.length) {
    sections.push('CURRENT CONTEXT:\n' + grouped.context.map((m) => `- ${m.value}`).join('\n'))
  }

  if (!sections.length) return ''

  return `--- USER MEMORY ---\n\n${sections.join('\n\n')}\n\n--- END MEMORY ---`
}

/**
 * Detect explicit memory commands from user message
 * Returns a memory to create, or { deactivate: string } to deactivate a memory
 */
export function detectExplicitMemory(
  message: string
): (MemoryCreateRequest & { deactivate?: string }) | null {
  const trimmed = message.trim()
  if (!trimmed) return null

  for (const pattern of LEARNING_PATTERNS) {
    const match = trimmed.match(pattern.regex)
    if (match) {
      const extracted = pattern.extract(match)

      // Special case: deactivate command
      if ('deactivate' in extracted) {
        return { type: 'context', value: '', deactivate: extracted.deactivate }
      }

      // Regular memory creation
      if (pattern.type && extracted.value) {
        return {
          type: pattern.type,
          key_phrase: extracted.key_phrase || null,
          value: extracted.value,
          metadata: extracted.metadata || null,
          confidence: 1.0 // Explicit memories have full confidence
        }
      }
    }
  }

  return null
}

/**
 * Find a memory to deactivate based on search term
 */
export function findMemoryToDeactivate(
  memories: VoiceMemory[],
  searchTerm: string
): VoiceMemory | null {
  if (!searchTerm || searchTerm === '__last__') {
    // Deactivate most recently created active memory
    const active = memories.filter((m) => m.is_active)
    if (!active.length) return null
    return active.sort(
      (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
    )[0]
  }

  const searchLower = searchTerm.toLowerCase()

  // First try exact key_phrase match
  const exactMatch = memories.find(
    (m) => m.is_active && m.key_phrase?.toLowerCase() === searchLower
  )
  if (exactMatch) return exactMatch

  // Then try partial match on key_phrase or value
  const partialMatch = memories.find(
    (m) =>
      m.is_active &&
      (m.key_phrase?.toLowerCase().includes(searchLower) ||
        m.value.toLowerCase().includes(searchLower))
  )
  if (partialMatch) return partialMatch

  return null
}

/**
 * Check if a memory already exists (to avoid duplicates)
 */
export function findSimilarMemory(
  memories: VoiceMemory[],
  newMemory: MemoryCreateRequest
): VoiceMemory | null {
  return (
    memories.find((m) => {
      if (m.type !== newMemory.type) return false
      if (!m.is_active) return false

      // For corrections and shortcuts, match on key_phrase
      if (
        (newMemory.type === 'correction' || newMemory.type === 'shortcut') &&
        newMemory.key_phrase
      ) {
        return m.key_phrase?.toLowerCase() === newMemory.key_phrase.toLowerCase()
      }

      // For preferences and context, check value similarity
      const mWords = m.value.toLowerCase().split(/\s+/)
      const newWords = newMemory.value.toLowerCase().split(/\s+/)
      const overlap = mWords.filter((w) => newWords.includes(w)).length
      const similarity = overlap / Math.max(mWords.length, newWords.length)
      return similarity > 0.6
    }) || null
  )
}
