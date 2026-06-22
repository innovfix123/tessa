/**
 * Voice API Service
 *
 * Calls the Electron main process via IPC for STT (Whisper) and NLU/response (OpenRouter).
 * API keys live in the main process — never exposed to the renderer.
 */

export type ConversationMessage = { role: 'user' | 'assistant'; content: string }

export interface NLUResult {
  intent: string
  params: Record<string, string>
  confidence: number
}

/**
 * Send audio to main process for transcription via Whisper.
 */
export async function transcribeAudio(audioBlob: Blob): Promise<string> {
  const arrayBuffer = await audioBlob.arrayBuffer()
  const result = await window.tessaDesktop.voice.stt(arrayBuffer)
  if (result.error) {
    console.error('Voice STT error:', result.error)
    throw new Error(result.error)
  }
  return result.text || ''
}

/**
 * Fast local intent matching for common phrases - skips the AI round-trip.
 */
function fastMatchIntent(text: string): NLUResult | null {
  const t = text.toLowerCase().trim()

  // Greetings
  if (/^(hi|hello|hey)\b/.test(t) && !/task|meeting|report|sprint/.test(t)) {
    return { intent: 'greeting', params: {}, confidence: 0.95 }
  }

  // Morning briefing
  if (/good\s*morning|morning\s*briefing|brief\s*me/.test(t)) {
    return { intent: 'morning_briefing', params: {}, confidence: 0.95 }
  }

  // Tasks
  if (/^(show|what|list|get).*(my )?(task|to.?do)/.test(t)) {
    return { intent: 'tasks_list', params: {}, confidence: 0.9 }
  }

  // Meetings
  if (/^(show|what|any|do i have).*(meeting|call|schedule)/.test(t)) {
    return { intent: 'meetings_today', params: {}, confidence: 0.9 }
  }

  // Sign in/out
  if (/sign.*(me )?(in|on)|clock.*(in|on)|check.*(in|on)/.test(t)) {
    return { intent: 'sign_in', params: {}, confidence: 0.95 }
  }
  if (/sign.*(me )?(out|off)|clock.*(out|off)|check.*(out|off)/.test(t)) {
    return { intent: 'sign_off', params: {}, confidence: 0.95 }
  }

  return null // No fast match - fall through to AI
}

/**
 * Classify user intent from transcript via Claude, with conversation history for follow-ups.
 * Uses fast local matching first, falls back to AI for complex queries.
 */
export async function classifyIntent(
  transcript: string,
  userName: string,
  history: ConversationMessage[] = []
): Promise<NLUResult> {
  // Try fast local matching first (instant, no API call)
  const fastResult = fastMatchIntent(transcript)
  if (fastResult) {
    console.log('Voice: Fast-matched intent:', fastResult.intent)
    return fastResult
  }

  // Fall back to AI classification
  const result = await window.tessaDesktop.voice.nlu(transcript, userName, '', history)
  if (result.error) {
    console.error('Voice NLU error:', result.error)
  }
  return {
    intent: result.intent || 'unknown',
    params: result.params || {},
    confidence: result.confidence || 0
  }
}

/**
 * Generate a natural spoken response from API data, with conversation history for context.
 */
export async function generateSpokenResponse(
  intent: string,
  apiData: unknown,
  userName: string,
  history: ConversationMessage[] = []
): Promise<string> {
  const result = await window.tessaDesktop.voice.respond(intent, apiData, userName, '', history)
  return result.response || "Sorry, I couldn't generate a response."
}
