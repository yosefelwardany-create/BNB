import { vi } from 'vitest'

/**
 * A stand-in for the API.
 *
 * These tests drive the real client, the real AuthProvider and the real
 * screens; only the network is replaced. Mocking `useAuth` instead would be
 * easier and would test nothing — the thing worth protecting is that a
 * permission the server did not grant does not produce a button, and that only
 * holds if the permission list travels the path it really travels.
 *
 * Routes are matched on "METHOD path", where the path is what the caller
 * passed to `api.*` — so `GET auth/me`, not the full URL.
 */

export interface StubbedCall {
  method: string
  /** The path under /api/v1, without the query string. */
  path: string
  query: URLSearchParams
  headers: Record<string, string>
  body: unknown
}

export interface StubbedResponse {
  status?: number
  body?: unknown
}

type Handler = StubbedResponse | ((call: StubbedCall) => StubbedResponse)

export interface ApiStub {
  calls: StubbedCall[]
  /** Every call to a path, in order. */
  callsTo: (method: string, path: string) => StubbedCall[]
  /** Add or replace a route after the stub is installed. */
  on: (route: string, handler: Handler) => void
}

export function stubApi(routes: Record<string, Handler> = {}): ApiStub {
  const table = new Map<string, Handler>(Object.entries(routes))
  const calls: StubbedCall[] = []

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
      const url = new URL(input instanceof Request ? input.url : input.toString())
      const path = url.pathname.replace(/^\/api\/v1\//, '')
      const method = (init.method ?? 'GET').toUpperCase()

      const call: StubbedCall = {
        method,
        path,
        query: url.searchParams,
        headers: normaliseHeaders(init.headers),
        body: typeof init.body === 'string' ? JSON.parse(init.body) : null,
      }

      calls.push(call)

      const handler = table.get(`${method} ${path}`)

      if (handler === undefined) {
        // Loud rather than a convenient empty list: a screen quietly rendering
        // "nothing here" because a route was never stubbed is a test that
        // passes while proving nothing.
        return Promise.resolve(
          jsonResponse(404, {
            message: `No stub for ${method} ${path}. Add it to stubApi().`,
          }),
        )
      }

      const result = typeof handler === 'function' ? handler(call) : handler

      return Promise.resolve(jsonResponse(result.status ?? 200, result.body ?? null))
    }),
  )

  return {
    calls,
    callsTo: (method, path) =>
      calls.filter((call) => call.method === method.toUpperCase() && call.path === path),
    on: (route, handler) => table.set(route, handler),
  }
}

function jsonResponse(status: number, body: unknown): Response {
  if (status === 204 || body === null) {
    return new Response(status === 204 ? null : '', { status })
  }

  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function normaliseHeaders(headers: HeadersInit | undefined): Record<string, string> {
  const result: Record<string, string> = {}

  if (headers === undefined) return result

  for (const [key, value] of Object.entries(headers as Record<string, string>)) {
    result[key] = value
  }

  return result
}

/** A page of results in the shape every list endpoint returns. */
export function page<T>(data: T[], overrides: Record<string, number> = {}) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length === 0 ? null : 1,
      last_page: 1,
      per_page: 25,
      to: data.length === 0 ? null : data.length,
      total: data.length,
      ...overrides,
    },
  }
}
