import { BrowserWindow, globalShortcut } from 'electron'

export function registerVoiceShortcut(getWindow: () => BrowserWindow | null): void {
  for (const shortcut of ['CommandOrControl+Shift+V', 'CommandOrControl+Shift+T']) {
    try {
      const ok = globalShortcut.register(shortcut, () => {
        const win = getWindow()
        if (win && !win.isDestroyed()) {
          console.log(`Voice: Shortcut ${shortcut} triggered`)
          win.webContents.send('voice:activated')
          if (win.isMinimized()) win.restore()
          win.focus()
        }
      })
      if (ok) { console.log(`Voice: Registered shortcut ${shortcut}`); break }
    } catch { /* try next */ }
  }
}

export function unregisterVoiceShortcut(): void {
  try { globalShortcut.unregisterAll() } catch { /* ignore */ }
}
