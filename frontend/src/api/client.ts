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

/**
 * A response that belongs to a different account than the one selected now.
 *
 * Raised when the selected account changed while a request was in flight, or
 * when the server says it answered for an account other than the one asked
 * for. Either way the payload is discarded unread: a list of one client's
 * reservations must never be rendered under another client's name, and a
 * mutation that completed under the previous account must not invalidate or
 * toast into the new one.
 *
 * Not an `ApiError`: nothing failed on the server, and no screen should show
 * it as a failure. The query layer treats it as non-retrying and the remount
 * that follows an account switch asks again under the right account.
 */
export class StaleOrganizationError extends Error {
  constructor(
    public readonly requested: string | null,
    public readonly current: string | null,
  ) {
    super('The response belongs to a previously selected account and was discarded.')
    this.name = 'StaleOrganizationError'
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

const ORGANIZATION_HEADER = 'X-Organization'

type Query = Record<string, string | number | boolean | undefined | null>

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  query?: Query
  /** Skip the auth header — used by sign-in and the public endpoints. */
  anonymous?: boolean
  signal?: AbortSignal
  /** What to ask for. JSON unless a download says otherwise. */
  accept?: string
}

function buildUrl(path: string, query: Query | undefined): URL {
  const url = new URL(
    `${BASE_URL}/api/v1/${path.replace(/^\//, '')}`,
    BASE_URL || window.location.origin,
  )

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value))
    }
  }

  return url
}

/**
 * Send the request and return the raw response, with the account checks done.
 *
 * The account the request is for is read once, before the request goes out,
 * and compared twice after it comes back: against the account selected *now*
 * (the user may have switched while it was in flight) and against the account
 * the server says it answered for (echoed on every scoped response). A
 * mismatch on either throws before the body is read.
 */
async function send(path: string, options: RequestOptions): Promise<Response> {
  const { method = 'GET', body, query, anonymous = false, signal, accept = 'application/json' } = options

  const url = buildUrl(path, query)

  const headers: Record<string, string> = { Accept: accept }

  if (body !== undefined) {
    // FormData sets its own content type, including the multipart boundary.
    // Setting it by hand produces a body the server cannot parse, and the error
    // it gives back says nothing about the cause.
    if (!(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json'
    }
  }

  const auth = anonymous ? null : readAuth()
  const requestedOrganization = auth?.organizationId ?? null

  if (!anonymous) {
    if (auth?.token) {
      headers.Authorization = `Bearer ${auth.token}`
    }

    // The account is named explicitly, because the platform owner works
    // across every client account and the server refuses to guess.
    if (requestedOrganization) {
      headers[ORGANIZATION_HEADER] = requestedOrganization
    }
  }

  const response = await fetch(url.toString(), {
    method,
    headers,
    body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    signal,
  })

  if (!anonymous) {
    const now = readAuth()?.organizationId ?? null

    // Switched accounts while this was in flight. The payload is for the
    // account that was selected then, and nothing on screen is about it now.
    if (now !== requestedOrganization) {
      throw new StaleOrganizationError(requestedOrganization, now)
    }

    // The server names the account it answered for. A disagreement means a
    // proxy, a cache or a bug served somebody else's data, and the only safe
    // thing to do with it is nothing.
    const echoed = response.headers.get(ORGANIZATION_HEADER)

    if (echoed !== null && requestedOrganization !== null && echoed !== requestedOrganization) {
      throw new StaleOrganizationError(requestedOrganization, echoed)
    }
  }

  return response
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await send(path, options)

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

/**
 * Fetch a file and hand it to the browser as a download.
 *
 * Opening the URL in a new tab would drop the Authorization header and the
 * account, and arrive as a 401. So the file is fetched with the same
 * credentials and the same account checks as every other request, and saved
 * from a blob. Nothing is saved when the account changed mid-flight.
 */
export async function download(
  path: string,
  filename: string,
  options: { query?: Query; accept?: string; failureMessage?: string } = {},
): Promise<void> {
  const response = await send(path, {
    method: 'GET',
    query: options.query,
    accept: options.accept ?? '*/*',
  })

  if (!response.ok) {
    if (response.status === 401) {
      storeAuth(null)
      window.dispatchEvent(new CustomEvent('habitat:unauthenticated'))
    }

    throw new ApiError(response.status, options.failureMessage ?? 'The file could not be downloaded.')
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  link.click()

  URL.revokeObjectURL(url)
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query'], signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', query, signal }),

  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),

  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),

  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),

  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),

  /** A file, saved by the browser. See {@link download}. */
  download,

  /** Unauthenticated calls: sign-in, registration, password reset. */
  anonymous: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body, anonymous: true }),

  /**
   * An unauthenticated read.
   *
   * Only one endpoint needs this: looking up an invitation by its token, which
   * by definition happens before there is an account to authenticate as.
   */
  anonymousGet: <T>(path: string) => request<T>(path, { method: 'GET', anonymous: true }),
}
