/**
 * Text-to-Speech Service
 *
 * Uses ElevenLabs for high-quality AI voice (Tessa voice).
 * Falls back to browser speechSynthesis if ElevenLabs fails.
 */

let currentUtterance: SpeechSynthesisUtterance | null = null
let currentAudio: HTMLAudioElement | null = null

/**
 * Speak text using ElevenLabs TTS, with fallback to browser TTS.
 */
export async function speak(text: string): Promise<void> {
  // Cancel any ongoing speech
  stop()

  // Try ElevenLabs first
  try {
    const result = await window.tessaDesktop.voice.tts(text)

    if (result.audio && result.audio.byteLength > 0) {
      console.log('Voice: Using ElevenLabs TTS')
      await playAudioBuffer(result.audio)
      return
    }

    if (result.error) {
      console.warn('Voice: ElevenLabs error, falling back to browser TTS:', result.error)
    }
  } catch (e) {
    console.warn('Voice: ElevenLabs unavailable, falling back to browser TTS:', e)
  }

  // Fallback to browser TTS
  return speakWithBrowser(text)
}

/**
 * Play audio buffer from ElevenLabs response.
 */
function playAudioBuffer(buffer: ArrayBuffer): Promise<void> {
  return new Promise((resolve, reject) => {
    const blob = new Blob([buffer], { type: 'audio/mpeg' })
    const url = URL.createObjectURL(blob)
    const audio = new Audio(url)
    currentAudio = audio

    audio.onended = () => {
      URL.revokeObjectURL(url)
      currentAudio = null
      resolve()
    }

    audio.onerror = (e) => {
      URL.revokeObjectURL(url)
      currentAudio = null
      reject(new Error('Audio playback error'))
    }

    audio.play().catch(reject)
  })
}

/**
 * Fallback: Speak text using browser's speech synthesis.
 */
function speakWithBrowser(text: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (!window.speechSynthesis) {
      reject(new Error('Speech synthesis not available'))
      return
    }

    const utterance = new SpeechSynthesisUtterance(text)
    currentUtterance = utterance

    // Try to pick a high-quality English voice
    const voices = window.speechSynthesis.getVoices()
    const voicePreferences = [
      'samantha', 'karen', 'alex', 'fiona', 'moira',
      'zira', 'david', 'mark', 'hazel',
      'google', 'enhanced', 'premium', 'natural', 'neural'
    ]

    const preferred = voices.find((v) => {
      if (!v.lang.startsWith('en')) return false
      const name = v.name.toLowerCase()
      return voicePreferences.some(pref => name.includes(pref))
    })
    const englishVoice = preferred || voices.find((v) => v.lang.startsWith('en'))

    console.log('Voice: Using browser TTS voice:', englishVoice?.name || 'default')

    if (englishVoice) {
      utterance.voice = englishVoice
    }

    utterance.rate = 1.0
    utterance.pitch = 1.0
    utterance.volume = 1.0

    utterance.onend = (): void => {
      currentUtterance = null
      resolve()
    }

    utterance.onerror = (event): void => {
      currentUtterance = null
      if (event.error === 'canceled' || event.error === 'interrupted') {
        resolve()
      } else {
        reject(new Error(`Speech synthesis error: ${event.error}`))
      }
    }

    window.speechSynthesis.speak(utterance)
  })
}

/**
 * Stop any currently playing speech (both ElevenLabs and browser TTS).
 */
export function stop(): void {
  // Stop ElevenLabs audio
  if (currentAudio) {
    currentAudio.pause()
    currentAudio.currentTime = 0
    currentAudio = null
  }

  // Stop browser TTS
  if (window.speechSynthesis) {
    window.speechSynthesis.cancel()
  }
  currentUtterance = null
}

/**
 * Check if currently speaking.
 */
export function isSpeaking(): boolean {
  return !!(currentAudio && !currentAudio.paused) || (window.speechSynthesis?.speaking ?? false)
}

// Pre-load voices (some browsers need this before first use)
if (typeof window !== 'undefined' && window.speechSynthesis) {
  window.speechSynthesis.getVoices()
  window.speechSynthesis.onvoiceschanged = (): void => {
    window.speechSynthesis.getVoices()
  }
}
