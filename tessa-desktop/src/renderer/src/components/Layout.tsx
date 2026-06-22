import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import VoiceAssistant from './voice/VoiceAssistant'

export default function Layout(): JSX.Element {
  return (
    <div className="flex h-screen overflow-hidden bg-surface-0">
      <Sidebar />
      <main className="flex-1 overflow-y-auto">
        <div className="p-5">
          <Outlet />
        </div>
      </main>
      <VoiceAssistant />
    </div>
  )
}
