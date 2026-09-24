/**
 * The single HTTP client for the API.
 *
 * Everything the admin application knows about talking to the server lives
 * here: where the API is, how the request is authenticated, which organization
 * it acts on, and how failures are shaped. No component builds a request by
 * hand.
 */

const STORAGE_KEY = 'habitat.auth'

export interface AuthState {
  token: string
  organizationId: string | null
}

/**
 * A failed request, with the server's own message and validation errors
 * preserved so a form can show them field by field.
 */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    public readonly payload: unknown = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** The first message for a field, which is what a form input shows. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  get isConflict(): boolean {
    return this.status === 409
  }

  get isForbidden(): boolean {
    return this.status === 403
  }
}

function readAuth(): AuthState | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as AuthState) : null
  } catch {
    return null
  }
}

export function storeAuth(state: AuthState | null): void {
  if (state === null) {
    localStorage.removeItem(STORAGE_KEY)
    return
  }

  localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
}

export function currentAuth(): AuthState | null {
  return readAuth()
}

/**
 * The API base. In development Vite proxies /api to the backend, so a
 * relative base works in both environments.
 */
const BASE_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  query?: Record<string, string | number | boolean | undefined | null>
  /** Skip the auth header — used by sign-in and the public endpoints. */
  anonymous?: boolean
  signal?: AbortSignal
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, anonymous = false, signal } = options

  const url = new URL(
    `${BASE_URL}/api/v1/${path.replace(/^\//, '')}`,
    BASE_URL || window.location.origin,
  )

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value))
    }
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  if (!anonymous) {
    const auth = readAuth()

    if (auth?.token) {
      headers.Authorization = `Bearer ${auth.token}`
    }

    // The tenant is named explicitly, because a user may work for more than
    // one company and the server refuses to guess.
    if (auth?.organizationId) {
      headers['X-Organization'] = auth.organizationId
    }
  }

  const response = await fetch(url.toString(), {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  const payload = text ? safeParse(text) : null

  if (!response.ok) {
    // An expired or revoked token should return the user to sign-in rather
    // than leaving them staring at a broken screen.
    if (response.status === 401) {
      storeAuth(null)
      window.dispatchEvent(new CustomEvent('habitat:unauthenticated'))
    }

    const record = (payload ?? {}) as Record<string, unknown>

    throw new ApiError(
      response.status,
      (record.message as string) ?? `Request failed with status ${response.status}`,
      (record.errors as Record<string, string[]>) ?? {},
      payload,
    )
  }

  return payload as T
}

function safeParse(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return { message: text }
  }
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query'], signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', query, signal }),

  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),

  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),

  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),

  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),

  /** Unauthenticated calls: sign-in, registration, password reset. */
  anonymous: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body, anonymous: true }),
}
