/**
 * Voice Recorder Service
 *
 * Records audio from the microphone with simple volume-based silence detection.
 * No external dependencies — uses Web Audio API's AnalyserNode.
 */

type RecordingCallback = (audioBlob: Blob) => void

let mediaRecorder: MediaRecorder | null = null
let audioChunks: Blob[] = []
let recordingTimeout: ReturnType<typeof setTimeout> | null = null
let silenceCheckInterval: ReturnType<typeof setInterval> | null = null
let mediaStream: MediaStream | null = null
let audioContext: AudioContext | null = null

const MAX_RECORDING_MS = 8_000 // 8 second max recording
const SILENCE_THRESHOLD = 0.012 // Volume level below which = silence
const SILENCE_DURATION_MS = 1200 // 1.2 seconds of silence to stop
const MIN_RECORDING_MS = 600 // Start checking silence after 0.6s

/**
 * Start recording audio from the microphone.
 * Auto-stops when silence is detected (volume drops below threshold for 1.8s).
 */
export async function startRecording(onComplete: RecordingCallback): Promise<void> {
  audioChunks = []

  // Get microphone access
  mediaStream = await navigator.mediaDevices.getUserMedia({
    audio: {
      echoCancellation: true,
      noiseSuppression: true,
      sampleRate: 16000
    }
  })

  // Create MediaRecorder
  const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
    ? 'audio/webm;codecs=opus'
    : 'audio/webm'

  mediaRecorder = new MediaRecorder(mediaStream, { mimeType })

  mediaRecorder.ondataavailable = (event): void => {
    if (event.data.size > 0) {
      audioChunks.push(event.data)
    }
  }

  mediaRecorder.onstop = (): void => {
    const blob = new Blob(audioChunks, { type: mimeType })
    audioChunks = []
    cleanup()
    onComplete(blob)
  }

  mediaRecorder.start(250)

  // Safety timeout
  recordingTimeout = setTimeout(() => {
    console.log('Voice: Max recording time reached, stopping')
    stopRecording()
  }, MAX_RECORDING_MS)

  // Set up silence detection using AudioContext analyser
  setupSilenceDetection(mediaStream)
}

/**
 * Stop recording manually.
 */
export function stopRecording(): void {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop()
  }
  clearTimers()
}

/**
 * Check if currently recording.
 */
export function isRecording(): boolean {
  return mediaRecorder !== null && mediaRecorder.state === 'recording'
}

/**
 * Simple silence detection using Web Audio API AnalyserNode.
 * Checks audio volume every 100ms. If volume stays below threshold
 * for SILENCE_DURATION_MS, stops recording.
 */
function setupSilenceDetection(stream: MediaStream): void {
  try {
    audioContext = new AudioContext()
    const source = audioContext.createMediaStreamSource(stream)
    const analyser = audioContext.createAnalyser()
    analyser.fftSize = 512
    source.connect(analyser)

    const dataArray = new Float32Array(analyser.fftSize)
    let silenceStart: number | null = null
    const recordingStart = Date.now()

    silenceCheckInterval = setInterval(() => {
      if (!mediaRecorder || mediaRecorder.state !== 'recording') {
        clearTimers()
        return
      }

      analyser.getFloatTimeDomainData(dataArray)

      // Calculate RMS volume
      let sum = 0
      for (let i = 0; i < dataArray.length; i++) {
        sum += dataArray[i] * dataArray[i]
      }
      const rms = Math.sqrt(sum / dataArray.length)

      const elapsed = Date.now() - recordingStart

      if (rms < SILENCE_THRESHOLD && elapsed > MIN_RECORDING_MS) {
        // Silence detected
        if (!silenceStart) {
          silenceStart = Date.now()
        } else if (Date.now() - silenceStart > SILENCE_DURATION_MS) {
          console.log('Voice: Silence detected, stopping recording')
          stopRecording()
        }
      } else {
        // Sound detected — reset silence timer
        silenceStart = null
      }
    }, 100)
  } catch (e) {
    console.warn('Voice: Could not set up silence detection:', e)
  }
}

function clearTimers(): void {
  if (recordingTimeout) {
    clearTimeout(recordingTimeout)
    recordingTimeout = null
  }
  if (silenceCheckInterval) {
    clearInterval(silenceCheckInterval)
    silenceCheckInterval = null
  }
}

function cleanup(): void {
  clearTimers()
  if (audioContext) {
    try { audioContext.close() } catch { /* ignore */ }
    audioContext = null
  }
  if (mediaStream) {
    mediaStream.getTracks().forEach((t) => t.stop())
    mediaStream = null
  }
  mediaRecorder = null
}
