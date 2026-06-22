import {
  app,
  shell,
  BrowserWindow,
  ipcMain,
  Menu,
  Tray,
  nativeImage,
  Notification,
  session,
  systemPreferences
} from 'electron'
import { join, extname } from 'path'
import { createServer, IncomingMessage, ServerResponse } from 'http'
import { request as httpsRequest, RequestOptions } from 'https'
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'fs'
import { electronApp, optimizer, is } from '@electron-toolkit/utils'
import AutoLaunch from 'auto-launch'
import { registerVoiceShortcut, unregisterVoiceShortcut } from './voiceHandlers'

const TESSA_HOST = 'tessa.innovfix.ai'
const TESSA_URL = `https://${TESSA_HOST}`
const autoLauncher = new AutoLaunch({ name: 'Tessa', isHidden: true })
const CONFIG_PATH = join(app.getPath('userData'), 'tessa-config.json')

let mainWindow: BrowserWindow | null = null
let tray: Tray | null = null
let isQuitting = false
let localPort = 0

// ── MIME types ──

const MIME: Record<string, string> = {
  '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css',
  '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff': 'font/woff',
  '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.map': 'application/json'
}

// ── Reverse proxy: forwards /api/* and /__portal/* to tessa.innovfix.ai ──
// Rewrites cookie Domain to localhost so they're same-origin.
// This is the production equivalent of the Vite dev proxy.

function proxyRequest(
  clientReq: IncomingMessage,
  clientRes: ServerResponse,
  path: string
): void {
  // /__portal/foo → /foo on the server (same as Vite rewrite)
  let targetPath = path
  if (path.startsWith('/__portal')) {
    targetPath = path.replace(/^\/__portal/, '') || '/'
  }

  // Collect the request body (for POST/PUT/PATCH)
  const bodyChunks: Buffer[] = []
  clientReq.on('data', (chunk: Buffer) => bodyChunks.push(chunk))
  clientReq.on('end', () => {
    const body = Buffer.concat(bodyChunks)

    const headers: Record<string, string | string[] | undefined> = {}
    // Forward all client headers except host
    for (const [key, val] of Object.entries(clientReq.headers)) {
      if (key.toLowerCase() !== 'host' && key.toLowerCase() !== 'origin' && key.toLowerCase() !== 'referer') {
        headers[key] = val
      }
    }
    headers['host'] = TESSA_HOST
    headers['origin'] = TESSA_URL
    headers['referer'] = TESSA_URL + targetPath
    if (body.length > 0) {
      headers['content-length'] = String(body.length)
    }

    const options: RequestOptions = {
      hostname: TESSA_HOST,
      port: 443,
      path: targetPath,
      method: clientReq.method || 'GET',
      headers: headers as Record<string, string>
    }

    const proxyReq = httpsRequest(options, (proxyRes) => {
      const resHeaders: Record<string, string | string[]> = {}

      // Copy response headers, rewriting cookies
      for (const [key, val] of Object.entries(proxyRes.headers)) {
        if (!val) continue
        if (key.toLowerCase() === 'set-cookie') {
          const cookies = Array.isArray(val) ? val : [val]
          resHeaders[key] = cookies.map((cookie) =>
            cookie
              .replace(/;\s*Domain=[^;]*/gi, '')
              .replace(/;\s*SameSite=[^;]*/gi, '; SameSite=Lax')
              .replace(/;\s*Secure/gi, '')
          )
        } else if (key.toLowerCase() === 'location') {
          // Rewrite redirect URLs from tessa.innovfix.ai back to localhost
          const locVal = Array.isArray(val) ? val[0] : val
          resHeaders[key] = locVal.replace(TESSA_URL, `http://127.0.0.1:${localPort}`)
        } else {
          resHeaders[key] = val
        }
      }

      clientRes.writeHead(proxyRes.statusCode || 200, resHeaders)
      proxyRes.pipe(clientRes)
    })

    proxyReq.on('error', (err) => {
      console.error('[Proxy] Request failed:', err.message)
      clientRes.writeHead(502)
      clientRes.end(JSON.stringify({ error: 'Proxy error: ' + err.message }))
    })

    if (body.length > 0) {
      proxyReq.write(body)
    }
    proxyReq.end()
  })
}

// ── Local HTTP server ──
// Serves renderer files + proxies /api and /__portal to the real server.
// Everything is same-origin (http://127.0.0.1:PORT) so cookies work perfectly.

function startLocalServer(): Promise<number> {
  return new Promise((resolve) => {
    const rendererDir = join(__dirname, '../renderer')

    const server = createServer((req, res) => {
      const url = (req.url || '/').split('?')[0]
      const fullUrl = req.url || '/'

      // Proxy API and portal config requests to tessa.innovfix.ai
      if (fullUrl.startsWith('/api/') || fullUrl.startsWith('/api?') ||
          fullUrl.startsWith('/__portal')) {
        proxyRequest(req, res, fullUrl)
        return
      }

      // Serve static renderer files
      let filePath = url === '/' ? '/index.html' : url
      const fullPath = join(rendererDir, filePath)
      const ext = extname(fullPath)
      const contentType = MIME[ext] || 'application/octet-stream'

      try {
        const data = readFileSync(fullPath)
        res.writeHead(200, { 'Content-Type': contentType })
        res.end(data)
      } catch {
        // SPA fallback — serve index.html for any path (React Router handles it)
        try {
          const indexData = readFileSync(join(rendererDir, 'index.html'))
          res.writeHead(200, { 'Content-Type': 'text/html' })
          res.end(indexData)
        } catch {
          res.writeHead(404)
          res.end('Not found')
        }
      }
    })

    server.listen(0, '127.0.0.1', () => {
      const addr = server.address()
      const port = typeof addr === 'object' && addr ? addr.port : 0
      console.log(`[Tessa] Local server running on http://127.0.0.1:${port}`)
      resolve(port)
    })
  })
}

// ── Persistent config (window bounds) ──

function loadConfig(): Record<string, unknown> {
  try {
    if (existsSync(CONFIG_PATH)) return JSON.parse(readFileSync(CONFIG_PATH, 'utf-8'))
  } catch { /* reset on corruption */ }
  return {}
}

function saveConfig(data: Record<string, unknown>): void {
  const dir = app.getPath('userData')
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true })
  writeFileSync(CONFIG_PATH, JSON.stringify(data, null, 2))
}

function getWindowBounds(): Electron.Rectangle | undefined {
  return loadConfig().windowBounds as Electron.Rectangle | undefined
}

function saveWindowBounds(bounds: Electron.Rectangle): void {
  const cfg = loadConfig()
  cfg.windowBounds = bounds
  saveConfig(cfg)
}

// ── Window ──

function createWindow(): void {
  const saved = getWindowBounds()

  mainWindow = new BrowserWindow({
    width: saved?.width ?? 1400,
    height: saved?.height ?? 900,
    x: saved?.x,
    y: saved?.y,
    minWidth: 900,
    minHeight: 600,
    title: 'Tessa',
    autoHideMenuBar: process.platform !== 'darwin',
    show: false,
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      sandbox: false,
      contextIsolation: true,
      spellcheck: true
    }
  })

  mainWindow.on('page-title-updated', (event) => {
    event.preventDefault()
  })

  // Load the React renderer
  if (is.dev && process.env['ELECTRON_RENDERER_URL']) {
    mainWindow.loadURL(process.env['ELECTRON_RENDERER_URL'])
  } else {
    // Production: load from local server (same-origin, cookies work)
    mainWindow.loadURL(`http://127.0.0.1:${localPort}`)
  }

  mainWindow.once('ready-to-show', () => mainWindow?.show())

  // External links open in default browser
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('http') && !url.includes('127.0.0.1')) {
      shell.openExternal(url)
      return { action: 'deny' }
    }
    return { action: 'allow' }
  })

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (url.startsWith('http') && !url.includes('localhost') && !url.includes('127.0.0.1')) {
      event.preventDefault()
      shell.openExternal(url)
    }
  })

  mainWindow.on('close', (event) => {
    if (mainWindow) saveWindowBounds(mainWindow.getBounds())
    if (process.platform === 'darwin' && !isQuitting) {
      event.preventDefault()
      mainWindow?.hide()
    }
  })

  mainWindow.on('closed', () => {
    mainWindow = null
  })
}

// ── System tray ──

function createTray(): void {
  const icon = nativeImage.createEmpty()
  tray = new Tray(icon)
  tray.setToolTip('Tessa — InnovFix Portal')

  let launchAtStartup = false
  autoLauncher.isEnabled().then((v) => { launchAtStartup = v }).catch(() => {})

  const contextMenu = Menu.buildFromTemplate([
    { label: 'Show Tessa', click: (): void => { mainWindow?.show(); mainWindow?.focus() } },
    { label: 'Reload', click: (): void => { mainWindow?.webContents.reload() } },
    { type: 'separator' },
    {
      label: 'Launch at Startup', type: 'checkbox', checked: launchAtStartup,
      click: (menuItem): void => {
        if (menuItem.checked) autoLauncher.enable().catch(() => {})
        else autoLauncher.disable().catch(() => {})
      }
    },
    { type: 'separator' },
    { label: 'Quit Tessa', click: (): void => { isQuitting = true; app.quit() } }
  ])
  tray.setContextMenu(contextMenu)
  tray.on('click', () => {
    if (mainWindow?.isVisible()) mainWindow.focus()
    else { mainWindow?.show(); mainWindow?.focus() }
  })
}

// ── App menu ──

function createAppMenu(): void {
  const template: Electron.MenuItemConstructorOptions[] = [
    {
      label: app.name,
      submenu: [
        { role: 'about' }, { type: 'separator' },
        { label: 'Reload', accelerator: 'CmdOrCtrl+R', click: (): void => { mainWindow?.webContents.reload() } },
        { type: 'separator' }, { role: 'hide' }, { role: 'hideOthers' }, { role: 'unhide' },
        { type: 'separator' }, { role: 'quit' }
      ]
    },
    { label: 'Edit', submenu: [{ role: 'undo' }, { role: 'redo' }, { type: 'separator' }, { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' }] },
    { label: 'View', submenu: [{ role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { type: 'separator' }, { role: 'togglefullscreen' }] },
    { label: 'Window', submenu: [{ role: 'minimize' }, { role: 'zoom' }, { label: 'Close', accelerator: 'CmdOrCtrl+W', click: (): void => { if (process.platform === 'darwin') mainWindow?.hide(); else mainWindow?.close() } }, { type: 'separator' }, { role: 'front' }] }
  ]
  if (is.dev) {
    const viewMenu = template.find((t) => t.label === 'View')
    if (viewMenu && Array.isArray(viewMenu.submenu)) {
      viewMenu.submenu.push({ type: 'separator' }, { role: 'toggleDevTools' })
    }
  }
  Menu.setApplicationMenu(Menu.buildFromTemplate(template))
}

// ── Lifecycle ──

app.whenReady().then(async () => {
  electronApp.setAppUserModelId('ai.innovfix.tessa')

  // Grant microphone permission for voice assistant
  session.defaultSession.setPermissionRequestHandler((_webContents, permission, callback) => {
    const allowed = ['media', 'microphone', 'audioCapture']
    callback(allowed.includes(permission))
  })
  session.defaultSession.setPermissionCheckHandler((_webContents, permission) => {
    const allowed = ['media', 'microphone', 'audioCapture']
    return allowed.includes(permission)
  })

  // Request macOS microphone access early
  if (process.platform === 'darwin' && systemPreferences.askForMediaAccess) {
    systemPreferences.askForMediaAccess('microphone').catch(() => {})
  }

  // Start local server with API proxy in production
  if (!is.dev) {
    localPort = await startLocalServer()
  }

  app.on('browser-window-created', (_, window) => { optimizer.watchWindowShortcuts(window) })

  ipcMain.handle('app:version', () => app.getVersion())
  ipcMain.handle('app:platform', () => process.platform)
  ipcMain.handle('app:reload', () => mainWindow?.webContents.reload())
  ipcMain.handle('app:autolaunch:status', async () => { try { return await autoLauncher.isEnabled() } catch { return false } })
  ipcMain.handle('app:autolaunch:toggle', async (_event, enable: boolean) => { try { if (enable) await autoLauncher.enable(); else await autoLauncher.disable(); return true } catch { return false } })
  ipcMain.handle('app:notify', (_event, title: string, body: string) => {
    if (Notification.isSupported()) {
      const notif = new Notification({ title, body })
      notif.on('click', () => { mainWindow?.show(); mainWindow?.focus() })
      notif.show()
    }
  })

  // ── Voice Assistant IPC handlers ──
  const OPENAI_KEY = process.env.OPENAI_KEY || ''
  const OPENROUTER_KEY = process.env.OPENROUTER_KEY || ''
  const ELEVENLABS_KEY = process.env.ELEVENLABS_KEY || ''
  const ELEVENLABS_VOICE_ID = 'RJTMVxggTDxBTBBzEShb' // Tessa voice

  // Whisper prompt with known Tessa vocabulary — helps STT accuracy for domain-specific terms
  const WHISPER_PROMPT = `Tessa, InnovFix, UNMAN, Astro Website, Bangalore Connect, Hireo, Only Cars, Susie, Thesla,
sprint, standup, KPI, escalation, sign-off, sign off, daily report, action items, agenda, minutes,
JP, Fida, Sneha, Ayush, Apush, meetup, backlog, velocity, burndown, retro, retrospective,
overdue, blocked, in progress, pending, completed, urgent, high priority,
revenue, gross revenue, net revenue, Google Ads, Meta Ads, Facebook Ads, invoices, payout, ad spend`

  ipcMain.handle('voice:stt', async (_event, audioArrayBuffer: ArrayBuffer) => {
    try {
      const audioBuffer = Buffer.from(audioArrayBuffer)
      console.log('Voice: STT request, audio size:', audioBuffer.length)

      const boundary = '----VoiceBoundary' + Date.now()
      const parts: Buffer[] = []
      parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="recording.webm"\r\nContent-Type: audio/webm\r\n\r\n`))
      parts.push(audioBuffer)
      parts.push(Buffer.from('\r\n'))
      parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="model"\r\n\r\nwhisper-1\r\n`))
      parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="language"\r\n\r\nen\r\n`))
      parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="prompt"\r\n\r\n${WHISPER_PROMPT}\r\n`))
      parts.push(Buffer.from(`--${boundary}--\r\n`))
      const body = Buffer.concat(parts)

      const text = await new Promise<string>((resolve, reject) => {
        const req = httpsRequest({
          hostname: 'api.openai.com', path: '/v1/audio/transcriptions', method: 'POST',
          headers: { 'Authorization': `Bearer ${OPENAI_KEY}`, 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'Content-Length': body.length },
          timeout: 30000
        }, (res) => {
          let data = ''; res.on('data', (c) => { data += c }); res.on('end', () => {
            try { const p = JSON.parse(data); p.error ? reject(new Error(p.error.message)) : resolve(p.text || '') } catch { reject(new Error('Parse error')) }
          })
        })
        req.on('error', reject); req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')) })
        req.write(body); req.end()
      })
      console.log('Voice: Whisper transcript:', text)
      return { text }
    } catch (e: any) {
      console.error('Voice: STT error:', e.message)
      return { text: '', error: e.message }
    }
  })

  ipcMain.handle('voice:nlu', async (_event, transcript: string, userName: string, _key: string, history: Array<{ role: string; content: string }>) => {
    try {
      console.log('Voice: NLU classifying:', transcript, '| History:', history?.length || 0, 'messages')
      const now = new Date()
      const todayStr = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
      const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })

      const systemPrompt = `You are the NLU for Tessa, a voice assistant that ONLY handles work portal queries. Output raw JSON only.

TODAY: ${todayStr}, ${timeStr}

INTENTS — pick the single best match:

TASKS:
- "my_tasks" — list tasks. Params: {"status":"pending|in_progress|completed|on_hold", "priority":"urgent|high|medium|low"} (only if user specified)
- "task_detail" — specific task details. Params: {"taskName":"keyword from title", "query":"full question"}
- "overdue_tasks" — overdue/late tasks
- "blocked_tasks" — blocked tasks

MEETINGS:
- "meetings_today" — today's meetings
- "meetings_list" — all meetings / weekly schedule
- "meeting_detail" — specific meeting (agenda, notes, minutes, action items, what was discussed). Params: {"meetingName":"keyword", "day":"today|yesterday|monday|tuesday|...", "time":"this week|last week"}
- "action_items" — action items from meetings. Params: {"status":"pending|done|in_progress"}

DAILY WORK:
- "daily_report_status" — daily report progress, what's filled/missing. Params: {"time":"this week|last week"}
- "pending_work" — all pending work (action items, report fields, agenda questions)
- "sign_in" — sign in for the day (action)
- "sign_off_status" — CHECK what's needed to sign off, sign-off status (no action, just info)
- "sign_off_action" — PERFORM sign off for the day (action). Use this when user says "sign me off", "sign off now", "just sign off", "let it be pending, sign off". Params: {"force":"true"} if user insists despite pending items

METRICS & FINANCE:
- "kpi_summary" — KPIs and targets. Params: {"time":"this week|last week"}
- "sprint_status" — sprint board, progress. Params: {"project":"project name"} if user mentions a specific project (UNMAN, Tessa, Astro, Hireo, etc.)
- "revenue" — ANY question about revenue, gross revenue, net revenue, earnings, payout, money made, income. Examples: "What was the gross revenue on 13th April?", "How much revenue yesterday?", "What's today's earnings?", "Show me the revenue", "Net revenue this week". Params: {"date":"13th April|yesterday|2026-04-13"}
- "meta_ads" — Meta ads, Facebook ads, Instagram ads spend/performance. Examples: "How much did we spend on Meta ads?", "Facebook ads performance", "Meta ad spend for UNMAN". Params: {"project":"project name"}
- "google_ads" — Google ads spend/performance/cost. Examples: "Google ads spend", "How much on Google ads?", "Google advertising cost". Params: {"project":"project name"}
- "invoices" — invoices, vendor payments, bills, vendor invoices, uploads. Examples: "Show pending invoices", "What invoices do we have?", "How many invoices were uploaded yesterday?", "Invoices from today". Params: {"status":"pending|approved|rejected", "date":"yesterday|today|YYYY-MM-DD"}

ESCALATIONS:
- "escalations" — open escalations. Params: {"status":"open|in_progress|resolved"}

TICKETS:
- "tickets" — support tickets, issues raised, bug reports, feature requests. Examples: "How many tickets are raised?", "Show open tickets", "Any high priority tickets?", "Tickets raised today". Params: {"status":"open|in_progress|resolved|closed", "priority":"high|medium|low"}

DASHBOARD & ORG:
- "dashboard" — dashboard stats, overview, summary stats. Examples: "Show dashboard", "What's the overview?", "Dashboard stats"
- "employees" — team members, employees, org chart, who's in the team. Examples: "Who's in the team?", "List employees", "Show org chart", "People in marketing". Params: {"department":"department/team name"}
- "leave_status" — leave requests, time off, vacation, leave balance. Examples: "What's my leave balance?", "Show my leave requests", "Any pending leave?". Params: {"status":"pending|approved|rejected"}

RELEASES & SCRIPTS:
- "releases" — software releases, deployments, versions. Examples: "What releases are planned?", "Show upcoming releases", "Release status". Params: {"status":"planned|in_progress|released"}
- "scripts" — scripts, templates, playbooks. Examples: "Show me the scripts", "What scripts do we have?", "Scripts for onboarding". Params: {"category":"category name"}

AGILE:
- "stories" — user stories, story points. Examples: "Show stories in current sprint", "How many story points?", "What stories are in progress?". Params: {"status":"backlog|in_progress|done", "sprint":"sprint name"}
- "epics" — epics, features, big features. Examples: "What epics are we working on?", "Show active epics", "Epic progress". Params: {"status":"open|in_progress|done"}
- "bugs" — bugs, defects, issues in code. Examples: "Any open bugs?", "Show critical bugs", "Bug count". Params: {"status":"open|in_progress|resolved", "priority":"critical|high|medium|low"}
- "agile_dashboard" — agile board stats, sprint metrics, velocity. Examples: "Show agile dashboard", "Sprint velocity", "Board overview"

OTHER:
- "morning_briefing" — comprehensive morning summary. Trigger phrases: "good morning", "morning", "briefing", "what's on today", "start my day", "morning update". This fetches ALL relevant data (tasks, meetings, pending work, etc.)
- "greeting" — ONLY for "hello", "hi", "hey" with NO other context (no data needed)
- "help" — what can you do / capabilities
- "unknown" — NOT a Tessa query (generic questions, weather, jokes, etc.)

RULES:
1. If user mentions a SPECIFIC meeting or task name → use meeting_detail or task_detail, extract the name
2. "agenda", "minutes", "what was discussed", "notes from" → meeting_detail
3. "details on", "tell me about", "what's the status of" + name → task_detail or meeting_detail
4. Follow-up questions ("what about", "and the", "how about") → use conversation history to determine entity
5. Generic questions (weather, time, jokes, news, calculations) → "unknown" with confidence 0
6. Time references: "yesterday", "last week", "this week", "Monday", "13th April" → extract to params
7. "revenue", "gross revenue", "earnings", "payout", "income", "how much money" → ALWAYS use "revenue" intent
8. "Meta ads", "Facebook ads", "Instagram ads" → "meta_ads" intent
9. "Google ads", "AdWords" → "google_ads" intent
10. "invoices", "bills", "vendor payments" → "invoices" intent
11. "tickets", "issues", "bugs raised", "feature requests", "how many tickets" → "tickets" intent
12. "team", "employees", "who's in", "org chart", "people in" → "employees" intent
13. "leave", "time off", "vacation", "leave balance", "PTO" → "leave_status" intent
14. "releases", "deployment", "version", "what's releasing" → "releases" intent
15. "scripts", "playbooks", "templates for" → "scripts" intent
16. "stories", "user stories", "story points" → "stories" intent
17. "epics", "features", "big features" → "epics" intent
18. "bugs", "defects", "code issues" (in agile context) → "bugs" intent
19. "agile dashboard", "sprint velocity", "board overview" → "agile_dashboard" intent
20. "sign me off", "sign off now", "sign off for today" → "sign_off_action" (the ACTION, not status check)
21. "good morning", "morning", "start my day", "briefing" → "morning_briefing" (NOT greeting!)
22. CRITICAL FOLLOW-UP RULE: If conversation history shows assistant mentioned "pending items" or "can't sign off" and user now says ANYTHING like "it's okay", "just do it", "sign me off anyway", "let it be pending", "I don't care", "proceed", "do it" → MUST use intent "sign_off_action" with params {"force":"true"}

Output ONLY: {"intent":"...","params":{...},"confidence":0.0-1.0}

EXAMPLE:
History: Assistant said "You have 2 pending items..."
User: "It's okay, just sign me off"
Output: {"intent":"sign_off_action","params":{"force":"true"},"confidence":0.95}`

      // Build messages array with conversation history for context
      const messages: Array<{ role: string; content: string }> = [
        { role: 'system', content: systemPrompt }
      ]
      // Add conversation history so Claude understands follow-ups
      if (history && history.length > 0) {
        for (const msg of history) {
          messages.push({ role: msg.role, content: msg.content })
        }
      }
      messages.push({ role: 'user', content: `User (${userName}) said: "${transcript}"` })

      const payload = JSON.stringify({
        model: 'anthropic/claude-haiku-4-5-20251001',
        messages,
        max_tokens: 100,
        temperature: 0
      })

      const rawResponse = await new Promise<string>((resolve, reject) => {
        const req = httpsRequest({ hostname: 'openrouter.ai', path: '/api/v1/chat/completions', method: 'POST',
          headers: { 'Authorization': `Bearer ${OPENROUTER_KEY}`, 'Content-Type': 'application/json', 'HTTP-Referer': 'https://tessa.innovfix.ai', 'X-Title': 'Tessa Voice', 'Content-Length': Buffer.byteLength(payload) }, timeout: 10000
        }, (res) => {
          let d = ''
          res.on('data', (c) => { d += c })
          res.on('end', () => {
            console.log('Voice: OpenRouter raw status:', res.statusCode)
            console.log('Voice: OpenRouter raw body:', d.substring(0, 500))
            resolve(d)
          })
        })
        req.on('error', reject)
        req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')) })
        req.write(payload)
        req.end()
      })

      // Parse OpenRouter response
      const apiResponse = JSON.parse(rawResponse)
      if (apiResponse.error) {
        console.error('Voice: OpenRouter API error:', apiResponse.error)
        return { intent: 'unknown', params: {}, confidence: 0, error: apiResponse.error.message || 'API error' }
      }

      const content = apiResponse.choices?.[0]?.message?.content || ''
      console.log('Voice: NLU Claude content:', content)

      // Extract JSON from Claude's response (handle markdown wrapping)
      let jsonStr = content.trim()
      // Strip ```json ... ``` wrapping if present
      const jsonMatch = jsonStr.match(/```(?:json)?\s*([\s\S]*?)```/)
      if (jsonMatch) jsonStr = jsonMatch[1].trim()
      // Strip any leading/trailing non-JSON text
      const braceMatch = jsonStr.match(/\{[\s\S]*\}/)
      if (braceMatch) jsonStr = braceMatch[0]

      const parsed = JSON.parse(jsonStr)
      console.log('Voice: NLU parsed:', parsed)
      return { intent: parsed.intent || 'unknown', params: parsed.params || {}, confidence: parsed.confidence || 0.8 }
    } catch (e: any) {
      console.error('Voice: NLU error:', e.message)
      return { intent: 'unknown', params: {}, confidence: 0, error: e.message }
    }
  })

  ipcMain.handle('voice:respond', async (_event, intent: string, apiData: any, userName: string, _key: string, history: Array<{ role: string; content: string }>) => {
    try {
      const hour = new Date().getHours()
      const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'

      // Handle simple intents without AI
      if (intent === 'greeting') {
        return { response: `${greet} ${userName}! How can I help you with Tessa today?` }
      }
      if (intent === 'help') {
        return { response: `I can help you with all your Tessa data. Ask me about tasks, meetings, daily reports, KPIs, sprints, tickets, escalations, revenue, Google and Meta ads, invoices, releases, scripts, stories, epics, bugs, employees, or leave status. I can also sign you in and out. Try saying "What are my tasks?" or "Show sprint status for UNMAN" or "How many tickets are open?"` }
      }
      if (intent === 'unknown') {
        return { response: `I can only help with Tessa — your tasks, meetings, reports, KPIs, and work items. What would you like to know about your work?` }
      }

      // If data has an error, give a short hardcoded response
      if (apiData?.error) {
        if (apiData?.permission_denied) {
          return { response: `Sorry ${userName}, you don't have access to that feature in Tessa. It might be restricted to certain roles.` }
        }
        return { response: `Sorry ${userName}, I couldn't fetch that data right now. Please try again.` }
      }

      const now = new Date()
      const todayStr = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })

      const sysPrompt = `You are Tessa, a voice assistant for the Tessa work portal. You speak naturally like Alexa or Google Assistant — friendly, clear, and conversational.

TODAY: ${todayStr}
USER: ${userName}

PERSONALITY:
- Speak naturally, like talking to a colleague. Use "you have", "your", contractions.
- Be warm but efficient. No robotic lists, no JSON-speak.
- Use actual names, numbers, dates, times from the data.
- Acknowledge the question naturally before answering.

RESPONSE STYLE:
- MATCH response length to question complexity:
  - "How many X?" → SHORT answer: "You have 18 tickets" or "There are 5 open bugs". Just the count, maybe a one-line breakdown by status. Done.
  - "What are my X?" / "Show X" → MEDIUM: List the items briefly (titles, status). 2-4 sentences.
  - "Tell me about X" / "Details on X" → LONGER: Full context with description, owner, dates, etc.
- For counts/numbers: give the number, optionally mention the breakdown (e.g., "7 open, 4 closed"), stop there.
- For lists: mention top 3-4 items by title, say "and X more" if needed. Don't describe each one unless asked.
- For specific items: give full context — title, status, owner, deadline, description.
- For empty results: say so briefly ("You don't have any overdue tasks right now.")
- For morning briefings: give a natural summary of the day ahead.
- DEFAULT TO BRIEF. Users can ask follow-ups if they want more detail.

CONSTRAINTS:
- This is SPOKEN aloud — no markdown, no bullets, no asterisks, no special formatting.
- Don't say field names like "assigned_to" or "status: in_progress" — say "assigned to Sarah" or "it's in progress".
- Don't start with "Based on the data" or "According to" — just answer naturally.
- Don't offer to help with more or ask follow-up questions.
- Stay focused on Tessa work data only.

MONEY/NUMBERS (CRITICAL for speech):
- Use INDIAN number system: thousands, lakhs (1,00,000), crores (1,00,00,000). NEVER say millions or billions.
- Speak amounts as WORDS, not digits: "ten thousand rupees", "two lakh fifty thousand", "one crore twenty lakhs"
- NEVER output "₹10,000" or "Rs 10,000" — the TTS will read it wrong. Say "ten thousand rupees" instead.
- For large amounts: 50,00,000 = "fifty lakhs", 1,20,00,000 = "one crore twenty lakhs"
- Round to sensible precision: "about nineteen lakh ninety-nine thousand" not "nineteen lakh ninety-nine thousand eight hundred one"

ACTION RESPONSES:
- If data contains "success: true" → confirm the action was completed ("Done!", "You're signed off now", etc.)
- If data contains "message" field → use that message naturally in your response
- If data contains "forced: true" → acknowledge you proceeded despite pending items
- Don't question or refuse actions that the system already completed — just confirm them.

Answer the user's question about their Tessa data naturally and completely.`

      const userContent = `Intent: ${intent}
Data:
${JSON.stringify(apiData, null, 2)}

Respond naturally to this query. Read out the relevant information conversationally.`

      // Build messages with conversation history
      const messages: Array<{ role: string; content: string }> = [
        { role: 'system', content: sysPrompt }
      ]
      if (history && history.length > 0) {
        for (const msg of history) {
          messages.push({ role: msg.role, content: msg.content })
        }
      }
      messages.push({ role: 'user', content: userContent })

      const payload = JSON.stringify({
        model: 'anthropic/claude-haiku-4-5-20251001',
        messages,
        max_tokens: 200,
        temperature: 0.4
      })
      const result = await new Promise<string>((resolve, reject) => {
        const req = httpsRequest({ hostname: 'openrouter.ai', path: '/api/v1/chat/completions', method: 'POST',
          headers: { 'Authorization': `Bearer ${OPENROUTER_KEY}`, 'Content-Type': 'application/json', 'HTTP-Referer': 'https://tessa.innovfix.ai', 'X-Title': 'Tessa Voice', 'Content-Length': Buffer.byteLength(payload) }, timeout: 15000
        }, (res) => { let d = ''; res.on('data', (c) => { d += c }); res.on('end', () => { try { resolve(JSON.parse(d).choices?.[0]?.message?.content || '') } catch { reject(new Error('Parse error')) } }) })
        req.on('error', reject); req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')) }); req.write(payload); req.end()
      })
      return { response: result || `I got your data but couldn't put it into words.` }
    } catch (e: any) {
      console.error('Voice: Response gen error:', e.message)
      return { response: 'Sorry, I had trouble generating a response. Please try again.' }
    }
  })

  // ElevenLabs Text-to-Speech handler
  ipcMain.handle('voice:tts', async (_event, text: string) => {
    try {
      console.log('Voice: TTS request, text length:', text.length)

      const payload = JSON.stringify({
        text,
        model_id: 'eleven_turbo_v2_5',
        voice_settings: {
          stability: 0.5,
          similarity_boost: 0.75
        }
      })

      const audioBuffer = await new Promise<Buffer>((resolve, reject) => {
        const req = httpsRequest({
          hostname: 'api.elevenlabs.io',
          path: `/v1/text-to-speech/${ELEVENLABS_VOICE_ID}`,
          method: 'POST',
          headers: {
            'xi-api-key': ELEVENLABS_KEY,
            'Content-Type': 'application/json',
            'Accept': 'audio/mpeg',
            'Content-Length': Buffer.byteLength(payload)
          },
          timeout: 15000
        }, (res) => {
          const chunks: Buffer[] = []
          res.on('data', (chunk) => chunks.push(chunk))
          res.on('end', () => {
            const data = Buffer.concat(chunks)
            if (res.statusCode === 200) {
              resolve(data)
            } else {
              // Log the error response body for debugging
              const errorBody = data.toString('utf-8')
              console.error('Voice: ElevenLabs API error response:', res.statusCode, errorBody)
              reject(new Error(`ElevenLabs API error: ${res.statusCode} - ${errorBody.slice(0, 200)}`))
            }
          })
        })
        req.on('error', reject)
        req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')) })
        req.write(payload)
        req.end()
      })

      console.log('Voice: TTS response, audio size:', audioBuffer.length)
      // Return as Uint8Array (serializable over IPC)
      return { audio: new Uint8Array(audioBuffer).buffer }
    } catch (e: any) {
      console.error('Voice: TTS error:', e.message)
      return { error: e.message }
    }
  })

  console.log('Voice: All IPC handlers registered')

  createAppMenu()
  createWindow()
  registerVoiceShortcut(() => mainWindow)
  if (process.platform !== 'linux') createTray()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow()
    else { mainWindow?.show(); mainWindow?.focus() }
  })
})

app.on('before-quit', () => { isQuitting = true; unregisterVoiceShortcut() })
app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit() })
