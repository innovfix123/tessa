import {
  createContext, useContext, useEffect, useState, useCallback,
  type ReactNode
} from 'react'
import { useNavigate } from 'react-router-dom'
import { authAPI, fetchPortalConfig, getFallbackFeatures } from '@/api/client'
import type { User, PortalConfig, Person, KpiGroup } from '@/lib/types'

interface AuthState {
  user: User | null
  loading: boolean
  features: string[]
  people: Person[]
  kpiDefinitions: KpiGroup[]
  portalTitle: string
  roleName: string
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refreshConfig: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }): JSX.Element {
  const navigate = useNavigate()
  const [state, setState] = useState<AuthState>({
    user: null,
    loading: true,
    features: [],
    people: [],
    kpiDefinitions: [],
    portalTitle: 'Portal',
    roleName: ''
  })

  const applyConfig = useCallback((config: PortalConfig | null, user: User) => {
    const features = (config?.features && config.features.length > 0)
      ? config.features
      : getFallbackFeatures(user.role)

    setState((s) => ({
      ...s,
      user,
      features,
      people: config?.people ?? [],
      kpiDefinitions: config?.kpiDefinitions ?? [],
      portalTitle: config?.title ?? 'Portal',
      roleName: config?.roleName ?? user.role ?? '',
      loading: false
    }))
  }, [])

  const loadConfig = useCallback(async (user: User) => {
    try {
      const config = await fetchPortalConfig()
      if (config) {
        (window as any).__PORTAL_CONFIG = config
      }
      applyConfig(config, user)
    } catch {
      applyConfig(null, user)
    }
  }, [applyConfig])

  // Check session on mount
  useEffect(() => {
    ;(async () => {
      try {
        const res = await authAPI.session()
        if (res.data?.authenticated && res.data?.user) {
          await loadConfig(res.data.user)
          return
        }
      } catch { /* not authenticated */ }
      setState((s) => ({ ...s, loading: false }))
    })()
  }, [loadConfig])

  // Listen for 401
  useEffect(() => {
    const handler = (): void => {
      setState((s) => ({ ...s, user: null, features: [], people: [] }))
      navigate('/login')
    }
    window.addEventListener('auth:unauthorized', handler)
    return () => window.removeEventListener('auth:unauthorized', handler)
  }, [navigate])

  const login = useCallback(async (email: string, password: string) => {
    const res = await authAPI.login(email, password)
    const user: User = res.data.user
    // loadConfig may fail in production (cross-origin HTML fetch).
    // Always navigate to dashboard — fallback features will be used.
    try {
      await loadConfig(user)
    } catch {
      applyConfig(null, user)
    }
    navigate('/')
  }, [navigate, loadConfig, applyConfig])

  const logout = useCallback(async () => {
    try { await authAPI.logout() } catch { /* already logged out */ }
    setState((s) => ({
      ...s,
      user: null,
      features: [],
      people: [],
      kpiDefinitions: [],
      roleName: ''
    }))
    navigate('/login')
  }, [navigate])

  const refreshConfig = useCallback(async () => {
    if (state.user) await loadConfig(state.user)
  }, [state.user, loadConfig])

  return (
    <AuthContext.Provider value={{ ...state, login, logout, refreshConfig }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
