/**
 * Voice Assistant Memory Types
 *
 * Types for the context-based memory system that persists user preferences,
 * corrections, shortcuts, and context across sessions and devices.
 */

export type MemoryType = 'preference' | 'correction' | 'context' | 'shortcut'

export interface VoiceMemory {
  id: number
  user_id: number
  type: MemoryType
  key_phrase: string | null // Trigger phrase (for corrections/shortcuts)
  value: string // The memory content
  metadata: Record<string, unknown> | null // Type-specific data
  confidence: number // 0.0-1.0, how confident we are in this memory
  is_active: boolean
  use_count: number
  last_used_at: string | null
  created_at: string
  updated_at: string
}

export interface MemoryMatch {
  memory: VoiceMemory
  score: number
}

export interface LearningPattern {
  regex: RegExp
  type: MemoryType | null // null = special action like deactivate
  extract: (match: RegExpMatchArray) => Partial<VoiceMemory> & { deactivate?: string }
}

export interface MemoryCreateRequest {
  type: MemoryType
  key_phrase?: string | null
  value: string
  metadata?: Record<string, unknown> | null
  confidence?: number
}

export interface MemoryUpdateRequest {
  value?: string
  metadata?: Record<string, unknown> | null
  confidence?: number
  is_active?: boolean
}
