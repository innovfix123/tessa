import { classNames } from '@/lib/utils'

interface Props {
  children: React.ReactNode
  variant?: string
  className?: string
}

export default function Badge({ children, variant = '', className = '' }: Props) {
  return (
    <span
      className={classNames(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border',
        variant || 'bg-zinc-800 text-zinc-400 border-zinc-700',
        className
      )}
    >
      {children}
    </span>
  )
}
