import { Loader2 } from 'lucide-react'

interface Props {
  size?: 'sm' | 'md' | 'lg'
  label?: string
  className?: string
}

const sizes = { sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-8 w-8' }

export default function Loader({ size = 'md', label, className = '' }: Props) {
  return (
    <div className={`flex flex-col items-center justify-center gap-3 py-12 ${className}`}>
      <Loader2 className={`animate-spin text-brand-500 ${sizes[size]}`} />
      {label && <p className="text-sm text-zinc-500">{label}</p>}
    </div>
  )
}
