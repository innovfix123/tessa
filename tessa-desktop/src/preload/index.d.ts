import { ElectronAPI } from '@electron-toolkit/preload'

interface TessaDesktopAPI {
  getVersion: () => Promise<string>
  getPlatform: () => Promise<string>
  reload: () => Promise<void>
  notify: (title: string, body: string) => Promise<void>
  autoLaunch: {
    isEnabled: () => Promise<boolean>
    toggle: (enable: boolean) => Promise<boolean>
  }
  voice: {
    onActivated: (callback: () => void) => () => void
    stt: (audioBuffer: ArrayBuffer) => Promise<{ text: string; error?: string }>
    nlu: (transcript: string, userName: string, openRouterKey: string, history: Array<{ role: string; content: string }>) => Promise<{ intent: string; params: Record<string, string>; confidence: number; error?: string }>
    respond: (intent: string, apiData: unknown, userName: string, openRouterKey: string, history: Array<{ role: string; content: string }>) => Promise<{ response: string }>
    tts: (text: string) => Promise<{ audio?: ArrayBuffer; error?: string }>
  }
}

declare global {
  interface Window {
    electron: ElectronAPI
    tessaDesktop: TessaDesktopAPI
  }
}
