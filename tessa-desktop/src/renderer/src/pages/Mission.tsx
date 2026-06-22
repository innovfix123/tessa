import { useMemo } from 'react'

/* ── Static data (mirrors portal.js renderMission) ── */

const projects = [
  { name: 'Hima', current: 42, target: 80, next: 6.5, color: '#ec4899', icon: 'H' },
  { name: 'Thedal', current: 8, target: 30, next: 2.2, color: '#eab308', icon: 'T' },
  { name: 'Sudar', current: 3.5, target: 25, next: 1.8, color: '#3b82f6', icon: 'S' },
  { name: "B'lore Connect", current: 12, target: 40, next: 3.5, color: '#22c55e', icon: 'B' },
  { name: 'Only Care', current: 5, target: 25, next: 2, color: '#a1a1aa', icon: 'O' }
]

const totalTarget = 200

const lineData = [
  { name: 'Hima', color: '#ec4899', data: [5.8, 6.2, 6.8, 7.3, 7.9, 8.4, 9.0, 9.5, 10.2, 10.8, 11.5, 12.2] },
  { name: 'Thedal', color: '#eab308', data: [1.0, 1.1, 1.3, 1.5, 1.7, 1.9, 2.1, 2.4, 2.6, 2.9, 3.2, 3.5] },
  { name: 'Sudar', color: '#3b82f6', data: [0.4, 0.5, 0.6, 0.7, 0.8, 1.0, 1.2, 1.4, 1.6, 1.8, 2.1, 2.4] },
  { name: "B'lore Connect", color: '#22c55e', data: [1.5, 1.7, 2.0, 2.3, 2.6, 2.9, 3.2, 3.5, 3.8, 4.2, 4.6, 5.0] },
  { name: 'Only Care', color: '#a1a1aa', data: [0.6, 0.7, 0.9, 1.1, 1.3, 1.5, 1.7, 1.9, 2.1, 2.4, 2.7, 3.0] }
]

const monthLabels = ['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar']

/* ── Helpers ── */

function statusColor(pct: number): string {
  if (pct >= 50) return '#22c55e'
  if (pct >= 25) return '#f59e0b'
  return '#ef4444'
}

function statusClass(pct: number): string {
  if (pct >= 50) return 'text-green-400'
  if (pct >= 25) return 'text-yellow-400'
  return 'text-red-400'
}

/* ── Line chart builder (exact portal logic) ── */

function buildLineChart(): {
  gridLines: { y: number; label: number }[]
  xLabels: { x: number; label: string }[]
  yAxisLabel: { x: number; y: number }
  lines: { name: string; color: string; points: string; dots: { x: number; y: number; v: number; isLast: boolean }[] }[]
  svgW: number
  svgH: number
  padL: number
  padR: number
  padT: number
  padB: number
  chartW: number
  chartH: number
  lineMax: number
} {
  const svgW = 800, svgH = 340
  const padL = 55, padR = 20, padT = 20, padB = 40
  const chartW = svgW - padL - padR
  const chartH = svgH - padT - padB

  let lineMax = 0
  lineData.forEach((line) => {
    line.data.forEach((v) => { if (v > lineMax) lineMax = v })
  })
  lineMax = Math.ceil(lineMax + 1)

  const yTicks: number[] = []
  const yStep = lineMax <= 5 ? 1 : lineMax <= 12 ? 2 : 5
  for (let y = 0; y <= lineMax; y += yStep) yTicks.push(y)

  const gridLines = yTicks.map((yVal) => ({
    y: padT + chartH - (yVal / lineMax * chartH),
    label: yVal
  }))

  const xLabels = monthLabels.map((m, i) => ({
    x: padL + (i / (monthLabels.length - 1)) * chartW,
    label: m
  }))

  const yAxisLabel = { x: 14, y: padT + chartH / 2 }

  const lines = lineData.map((line) => {
    const dots: { x: number; y: number; v: number; isLast: boolean }[] = []
    const pointStrs: string[] = []

    line.data.forEach((v, i) => {
      const x = padL + (i / (monthLabels.length - 1)) * chartW
      const yPixel = padT + chartH - (v / lineMax * chartH)
      pointStrs.push(`${x.toFixed(1)},${yPixel.toFixed(1)}`)
      dots.push({ x, y: yPixel, v, isLast: i === line.data.length - 1 })
    })

    return { name: line.name, color: line.color, points: pointStrs.join(' '), dots }
  })

  return { gridLines, xLabels, yAxisLabel, lines, svgW, svgH, padL, padR, padT, padB, chartW, chartH, lineMax }
}

/* ── Component ── */

export default function Mission(): JSX.Element {
  const totalCurrent = useMemo(() => projects.reduce((s, p) => s + p.current, 0), [])
  const totalNext = useMemo(() => projects.reduce((s, p) => s + p.next, 0), [])
  const overallPct = useMemo(() => Math.round((totalCurrent / totalTarget) * 100), [totalCurrent])

  // Dynamic month label for focus section
  const currentMonthFocus = useMemo(
    () => new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' }),
    []
  )

  // Time remaining
  const { monthsLeft, daysLeft } = useMemo(() => {
    const deadline = new Date('2027-03-31')
    const now = new Date()
    const msLeft = deadline.getTime() - now.getTime()
    const days = Math.max(0, Math.ceil(msLeft / (1000 * 60 * 60 * 24)))
    return { monthsLeft: Math.round(days / 30), daysLeft: days }
  }, [])

  const chart = useMemo(() => buildLineChart(), [])

  // Legend share calculation (portal logic)
  const legendTotal = useMemo(
    () => lineData.reduce((s, l) => s + l.data[l.data.length - 1], 0),
    []
  )

  const ringDash = Math.round(326.7 * overallPct / 100)
  const ringColor = statusColor(overallPct)

  return (
    <div className="space-y-5">
      {/* ── Mission Banner ── */}
      <div className="grid grid-cols-3 items-center gap-6 rounded-xl bg-zinc-900/80 border border-zinc-800 p-6">
        {/* Left */}
        <div>
          <div className="text-[11px] font-bold tracking-[0.2em] text-zinc-500 uppercase">Mission</div>
          <div className="mt-1 text-3xl font-extrabold text-white">&#8377;200 Crore</div>
          <div className="mt-1 text-sm text-zinc-400">Total Revenue Target &middot; By March 2027</div>
        </div>

        {/* Center — progress ring */}
        <div className="flex justify-center">
          <div className="relative w-[120px] h-[120px]">
            <svg className="w-full h-full" viewBox="0 0 120 120">
              <circle cx="60" cy="60" r="52" fill="none" stroke="#1e1e21" strokeWidth="10" />
              <circle
                cx="60" cy="60" r="52"
                fill="none"
                stroke={ringColor}
                strokeWidth="10"
                strokeLinecap="round"
                strokeDasharray={`${ringDash} 326.7`}
                transform="rotate(-90 60 60)"
              />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
              <div className="text-2xl font-extrabold text-white">{overallPct}%</div>
              <div className="text-[11px] text-zinc-400">achieved</div>
            </div>
          </div>
        </div>

        {/* Right — stats */}
        <div className="space-y-3 text-right">
          <div>
            <div className="text-lg font-bold text-white">&#8377;{totalCurrent} Cr</div>
            <div className="text-xs text-zinc-500">Revenue So Far</div>
          </div>
          <div>
            <div className="text-lg font-bold text-white">{monthsLeft} months</div>
            <div className="text-xs text-zinc-500">{daysLeft} days remaining</div>
          </div>
          <div>
            <div className="text-lg font-bold text-white">&#8377;{totalNext} Cr</div>
            <div className="text-xs text-zinc-500">Next Month Target</div>
          </div>
        </div>
      </div>

      {/* ── Project-wise Progress (vertical bars) ── */}
      <div className="rounded-xl bg-zinc-900/80 border border-zinc-800 p-5">
        <div className="text-sm font-semibold text-zinc-300 mb-5">Project-wise Progress</div>
        <div className="grid grid-cols-5 gap-4">
          {projects.map((p) => {
            const pct = Math.round((p.current / p.target) * 100)
            return (
              <div key={p.name} className="flex flex-col items-center gap-1.5">
                <div className={`text-sm font-bold ${statusClass(pct)}`}>{pct}%</div>
                <div className="relative w-7 h-36 rounded-full bg-zinc-800 overflow-hidden">
                  <div
                    className="absolute bottom-0 left-0 right-0 rounded-full transition-all"
                    style={{ height: `${Math.min(pct, 100)}%`, background: p.color }}
                  />
                </div>
                <div className="text-xs font-medium text-zinc-300 text-center mt-1">{p.name}</div>
                <div className="text-xs text-center">
                  <span style={{ color: p.color }} className="font-semibold">&#8377;{p.current}</span>
                  <span className="text-zinc-500"> / {p.target} Cr</span>
                </div>
                <div className="text-[11px] text-zinc-500">Next: &#8377;{p.next} Cr</div>
              </div>
            )
          })}
        </div>
      </div>

      {/* ── Monthly Focus Strip ── */}
      <div className="rounded-xl bg-zinc-900/80 border border-zinc-800 p-5">
        <div className="text-sm font-semibold text-zinc-300 mb-4">{currentMonthFocus} Focus</div>
        <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">
          {projects.map((p) => (
            <div key={p.name} className="flex items-center gap-2 rounded-lg bg-zinc-800/60 px-3 py-2">
              <div className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: p.color }} />
              <span className="text-xs text-zinc-300 truncate">{p.name}</span>
              <span className="text-xs font-semibold text-white ml-auto">&#8377;{p.next} Cr</span>
            </div>
          ))}
          <div className="flex items-center gap-2 rounded-lg bg-zinc-800/60 px-3 py-2 border border-zinc-700">
            <div className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: '#fafafa' }} />
            <span className="text-xs font-semibold text-zinc-200">Total</span>
            <span className="text-xs font-bold text-white ml-auto">&#8377;{totalNext} Cr</span>
          </div>
        </div>
      </div>

      {/* ── Line Chart — Monthly Revenue by Project ── */}
      <div className="rounded-xl bg-zinc-900/80 border border-zinc-800 p-5">
        <div className="text-sm font-semibold text-zinc-300 mb-4">Monthly Revenue by Project (in Cr)</div>
        <div className="w-full overflow-x-auto">
          <svg
            className="w-full"
            viewBox={`0 0 ${chart.svgW} ${chart.svgH}`}
            preserveAspectRatio="xMidYMid meet"
          >
            {/* Grid lines + Y labels */}
            {chart.gridLines.map((g) => (
              <g key={g.label}>
                <line
                  x1={chart.padL} y1={g.y}
                  x2={chart.padL + chart.chartW} y2={g.y}
                  stroke="#1e1e21" strokeWidth="1"
                />
                <text
                  x={chart.padL - 10} y={g.y + 4}
                  textAnchor="end" fill="#52525b" fontSize="11"
                >
                  {g.label}
                </text>
              </g>
            ))}

            {/* X labels */}
            {chart.xLabels.map((xl) => (
              <text
                key={xl.label}
                x={xl.x} y={chart.svgH - 8}
                textAnchor="middle" fill="#71717a" fontSize="12" fontWeight="500"
              >
                {xl.label}
              </text>
            ))}

            {/* Y axis label */}
            <text
              x={chart.yAxisLabel.x} y={chart.yAxisLabel.y}
              textAnchor="middle" fill="#52525b" fontSize="10"
              transform={`rotate(-90,${chart.yAxisLabel.x},${chart.yAxisLabel.y})`}
            >
              Revenue (Cr)
            </text>

            {/* Lines + dots */}
            {chart.lines.map((line) => (
              <g key={line.name}>
                <polyline
                  points={line.points}
                  fill="none"
                  stroke={line.color}
                  strokeWidth="1.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
                {line.dots.map((d, i) => (
                  <g key={i}>
                    <circle
                      cx={d.x.toFixed(1)} cy={d.y.toFixed(1)} r="3"
                      fill={line.color} stroke="#0f0f11" strokeWidth="1.5"
                    />
                    {d.isLast && (
                      <text
                        x={(d.x + 8).toFixed(1)} y={(d.y + 4).toFixed(1)}
                        fill={line.color} fontSize="11" fontWeight="700"
                      >
                        {d.v}
                      </text>
                    )}
                  </g>
                ))}
              </g>
            ))}
          </svg>
        </div>

        {/* Legend */}
        <div className="flex flex-wrap gap-4 mt-4 pt-3 border-t border-zinc-800">
          {lineData.map((line) => {
            const lastVal = line.data[line.data.length - 1]
            const share = Math.round((lastVal / legendTotal) * 100)
            return (
              <div key={line.name} className="flex items-center gap-2 text-xs">
                <span className="w-3 h-3 rounded-sm shrink-0" style={{ background: line.color }} />
                <span className="text-zinc-400">{line.name}</span>
                <span className="text-white font-semibold">&#8377;{lastVal} Cr</span>
                <span className="text-zinc-500">{share}%</span>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
