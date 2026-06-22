import VoiceOrb from './VoiceOrb'
import VoiceTranscript from './VoiceTranscript'

/**
 * Voice Assistant container.
 * Renders for all authenticated users. Composes the floating orb and transcript panel.
 */
export default function VoiceAssistant(): JSX.Element {
  return (
    <>
      <VoiceTranscript />
      <VoiceOrb />
    </>
  )
}
