import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, currentAuth, storeAuth } from '@/api/client'
import type { LoginResponse, MeResponse, OrganizationSummary } from '@/api/types'

/**
 * Who is signed in, which company they are acting for, and what they may do.
 *
 * The permission list comes from the server and is only ever used to decide
 * what to *show*. Every action is authorised again on the server, so hiding a
 * button is a courtesy, not a control.
 */
interface AuthContextValue {
  session: MeResponse | null
  loading: boolean
  organizations: OrganizationSummary[]
  signIn: (email: string, password: string, organization?: string) => Promise<void>
  signOut: () => Promise<void>
  switchOrganization: (organizationId: string) => Promise<void>
  can: (permission: string) => boolean
  canAny: (permissions: string[]) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<MeResponse | null>(null)
  const [organizations, setOrganizations] = useState<OrganizationSummary[]>([])
  const [loading, setLoading] = useState(true)

  const loadSession = useCallback(async () => {
    if (!currentAuth()?.token) {
      setSession(null)
      setLoading(false)
      return
    }

    try {
      setSession(await api.get<MeResponse>('auth/me'))
    } catch {
      storeAuth(null)
      setSession(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadSession()

    // The client dispatches this when the server rejects a token, so an
    // expired session returns the user to sign-in instead of leaving them on
    // a screen that silently stops working.
    const onUnauthenticated = () => setSession(null)
    window.addEventListener('habitat:unauthenticated', onUnauthenticated)

    return () => window.removeEventListener('habitat:unauthenticated', onUnauthenticated)
  }, [loadSession])

  const signIn = useCallback(
    async (email: string, password: string, organization?: string) => {
      const response = await api.anonymous<LoginResponse>('auth/login', {
        email,
        password,
        device_name: 'admin-web',
        organization,
      })

      setOrganizations(response.organizations)

      storeAuth({
        token: response.token ?? '',
        organizationId: organization ?? response.organizations[0]?.id ?? null,
      })

      setLoading(true)
      await loadSession()
    },
    [loadSession],
  )

  const signOut = useCallback(async () => {
    try {
      await api.post('auth/logout')
    } catch {
      // Signing out locally must succeed even if the server cannot be reached.
    }

    storeAuth(null)
    setSession(null)
    setOrganizations([])
  }, [])

  const switchOrganization = useCallback(
    async (organizationId: string) => {
      const auth = currentAuth()

      if (auth) {
        storeAuth({ ...auth, organizationId })
      }

      setLoading(true)
      await loadSession()
    },
    [loadSession],
  )

  const value = useMemo<AuthContextValue>(() => {
    const permissions = session?.permissions ?? []
    const unrestricted = session?.is_platform_admin === true || permissions.includes('*')

    const can = (permission: string) => unrestricted || permissions.includes(permission)

    return {
      session,
      loading,
      organizations,
      signIn,
      signOut,
      switchOrganization,
      can,
      canAny: (list: string[]) => list.some(can),
    }
  }, [session, loading, organizations, signIn, signOut, switchOrganization])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider.')
  }

  return context
}
