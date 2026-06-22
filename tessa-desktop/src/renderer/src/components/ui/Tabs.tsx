import { classNames } from '@/lib/utils'

interface Tab {
  key: string
  label: string
  count?: number
}

interface Props {
  tabs: Tab[]
  active: string
  onChange: (key: string) => void
  className?: string
}

export default function Tabs({ tabs, active, onChange, className = '' }: Props) {
  return (
    <div className={classNames('flex gap-0.5 rounded-lg bg-surface-1 p-1 w-fit', className)}>
      {tabs.map((tab) => (
        <button
          key={tab.key}
          onClick={() => onChange(tab.key)}
          className={classNames(
            'rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors',
            active === tab.key
              ? 'bg-zinc-800 text-zinc-100 shadow-sm'
              : 'text-zinc-500 hover:text-zinc-300'
          )}
        >
          {tab.label}
          {tab.count != null && (
            <span className="ml-1.5 text-xs text-zinc-500">({tab.count})</span>
          )}
        </button>
      ))}
    </div>
  )
}
