import {
  createContext,
  useContext,
  useState,
  useCallback,
  useEffect,
  useRef,
  type ReactNode
} from 'react'
import { useAuth } from './AuthContext'
import { startRecording, stopRecording } from '@/services/voiceRecorder'
import { transcribeAudio, classifyIntent, generateSpokenResponse, type ConversationMessage } from '@/services/voiceAPI'
import { orchestrate } from '@/services/apiOrchestrator'
import { speak, stop as stopSpeech } from '@/services/textToSpeech'
import {
  startWakeWordDetection,
  stopWakeWordDetection,
  pauseDetection,
  resumeDetection
} from '@/services/wakeWord'
import type { VoiceState } from '@/lib/types'

// Voice assistant enabled for all authenticated users

// Play a pleasant activation chime using Web Audio API
function playActivationChime(): void {
  try {
    const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)()
    const oscillator = audioCtx.createOscillator()
    const gainNode = audioCtx.createGain()

    oscillator.connect(gainNode)
    gainNode.connect(audioCtx.destination)

    // Pleasant two-tone chime (C5 → E5)
    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(523.25, audioCtx.currentTime) // C5
    oscillator.frequency.setValueAtTime(659.25, audioCtx.currentTime + 0.08) // E5

    // Fade in/out for smooth sound
    gainNode.gain.setValueAtTime(0, audioCtx.currentTime)
    gainNode.gain.linearRampToValueAtTime(0.15, audioCtx.currentTime + 0.02)
    gainNode.gain.linearRampToValueAtTime(0.1, audioCtx.currentTime + 0.1)
    gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.2)

    oscillator.start(audioCtx.currentTime)
    oscillator.stop(audioCtx.currentTime + 0.2)

    // Clean up
    setTimeout(() => audioCtx.close(), 300)
  } catch {
    // Ignore audio errors silently
  }
}

interface VoiceContextValue {
  state: VoiceState
  transcript: string
  response: string
  error: string | null
  activate: () => void
  cancel: () => void
  isEnabled: boolean
  setEnabled: (v: boolean) => void
  isPilotUser: boolean
}

const VoiceContext = createContext<VoiceContextValue | null>(null)

export function VoiceProvider({ children }: { children: ReactNode }): JSX.Element {
  const { user } = useAuth()
  const [state, setState] = useState<VoiceState>('idle')
  const [transcript, setTranscript] = useState('')
  const [response, setResponse] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isEnabled, setEnabled] = useState(() => {
    try {
      return localStorage.getItem('tessa_voice_enabled') !== 'false'
    } catch {
      return true
    }
  })

  // Wake word is always enabled when voice is enabled
  const wakeWordEnabled = true

  const followUpTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const stateRef = useRef(state)
  stateRef.current = state

  // Conversation memory — keeps last 3 exchanges (6 messages) for follow-up context
  const conversationRef = useRef<ConversationMessage[]>([])

  // Voice enabled for all authenticated users
  const isPilotUser = !!user?.email

  const handleSetEnabled = useCallback((v: boolean) => {
    setEnabled(v)
    try { localStorage.setItem('tessa_voice_enabled', String(v)) } catch { /* ignore */ }
  }, [])


  const clearFollowUp = useCallback(() => {
    if (followUpTimerRef.current) {
      clearTimeout(followUpTimerRef.current)
      followUpTimerRef.current = null
    }
  }, [])

  const cancel = useCallback(() => {
    clearFollowUp()
    stopRecording()
    stopSpeech()
    setState('idle')
    setTranscript('')
    setResponse('')
    setError(null)
  }, [clearFollowUp])

  const enterFollowUp = useCallback(() => {
    clearFollowUp()
    setState('follow_up')
    followUpTimerRef.current = setTimeout(() => {
      if (stateRef.current === 'follow_up') {
        setState('idle')
        conversationRef.current = []
        console.log('Voice: Conversation memory cleared (timeout)')
      }
    }, 20000) // 20 seconds to ask follow-up (enough time for response to finish speaking)
  }, [clearFollowUp])

  /**
   * Full voice pipeline:
   * record audio → Whisper STT → Claude NLU → fetch data → Claude response → speak
   */
  const runPipeline = useCallback(async () => {
    const userName = user?.name?.split(' ')[0] || 'there'

    try {
      // 1. Record audio from microphone
      setState('listening')
      setTranscript('')
      setResponse('')
      setError(null)

      console.log('Voice: Recording started...')

      const audioBlob = await new Promise<Blob>((resolve, reject) => {
        const timeout = setTimeout(() => {
          console.log('Voice: Recording timeout, stopping...')
          stopRecording()
          reject(new Error('No speech detected — try speaking closer to the mic'))
        }, 10_000)

        startRecording((blob) => {
          clearTimeout(timeout)
          console.log('Voice: Recording complete, blob size:', blob.size)
          if (blob.size < 500) {
            reject(new Error('No speech detected'))
          } else {
            resolve(blob)
          }
        }).catch((err) => {
          clearTimeout(timeout)
          reject(err)
        })
      })

      if (stateRef.current === 'idle') return

      // 2. Transcribe via Whisper API
      setState('processing')
      console.log('Voice: Sending to Whisper STT...')

      let text: string
      try {
        text = await transcribeAudio(audioBlob)
      } catch (sttErr: any) {
        console.error('Voice: STT failed:', sttErr)
        const status = sttErr?.response?.status
        if (status === 422 || status === 500) {
          setError('Speech-to-text failed. Check OPENAI_API_KEY is set in the server .env file.')
        } else {
          setError('Could not reach the server. Check your internet connection.')
        }
        setState('error')
        setTimeout(() => { if (stateRef.current === 'error') setState('idle') }, 5000)
        return
      }

      if (!text || text.trim().length === 0) {
        setError("I didn't catch that. Click the orb and try again.")
        setState('error')
        setTimeout(() => { if (stateRef.current === 'error') setState('idle') }, 3000)
        return
      }

      console.log('Voice: Transcript:', text)
      setTranscript(text)

      // 3. Classify intent via Claude (with conversation history for follow-ups)
      console.log('Voice: Classifying intent...')
      const nluResult = await classifyIntent(text, userName, conversationRef.current)
      console.log('Voice: Intent:', nluResult.intent, '| Confidence:', nluResult.confidence)

      if (nluResult.confidence < 0.5) {
        const fallbackMsg = `I'm not sure what you mean, ${userName}. Try asking about your tasks, meetings, sprint status, or daily reports.`
        setResponse(fallbackMsg)
        setState('speaking')
        await speak(fallbackMsg)
        enterFollowUp()
        return
      }

      // 4. Fetch data via existing Tessa APIs
      console.log('Voice: Fetching data for intent:', nluResult.intent)
      const userRole = user?.role || 'ops'
      const { data: apiData } = await orchestrate(nluResult.intent, nluResult.params, userRole)

      // 5. Generate spoken response — skip AI call if data fetch errored
      let spokenText: string
      if (apiData && (apiData as any).error === true) {
        spokenText = `Sorry ${userName}, couldn't pull that data right now. Try again in a sec.`
        console.log('Voice: Data error, using short fallback')
      } else {
        console.log('Voice: Generating response...')
        spokenText = await generateSpokenResponse(nluResult.intent, apiData, userName, conversationRef.current)
      }

      if (stateRef.current === 'idle') return

      console.log('Voice: Response:', spokenText)
      setResponse(spokenText)

      // Store exchange in conversation memory for follow-ups
      conversationRef.current.push({ role: 'user', content: text })
      conversationRef.current.push({ role: 'assistant', content: spokenText })
      // Keep only last 6 messages (3 exchanges)
      if (conversationRef.current.length > 6) {
        conversationRef.current = conversationRef.current.slice(-6)
      }
      console.log('Voice: Conversation memory:', conversationRef.current.length, 'messages')

      // 6. Speak aloud
      setState('speaking')
      await speak(spokenText)

      // 7. Wait for follow-up
      enterFollowUp()
    } catch (err) {
      console.error('Voice pipeline error:', err)
      let msg = 'Something went wrong. Please try again.'
      if (err instanceof Error) {
        if (err.message.includes('Permission') || err.message.includes('NotAllowed')) {
          msg = 'Microphone access denied. Go to System Settings > Privacy > Microphone and allow Tessa (or Electron).'
        } else if (err.message.includes('NotFound') || err.message.includes('DevicesNotFound')) {
          msg = 'No microphone found.'
        } else if (err.message.includes('No speech') || err.message.includes('timed out')) {
          msg = "I didn't hear anything. Click the orb and speak your question."
        } else {
          msg = err.message
        }
      }
      setError(msg)
      setState('error')
      setTimeout(() => { if (stateRef.current === 'error') setState('idle') }, 4000)
    }
  }, [user, enterFollowUp])

  const activate = useCallback(() => {
    if (!isEnabled || !isPilotUser) return
    if (stateRef.current === 'follow_up' || stateRef.current === 'idle') {
      playActivationChime() // Chime plays INSTANTLY on activation
      clearFollowUp()
      runPipeline()
    } else if (stateRef.current === 'speaking') {
      playActivationChime()
      stopSpeech()
      clearFollowUp()
      runPipeline()
    }
  }, [isEnabled, isPilotUser, clearFollowUp, runPipeline])

  // Listen for keyboard shortcut from main process
  useEffect(() => {
    if (!isPilotUser || !isEnabled) return
    if (!window.tessaDesktop?.voice?.onActivated) return
    const cleanup = window.tessaDesktop.voice.onActivated(() => activate())
    return cleanup
  }, [isPilotUser, isEnabled, activate])

  // Wake word detection - "Hey Tessa"
  useEffect(() => {
    if (!isPilotUser || !isEnabled || !wakeWordEnabled) {
      stopWakeWordDetection()
      return
    }

    // Start wake word detection (mic monitoring + Whisper check)
    startWakeWordDetection(() => {
      activate()
    })

    return () => {
      stopWakeWordDetection()
    }
  }, [isPilotUser, isEnabled, wakeWordEnabled, activate])

  // Pause/resume wake word detection based on voice state
  useEffect(() => {
    if (!wakeWordEnabled || !isEnabled || !isPilotUser) return

    if (state === 'idle') {
      // Resume wake word after voice interaction finishes
      const timer = setTimeout(() => {
        resumeDetection()
      }, 500)
      return () => clearTimeout(timer)
    } else {
      // Pause wake word while voice assistant is active
      pauseDetection()
    }
  }, [state, wakeWordEnabled])

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      clearFollowUp()
      stopRecording()
      stopSpeech()
    }
  }, [clearFollowUp])

  return (
    <VoiceContext.Provider
      value={{
        state, transcript, response, error,
        activate, cancel,
        isEnabled, setEnabled: handleSetEnabled, isPilotUser
      }}
    >
      {children}
    </VoiceContext.Provider>
  )
}

export function useVoice(): VoiceContextValue {
  const ctx = useContext(VoiceContext)
  if (!ctx) throw new Error('useVoice must be used within VoiceProvider')
  return ctx
}
