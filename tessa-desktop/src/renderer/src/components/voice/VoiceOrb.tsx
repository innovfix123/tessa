import { Mic, MicOff, Loader2, Volume2, AlertCircle } from 'lucide-react'
import { useVoice } from '@/contexts/VoiceContext'
import type { VoiceState } from '@/lib/types'

const stateConfig: Record<VoiceState, { size: number; gradient: string; glow: string }> = {
  idle: {
    size: 56,
    gradient: 'from-brand-500 to-brand-600',
    glow: 'shadow-brand-500/30'
  },
  listening: {
    size: 64,
    gradient: 'from-blue-400 to-blue-600',
    glow: 'shadow-blue-500/50'
  },
  processing: {
    size: 64,
    gradient: 'from-brand-500 to-purple-600',
    glow: 'shadow-purple-500/40'
  },
  speaking: {
    size: 64,
    gradient: 'from-emerald-400 to-emerald-600',
    glow: 'shadow-emerald-500/40'
  },
  follow_up: {
    size: 60,
    gradient: 'from-brand-400 to-brand-600',
    glow: 'shadow-brand-500/30'
  },
  error: {
    size: 60,
    gradient: 'from-red-500 to-red-600',
    glow: 'shadow-red-500/40'
  }
}

function StateIcon({ state }: { state: VoiceState }): JSX.Element {
  const iconClass = 'text-white drop-shadow-sm'
  switch (state) {
    case 'idle':
    case 'follow_up':
      return <Mic className={`w-6 h-6 ${iconClass}`} />
    case 'listening':
      return <Mic className={`w-7 h-7 ${iconClass}`} />
    case 'processing':
      return <Loader2 className={`w-7 h-7 ${iconClass} animate-spin`} />
    case 'speaking':
      return <Volume2 className={`w-7 h-7 ${iconClass}`} />
    case 'error':
      return <AlertCircle className={`w-6 h-6 ${iconClass}`} />
  }
}

export default function VoiceOrb(): JSX.Element {
  const { state, activate, cancel, isEnabled, setEnabled } = useVoice()
  const config = stateConfig[state]
  const isActive = state !== 'idle'
  const isListening = state === 'listening'

  const handleClick = (): void => {
    if (state === 'idle' || state === 'follow_up') {
      activate()
    } else {
      cancel()
    }
  }

  const handleRightClick = (e: React.MouseEvent): void => {
    e.preventDefault()
    setEnabled(!isEnabled)
  }

  if (!isEnabled) {
    return (
      <button
        onClick={() => setEnabled(true)}
        className="fixed bottom-6 right-6 z-50 w-10 h-10 rounded-full bg-zinc-800/80 backdrop-blur-sm
          border border-zinc-700/50 flex items-center justify-center opacity-50 hover:opacity-80
          transition-all duration-300 cursor-pointer hover:scale-105"
        title="Voice assistant disabled. Click to enable."
      >
        <MicOff className="w-4 h-4 text-zinc-400" />
      </button>
    )
  }

  return (
    <div className="fixed bottom-6 right-6 z-50 flex flex-col items-center">
      {/* Wake word indicator - small dot showing "Hey Tessa" is always listening */}
      {state === 'idle' && (
        <div className="absolute -top-1 -right-1 z-10">
          <div className="w-3 h-3 rounded-full bg-emerald-500 border-2 border-zinc-900 shadow-sm voice-wake-pulse" />
        </div>
      )}

      {/* Outer glow ring for listening state */}
      {isListening && (
        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
          <div className="absolute w-24 h-24 rounded-full bg-blue-500/10 voice-glow-pulse" />
          <div className="absolute w-20 h-20 rounded-full border border-blue-400/30 voice-ring-expand-1" />
          <div className="absolute w-20 h-20 rounded-full border border-blue-400/20 voice-ring-expand-2" />
          <div className="absolute w-20 h-20 rounded-full border border-blue-400/10 voice-ring-expand-3" />
        </div>
      )}

      {/* Speaking pulse rings */}
      {state === 'speaking' && (
        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
          <div className="absolute w-20 h-20 rounded-full border border-emerald-400/20 voice-speak-ring" />
        </div>
      )}

      {/* The orb button */}
      <button
        onClick={handleClick}
        onContextMenu={handleRightClick}
        style={{ width: config.size, height: config.size }}
        className={`
          rounded-full bg-gradient-to-br ${config.gradient}
          flex items-center justify-center
          shadow-lg ${config.glow}
          transition-all duration-300 ease-out
          cursor-pointer select-none
          hover:brightness-110
          ${isListening ? 'voice-orb-listening' : ''}
          ${state === 'speaking' ? 'voice-orb-speaking' : ''}
          ${state === 'idle' ? 'voice-orb-idle hover:scale-105' : ''}
          ${state === 'error' ? 'voice-orb-shake' : ''}
        `}
        title={
          state === 'idle'
            ? 'Say "Hey Tessa" or click to talk'
            : state === 'listening'
              ? 'Listening...'
              : state === 'processing'
                ? 'Processing...'
                : state === 'speaking'
                  ? 'Speaking...'
                  : state === 'follow_up'
                    ? 'Ask another question...'
                    : 'Error'
        }
      >
        <StateIcon state={state} />
      </button>
    </div>
  )
}
