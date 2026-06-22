import { contextBridge, ipcRenderer } from 'electron'
import { electronAPI } from '@electron-toolkit/preload'

const tessaDesktop = {
  getVersion: (): Promise<string> => ipcRenderer.invoke('app:version'),
  getPlatform: (): Promise<string> => ipcRenderer.invoke('app:platform'),
  reload: (): Promise<void> => ipcRenderer.invoke('app:reload'),
  notify: (title: string, body: string): Promise<void> =>
    ipcRenderer.invoke('app:notify', title, body),
  autoLaunch: {
    isEnabled: (): Promise<boolean> => ipcRenderer.invoke('app:autolaunch:status'),
    toggle: (enable: boolean): Promise<boolean> =>
      ipcRenderer.invoke('app:autolaunch:toggle', enable)
  },
  voice: {
    onActivated: (callback: () => void): (() => void) => {
      const handler = (): void => callback()
      ipcRenderer.on('voice:activated', handler)
      return () => ipcRenderer.removeListener('voice:activated', handler)
    },
    stt: (audioBuffer: ArrayBuffer): Promise<{ text: string; error?: string }> =>
      ipcRenderer.invoke('voice:stt', audioBuffer),
    nlu: (transcript: string, userName: string, openRouterKey: string, history: Array<{ role: string; content: string }>): Promise<{ intent: string; params: Record<string, string>; confidence: number; error?: string }> =>
      ipcRenderer.invoke('voice:nlu', transcript, userName, openRouterKey, history),
    respond: (intent: string, apiData: unknown, userName: string, openRouterKey: string, history: Array<{ role: string; content: string }>): Promise<{ response: string }> =>
      ipcRenderer.invoke('voice:respond', intent, apiData, userName, openRouterKey, history),
    tts: (text: string): Promise<{ audio?: ArrayBuffer; error?: string }> =>
      ipcRenderer.invoke('voice:tts', text)
  }
}

if (process.contextIsolated) {
  try {
    contextBridge.exposeInMainWorld('electron', electronAPI)
    contextBridge.exposeInMainWorld('tessaDesktop', tessaDesktop)
  } catch (error) {
    console.error(error)
  }
} else {
  // @ts-ignore fallback
  window.electron = electronAPI
  // @ts-ignore fallback
  window.tessaDesktop = tessaDesktop
}
