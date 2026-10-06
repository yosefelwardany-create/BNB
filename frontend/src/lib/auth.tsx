import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, currentAuth, storeAuth } from '@/api/client'
import type {
  LoginResponse,
  MeResponse,
  MfaChallengeResponse,
  OrganizationSummary,
} from '@/api/types'

/**
 * Who is signed in, which account they are acting for, and what they may do.
 *
 * The permission list comes from the server and is only ever used to decide
 * what to *show*. Every action is authorised again on the server, so hiding a
 * button is a courtesy, not a control.
 *
 * For the platform owner, `organizations` is every client account and the
 * selected one is whichever `X-Organization` names. Nothing here signs in as a
 * client: the owner's own token is used throughout, and the server authorises
 * each request against the account in the header.
 */
interface AuthContextValue {
  session: MeResponse | null
  loading: boolean
  organizations: OrganizationSummary[]
  /** Resolves with `mfaRequired` when a code is still needed. */
  signIn: (
    email: string,
    password: string,
    organization?: string,
  ) => Promise<{ mfaRequired: boolean; reference?: string }>
  completeMfa: (reference: string, code: string, organization?: string) => Promise<void>
  signOut: () => Promise<void>
  /** Resolves once the session for the new account has loaded. */
  switchOrganization: (organizationId: string) => Promise<void>
  can: (permission: string) => boolean
  canAny: (permissions: string[]) => boolean
  /** Re-read the session after changing something about yourself. */
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** The account the platform owner last worked in, so a reload lands there. */
const LAST_ACCOUNT_KEY = 'habitat.account'

function rememberAccount(organizationId: string | null): void {
  try {
    if (organizationId === null) localStorage.removeItem(LAST_ACCOUNT_KEY)
    else localStorage.setItem(LAST_ACCOUNT_KEY, organizationId)
  } catch {
    // A preference. The selector still works without it.
  }
}

function lastAccount(): string | null {
  try {
    return localStorage.getItem(LAST_ACCOUNT_KEY)
  } catch {
    return null
  }
}

/**
 * Which account to select for a platform owner who has none selected.
 *
 * The one they used last if it still exists, otherwise the first. An owner
 * with no client accounts at all gets null and lands on the Accounts page.
 */
function chooseAccount(organizations: OrganizationSummary[]): string | null {
  const remembered = lastAccount()

  if (remembered !== null && organizations.some((organization) => organization.id === remembered)) {
    return remembered
  }

  return organizations[0]?.id ?? null
}

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
      let me = await api.get<MeResponse>('auth/me')

      // A platform owner whose stored selection is empty (first sign-in, or a
      // cleared selection) is put into an account rather than shown a
      // workspace about nothing. One extra round trip, once.
      if (me.is_platform_admin && me.organization === null) {
        const chosen = chooseAccount(me.organizations ?? [])
        const auth = currentAuth()

        if (chosen !== null && auth !== null) {
          storeAuth({ ...auth, organizationId: chosen })
          me = await api.get<MeResponse>('auth/me')
        }
      }

      setSession(me)
      // From the session rather than only from sign-in, so the account list
      // survives a reload.
      setOrganizations(me.organizations ?? [])

      if (me.organization !== null) rememberAccount(me.organization.id)
    } catch {
      storeAuth(null)
      setSession(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    // The rule is about effects that set state synchronously and cascade a
    // render. This one starts a request and sets state when it answers, which
    // is what an effect is for: the stored token lives outside React and has
    // to be exchanged for a session before anything can be drawn.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadSession()

    // The client dispatches this when the server rejects a token, so an
    // expired session returns the user to sign-in instead of leaving them on
    // a screen that silently stops working.
    const onUnauthenticated = () => setSession(null)
    window.addEventListener('habitat:unauthenticated', onUnauthenticated)

    return () => window.removeEventListener('habitat:unauthenticated', onUnauthenticated)
  }, [loadSession])

  /**
   * Finish a sign-in from whichever endpoint completed it.
   *
   * Shared by the password-only path and the one that answers a two-factor
   * challenge, because both return the same payload and storing it in two
   * places is how they drift.
   */
  const establish = useCallback(
    async (response: LoginResponse, organization?: string) => {
      setOrganizations(response.organizations)

      storeAuth({
        token: response.token ?? '',
        organizationId:
          organization ?? chooseAccount(response.organizations) ?? response.organizations[0]?.id ?? null,
      })

      setLoading(true)
      await loadSession()
    },
    [loadSession],
  )

  const signIn = useCallback(
    async (email: string, password: string, organization?: string) => {
      const response = await api.anonymous<LoginResponse | MfaChallengeResponse>('auth/login', {
        email,
        password,
        device_name: 'admin-web',
        organization,
      })

      // A correct password is not a sign-in when a second factor is enabled.
      // Nothing is stored — the caller is handed the reference and shows the
      // code screen.
      if ('mfa_required' in response) {
        return { mfaRequired: true as const, reference: response.challenge.reference }
      }

      await establish(response, organization)

      return { mfaRequired: false as const }
    },
    [establish],
  )

  const completeMfa = useCallback(
    async (reference: string, code: string, organization?: string) => {
      const response = await api.anonymous<LoginResponse>('auth/mfa/challenge', {
        challenge: reference,
        code,
        device_name: 'admin-web',
        organization,
      })

      await establish(response, organization)
    },
    [establish],
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

  /**
   * Work in a different account.
   *
   * Storing the id first is what makes every in-flight request for the old
   * account fail its check in the client and be discarded. Then the session
   * reloads, which unmounts the whole tree while it does; the query cache for
   * the old account goes with it (see OrganizationScopedQueries).
   */
  const switchOrganization = useCallback(
    async (organizationId: string) => {
      const auth = currentAuth()

      if (auth) {
        storeAuth({ ...auth, organizationId })
      }

      rememberAccount(organizationId)
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
      completeMfa,
      refresh: loadSession,
      can,
      canAny: (list: string[]) => list.some(can),
    }
  }, [session, loading, organizations, signIn, completeMfa, signOut, switchOrganization, loadSession])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// Exported beside the provider on purpose: the context object stays private,
// so the only way to read it is through this. The cost is that editing this
// file remounts the tree in development rather than hot-reloading it.
// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider.')
  }

  return context
}
