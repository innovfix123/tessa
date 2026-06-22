/**
 * Org Chart — sourced line-by-line from /public/shared/org.js (142 lines)
 * + /public/shared/org.css (264 lines)
 * Static page — no API calls. All data hardcoded exactly as portal.
 */

interface OrgPerson {
  name: string
  role: string
}

interface TreeRow {
  name: string
  role: string
  tag?: string
  indent?: boolean
}

/* ── Data — exact copy from org.js lines 45-95 ── */

const directReports: OrgPerson[] = [
  { name: 'Bala', role: 'COO' },
  { name: 'Nandha', role: 'CMO + BLR PM' },
  { name: 'Ayush', role: 'CFO' },
  { name: 'Yuvanesh', role: 'Tech Lead' },
  { name: 'Fida', role: 'Gen AI Dev' },
  { name: 'Sneha P', role: 'Gen AI Dev' }
]

const departments: { title: string; subtitle: string; rows: TreeRow[] }[] = [
  {
    title: 'Bala — COO',
    subtitle: 'Product Operations',
    rows: [
      { name: 'Sneha Sunoj', role: 'Ops Manager', tag: 'Hima' },
      { name: 'Meghana', role: 'Business Analyst', indent: true, tag: '→ Sneha' },
      { name: 'Ranjini', role: 'Tamil Support', indent: true },
      { name: 'Gousia', role: 'Telugu Support', indent: true },
      { name: 'Deeksha', role: 'Kannada Support', indent: true },
      { name: 'Reshma', role: 'Malayalam Support', indent: true },
      { name: 'Amirtha', role: 'HR Manager', tag: 'All' },
      { name: 'Tamil Arasan', role: 'Product Manager' },
      { name: 'Dhanush', role: 'Product Manager' }
    ]
  },
  {
    title: 'Nandha — CMO + BLR PM',
    subtitle: 'Marketing, Content & Bangalore Connect',
    rows: [
      { name: 'Bangalore Connect', role: 'Product Management (Owner: Nandha)', tag: 'BLR' },
      { name: 'Anirudh', role: 'Performance Mktg' },
      { name: 'Anindita', role: 'Growth Manager, North India' },
      { name: 'Krishnan', role: 'Content Lead', tag: 'Hima' },
      { name: 'Disha', role: 'Content Creator', indent: true },
      { name: 'Tiyasa', role: 'Content Creator', indent: true },
      { name: 'Maansi', role: 'Content Creator', indent: true },
      { name: 'Anaz', role: 'Video Editor' },
      { name: 'Sooraj', role: 'Graphic Designer' }
    ]
  },
  {
    title: 'Ayush — CFO',
    subtitle: 'Finance',
    rows: [{ name: 'Shoyab', role: 'Accountant' }]
  },
  {
    title: 'AI Platform & R&D',
    subtitle: 'Reports to CEO',
    rows: [
      { name: 'Fida', role: 'Gen AI Developer' },
      { name: 'Sneha Prathap', role: 'Gen AI Developer' }
    ]
  },
  {
    title: 'Yuvanesh — Tech Lead',
    subtitle: 'All App Development',
    rows: [
      { name: 'Rishabh', role: 'Full Stack Dev', tag: 'Hima' },
      { name: 'Barkha Agarwal', role: 'Intern', indent: true, tag: '→ Rishabh' },
      { name: 'Raksha', role: 'QA Analyst' },
      { name: 'Laxmi', role: 'QA Intern', indent: true, tag: '→ Raksha' },
      { name: 'Perumal', role: 'Full Stack Dev' },
      { name: 'Maari', role: 'Full Stack Dev', tag: 'Only Care' },
      { name: 'Saran', role: 'Data Analyst' }
    ]
  }
]

/* ── Components ── */

function ReportCard({ person }: { person: OrgPerson }) {
  return (
    <article className="rounded-xl border border-zinc-800 bg-[#0d1014] px-3 py-2.5 text-center">
      <div className="mx-auto mb-2.5 h-[3px] w-[78px] rounded-full bg-zinc-200" />
      <div className="text-[0.92rem] font-bold text-zinc-100">{person.name}</div>
      <div className="mt-0.5 text-[0.76rem] text-zinc-500">{person.role}</div>
    </article>
  )
}

function TreeRowItem({ row }: { row: TreeRow }) {
  return (
    <div className={`flex items-baseline gap-2 text-zinc-400 leading-tight ${row.indent ? 'pl-6' : ''}`}>
      <span className="w-3 flex-none text-center text-zinc-600">└</span>
      <span className="font-semibold text-zinc-300">{row.name}</span>
      <span className="text-[0.9em] text-zinc-500">{row.role}</span>
      {row.tag && (
        <span className="ml-auto rounded-[7px] border border-zinc-800 bg-[#161a1f] px-2 py-0.5 text-[0.72rem] text-zinc-500">
          {row.tag}
        </span>
      )}
    </div>
  )
}

function DepartmentCard({ title, subtitle, rows }: { title: string; subtitle: string; rows: TreeRow[] }) {
  return (
    <article className="rounded-xl border border-zinc-800 bg-[#0d1014] px-4 py-3.5">
      <h4 className="m-0 text-[1.05rem] font-bold text-zinc-100">{title}</h4>
      <div className="mt-0.5 text-[0.78rem] text-zinc-500">{subtitle}</div>
      <div className="my-2.5 border-b border-zinc-800" />
      <div className="grid gap-[7px]">
        {rows.map((row, i) => (
          <TreeRowItem key={i} row={row} />
        ))}
      </div>
    </article>
  )
}

/* ── Main page ── */

export default function OrgChart() {
  // Dynamic current month for the header
  const currentMonth = new Date().toLocaleDateString('en-US', {
    month: 'short',
    year: 'numeric'
  }).toUpperCase()

  return (
    <div className="min-h-full bg-[#050607] p-6 text-zinc-100">
      {/* Top bar — brand + stats */}
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-baseline gap-3.5">
          <span className="text-5xl font-bold tracking-tight">InnovFix</span>
          <span className="text-[0.72rem] uppercase tracking-[0.34em] text-zinc-500 leading-snug">
            ORG STRUCTURE &bull; {currentMonth}
          </span>
        </div>
        <div className="flex gap-6">
          {[
            { num: '33', label: 'PEOPLE' },
            { num: '6', label: 'PRODUCTS' },
            { num: '5', label: 'DEPTS' }
          ].map((s) => (
            <div key={s.label} className="text-center">
              <span className="block text-3xl font-bold leading-tight">{s.num}</span>
              <small className="mt-1 block text-[0.72rem] tracking-[0.24em] text-zinc-500">
                {s.label}
              </small>
            </div>
          ))}
        </div>
      </div>

      {/* Divider */}
      <div className="my-5 h-px bg-zinc-800" />

      {/* CEO */}
      <div className="grid justify-items-center">
        <article className="w-[min(360px,90%)] rounded-[14px] border border-zinc-800 bg-[#17191d] px-4 py-5 text-center">
          <div className="text-[2.5rem] font-bold leading-tight">JP</div>
          <div className="mt-2 text-[0.82rem] tracking-[0.14em] text-zinc-500">CEO + AI LEAD</div>
        </article>
        <div className="mt-2.5 h-7 w-px bg-zinc-800" />
      </div>

      {/* Direct Reports */}
      <div className="mb-3.5 text-center text-[0.84rem] tracking-[0.34em] text-zinc-500">
        DIRECT REPORTS
      </div>
      <div className="grid grid-cols-3 gap-2.5">
        {directReports.map((p) => (
          <ReportCard key={p.name} person={p} />
        ))}
      </div>

      {/* Departments title with lines */}
      <div className="relative my-5 text-center text-[0.84rem] tracking-[0.34em] text-zinc-500">
        <span className="absolute left-0 top-1/2 h-px w-[calc(50%-108px)] bg-zinc-800" />
        DEPARTMENTS
        <span className="absolute right-0 top-1/2 h-px w-[calc(50%-108px)] bg-zinc-800" />
      </div>

      {/* Department cards */}
      <div className="grid gap-3">
        {departments.map((dept) => (
          <DepartmentCard key={dept.title} title={dept.title} subtitle={dept.subtitle} rows={dept.rows} />
        ))}
      </div>
    </div>
  )
}
