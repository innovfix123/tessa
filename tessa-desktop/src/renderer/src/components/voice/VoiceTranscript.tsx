import { useEffect, useState } from 'react'
import { useVoice } from '@/contexts/VoiceContext'
import { Mic, Bot } from 'lucide-react'

export default function VoiceTranscript(): JSX.Element | null {
  const { state, transcript, response, error } = useVoice()
  const isActive = state !== 'idle'
  const hasContent = !!(transcript || response || error)

  // Show immediately when active - no effect delay
  const [fadeOut, setFadeOut] = useState(false)
  const [dismissed, setDismissed] = useState(true)

  // Reset dismissed flag as soon as voice becomes active
  if (isActive && dismissed) {
    setDismissed(false)
    setFadeOut(false)
  }

  // Auto-hide 5s after going idle
  useEffect(() => {
    if (state === 'idle' && !dismissed) {
      const timer = setTimeout(() => {
        setFadeOut(true)
        setTimeout(() => setDismissed(true), 300)
      }, 5000)
      return () => clearTimeout(timer)
    }
  }, [state, dismissed])

  // Don't render when dismissed and idle
  if (dismissed && !isActive) return null

  // During listening with no content yet - show compact indicator
  const showListening = state === 'listening' && !hasContent
  // During processing with no response yet
  const showProcessing = state === 'processing' && !response

  return (
    <div
      className={`fixed bottom-24 right-6 z-50 max-w-sm w-80 transition-opacity duration-300 ${fadeOut ? 'opacity-0' : 'opacity-100'}`}
    >
      <div className="bg-surface-3 border border-zinc-700/60 rounded-2xl shadow-2xl shadow-black/40 overflow-hidden backdrop-blur-sm">
        {/* Listening indicator */}
        {showListening && (
          <div className="px-4 py-3">
            <div className="flex items-center gap-3 text-sm text-zinc-400">
              <div className="flex gap-1">
                <div className="w-2 h-2 rounded-full bg-blue-400 animate-pulse" />
                <div className="w-2 h-2 rounded-full bg-blue-400 animate-pulse" style={{ animationDelay: '200ms' }} />
                <div className="w-2 h-2 rounded-full bg-blue-400 animate-pulse" style={{ animationDelay: '400ms' }} />
              </div>
              Listening...
            </div>
          </div>
        )}

        {/* User transcript */}
        {transcript && (
          <div className={`px-4 py-3 ${response || showProcessing ? 'border-b border-zinc-700/40' : ''}`}>
            <div className="flex items-start gap-2.5">
              <Mic className="w-4 h-4 text-blue-400 mt-0.5 shrink-0" />
              <p className="text-sm text-zinc-300">{transcript}</p>
            </div>
          </div>
        )}

        {/* Processing indicator */}
        {showProcessing && (
          <div className="px-4 py-3">
            <div className="flex items-center gap-3 text-sm text-zinc-400">
              <div className="flex gap-1">
                <div className="w-1.5 h-1.5 rounded-full bg-brand-400 animate-bounce" style={{ animationDelay: '0ms' }} />
                <div className="w-1.5 h-1.5 rounded-full bg-brand-400 animate-bounce" style={{ animationDelay: '150ms' }} />
                <div className="w-1.5 h-1.5 rounded-full bg-brand-400 animate-bounce" style={{ animationDelay: '300ms' }} />
              </div>
              Thinking...
            </div>
          </div>
        )}

        {/* Tessa response */}
        {response && (
          <div className="px-4 py-3">
            <div className="flex items-start gap-2.5">
              <Bot className="w-4 h-4 text-emerald-400 mt-0.5 shrink-0" />
              <p className="text-sm text-zinc-200 leading-relaxed">{response}</p>
            </div>
          </div>
        )}

        {/* Error */}
        {error && (
          <div className="px-4 py-3">
            <p className="text-sm text-red-400">{error}</p>
          </div>
        )}
      </div>
    </div>
  )
}
