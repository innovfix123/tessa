import { initials, stringToColor } from '@/lib/utils'

interface Props {
  name: string
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

const sizes = {
  sm: 'h-6 w-6 text-[10px]',
  md: 'h-8 w-8 text-xs',
  lg: 'h-10 w-10 text-sm'
}

export default function Avatar({ name, size = 'md', className = '' }: Props) {
  return (
    <div
      className={`shrink-0 rounded-full flex items-center justify-center font-semibold text-white ${sizes[size]} ${className}`}
      style={{ backgroundColor: stringToColor(name) }}
      title={name}
    >
      {initials(name)}
    </div>
  )
}
