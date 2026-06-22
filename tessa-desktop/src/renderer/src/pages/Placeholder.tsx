import { useLocation } from 'react-router-dom'
import { Construction } from 'lucide-react'

export default function Placeholder(): JSX.Element {
  const location = useLocation()
  const name = location.pathname.slice(1).replace(/-/g, ' ') || 'Page'

  return (
    <div className="flex flex-col items-center justify-center py-32 text-center">
      <Construction className="h-14 w-14 text-zinc-700 mb-4" />
      <h2 className="text-lg font-semibold text-zinc-300 capitalize">{name}</h2>
      <p className="mt-2 text-sm text-zinc-500 max-w-sm">
        This feature is being built. It will be available in the next sprint.
      </p>
    </div>
  )
}
