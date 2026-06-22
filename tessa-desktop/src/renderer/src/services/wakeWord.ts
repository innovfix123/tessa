/**
 * Wake Word Detection - "Hey Tessa"
 *
 * Uses continuous microphone monitoring with audio level detection.
 * When speech is detected, records a short clip and sends to Whisper
 * to check if it contains the wake word.
 *
 * Flow: mic → audio level gate → short recording → Whisper STT → check for "Hey Tessa"
 */

type WakeWordCallback = () => void

let isListening = false
let onWakeWord: WakeWordCallback | null = null
let audioContext: AudioContext | null = null
let analyser: AnalyserNode | null = null
let mediaStream: MediaStream | null = null
let monitorInterval: ReturnType<typeof setInterval> | null = null
let isRecordingClip = false
let isPaused = false

// Tuning - optimized for speed
const SILENCE_THRESHOLD = 12       // RMS level to consider as speech (0-128 range)
const CLIP_DURATION_MS = 1200      // Record 1.2s clip - enough for "Hey Tessa"
const COOLDOWN_MS = 1500           // Wait 1.5s between wake word checks
let lastCheckTime = 0

// Wake word variations
const WAKE_PHRASES = [
  'hey tessa', 'hey tesa', 'hey tesla', 'hi tessa',
  'a tessa', 'ok tessa', 'okay tessa', 'hey tassa',
  'hey tesso', 'hei tessa', 'hey tess'
]

function containsWakeWord(transcript: string): boolean {
  const normalized = transcript.toLowerCase().trim()
  return WAKE_PHRASES.some(phrase => normalized.includes(phrase))
}

/**
 * Get current audio RMS level from analyser
 */
function getAudioLevel(): number {
  if (!analyser) return 0
  const data = new Uint8Array(analyser.fftSize)
  analyser.getByteTimeDomainData(data)

  let sum = 0
  for (let i = 0; i < data.length; i++) {
    const val = data[i] - 128
    sum += val * val
  }
  return Math.sqrt(sum / data.length)
}

/**
 * Record a short audio clip from the active stream
 */
function recordClip(): Promise<Blob> {
  return new Promise((resolve, reject) => {
    if (!mediaStream) {
      reject(new Error('No media stream'))
      return
    }

    const recorder = new MediaRecorder(mediaStream, {
      mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : 'audio/webm'
    })

    const chunks: Blob[] = []

    recorder.ondataavailable = (e) => {
      if (e.data.size > 0) chunks.push(e.data)
    }

    recorder.onstop = () => {
      resolve(new Blob(chunks, { type: recorder.mimeType }))
    }

    recorder.onerror = () => reject(new Error('Recording failed'))

    recorder.start()
    setTimeout(() => {
      if (recorder.state === 'recording') {
        recorder.stop()
      }
    }, CLIP_DURATION_MS)
  })
}

/**
 * Check a short audio clip for the wake word using Whisper
 */
async function checkForWakeWord(clip: Blob): Promise<boolean> {
  try {
    const buffer = await clip.arrayBuffer()
    const result = await window.tessaDesktop.voice.stt(buffer)

    if (result.text && result.text.trim().length > 0) {
      const transcript = result.text.trim()
      if (containsWakeWord(transcript)) {
        return true
      }
    }
  } catch {
    // Silently fail - don't disrupt user
  }
  return false
}

/**
 * Monitor loop - runs every 200ms to check audio levels
 */
function startMonitorLoop(): void {
  monitorInterval = setInterval(async () => {
    if (isPaused || isRecordingClip || !isListening) return

    // Cooldown between checks
    const now = Date.now()
    if (now - lastCheckTime < COOLDOWN_MS) return

    const level = getAudioLevel()

    // Speech detected above threshold
    if (level > SILENCE_THRESHOLD) {
      isRecordingClip = true
      lastCheckTime = now

      try {
        const clip = await recordClip()

        // Don't check if we got paused/stopped during recording
        if (!isListening || isPaused) {
          isRecordingClip = false
          return
        }

        const detected = await checkForWakeWord(clip)

        if (detected && isListening && !isPaused) {
          pauseDetection()
          if (onWakeWord) {
            onWakeWord()
          }
        }
      } catch {
        // Silently continue
      }

      isRecordingClip = false
    }
  }, 150)
}

/**
 * Start listening for "Hey Tessa"
 */
export async function startWakeWordDetection(callback: WakeWordCallback): Promise<boolean> {
  if (isListening) return true

  try {
    // Get microphone access
    mediaStream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    })

    // Set up audio analysis for level detection
    audioContext = new AudioContext()
    const source = audioContext.createMediaStreamSource(mediaStream)
    analyser = audioContext.createAnalyser()
    analyser.fftSize = 512
    source.connect(analyser)
    // Don't connect to destination - we don't want to play back the mic

    onWakeWord = callback
    isListening = true
    isPaused = false
    isRecordingClip = false

    startMonitorLoop()

    return true
  } catch (e) {
    console.warn('Voice: Could not start wake word detection -', (e as Error).message)
    return false
  }
}

/**
 * Pause detection and release the mic (so voice recorder can use it)
 */
export function pauseDetection(): void {
  isPaused = true

  // Release the mic so voiceRecorder can acquire it without conflict
  if (mediaStream) {
    mediaStream.getTracks().forEach(t => t.stop())
    mediaStream = null
  }
  if (audioContext) {
    audioContext.close().catch(() => {})
    audioContext = null
    analyser = null
  }
}

/**
 * Resume detection - re-acquire mic
 */
export async function resumeDetection(): Promise<void> {
  if (!isListening || !isPaused) return
  isPaused = false
  lastCheckTime = Date.now()

  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    })
    audioContext = new AudioContext()
    const source = audioContext.createMediaStreamSource(mediaStream)
    analyser = audioContext.createAnalyser()
    analyser.fftSize = 512
    source.connect(analyser)
  } catch {
    // Mic unavailable - will retry on next resume
  }
}

/**
 * Stop wake word detection completely
 */
export function stopWakeWordDetection(): void {
  isListening = false
  isPaused = false
  onWakeWord = null

  if (monitorInterval) {
    clearInterval(monitorInterval)
    monitorInterval = null
  }

  if (mediaStream) {
    mediaStream.getTracks().forEach(t => t.stop())
    mediaStream = null
  }

  if (audioContext) {
    audioContext.close().catch(() => {})
    audioContext = null
    analyser = null
  }
}

/**
 * Check if wake word detection is active
 */
export function isWakeWordActive(): boolean {
  return isListening
}
