import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, currentAuth, request, StaleOrganizationError, storeAuth } from '@/api/client'
import { stubApi } from '@/test/server'

/**
 * The HTTP client.
 *
 * Everything the application knows about talking to the server goes through
 * here, so the things worth pinning down are the ones no screen would notice
 * going wrong: which headers travel, what an empty filter does to a query
 * string, and what a rejection turns into.
 */
describe('request', () => {
  beforeEach(() => {
    storeAuth({ token: 'test-token', organizationId: 'org_1' })
  })

  it('names the tenant on every authenticated call', () => {
    const server = stubApi({ 'GET properties': { body: { data: [] } } })

    return api.get('properties').then(() => {
      const [call] = server.callsTo('GET', 'properties')

      expect(call?.headers.Authorization).toBe('Bearer test-token')
      // A user may work for more than one company; the server refuses to
      // guess, so the client must always say.
      expect(call?.headers['X-Organization']).toBe('org_1')
    })
  })

  it('sends no credentials on an anonymous call', async () => {
    const server = stubApi({ 'POST auth/login': { body: { user: {}, organizations: [] } } })

    await api.anonymous('auth/login', { email: 'a@b.test' })

    const [call] = server.callsTo('POST', 'auth/login')

    expect(call?.headers.Authorization).toBeUndefined()
    expect(call?.headers['X-Organization']).toBeUndefined()
  })

  it('leaves empty filters out of the query string', async () => {
    const server = stubApi({ 'GET reservations': { body: { data: [] } } })

    await api.get('reservations', {
      status: 'confirmed',
      page: 2,
      // A cleared filter must not become `search=` — some endpoints treat an
      // empty string as a search for the empty string.
      search: '',
      property: undefined,
      owner: null,
    })

    const [call] = server.callsTo('GET', 'reservations')

    expect(call?.query.get('status')).toBe('confirmed')
    expect(call?.query.get('page')).toBe('2')
    expect(call?.query.has('search')).toBe(false)
    expect(call?.query.has('property')).toBe(false)
    expect(call?.query.has('owner')).toBe(false)
  })

  it('handles a 204 without trying to parse a body', async () => {
    stubApi({ 'DELETE webhooks/wh_1': { status: 204 } })

    await expect(api.delete('webhooks/wh_1')).resolves.toBeUndefined()
  })

  it('carries validation errors through to the form', async () => {
    stubApi({
      'POST properties': {
        status: 422,
        body: {
          message: 'The given data was invalid.',
          errors: { name: ['A name is required.'], 'address.city': ['A city is required.'] },
        },
      },
    })

    const error = await api.post('properties', {}).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    const apiError = error as ApiError

    expect(apiError.isValidation).toBe(true)
    expect(apiError.message).toBe('The given data was invalid.')
    expect(apiError.fieldError('name')).toBe('A name is required.')
    expect(apiError.fieldError('address.city')).toBe('A city is required.')
    expect(apiError.fieldError('nothing')).toBeUndefined()
  })

  it('distinguishes a refusal from a conflict from a forbidden', async () => {
    stubApi({
      'POST a': { status: 422, body: { message: 'no' } },
      'POST b': { status: 409, body: { message: 'no' } },
      'POST c': { status: 403, body: { message: 'no' } },
    })

    const a = (await api.post('a').catch((e: unknown) => e)) as ApiError
    const b = (await api.post('b').catch((e: unknown) => e)) as ApiError
    const c = (await api.post('c').catch((e: unknown) => e)) as ApiError

    expect([a.isValidation, a.isConflict, a.isForbidden]).toEqual([true, false, false])
    expect([b.isValidation, b.isConflict, b.isForbidden]).toEqual([false, true, false])
    expect([c.isValidation, c.isConflict, c.isForbidden]).toEqual([false, false, true])
  })

  it('keeps the payload of a refusal the server explains further', async () => {
    // A refusal may carry structured detail beyond its message; a form that
    // only had the message could not show it.
    stubApi({
      'POST properties': {
        status: 402,
        body: { message: 'Your plan allows 5 properties.', limit: 5, current: 5 },
      },
    })

    const error = (await api.post('properties').catch((e: unknown) => e)) as ApiError

    expect(error.status).toBe(402)
    expect(error.payload).toMatchObject({ limit: 5, current: 5 })
  })

  it('surfaces a non-JSON failure as its text rather than swallowing it', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(new Response('<html>502 Bad Gateway</html>', { status: 502 }))),
    )

    const error = (await api.get('properties').catch((e: unknown) => e)) as ApiError

    expect(error.status).toBe(502)
    expect(error.message).toContain('502 Bad Gateway')
  })

  it('clears the session and announces it when the token is rejected', async () => {
    stubApi({ 'GET auth/me': { status: 401, body: { message: 'Unauthenticated.' } } })

    const listener = vi.fn()
    window.addEventListener('habitat:unauthenticated', listener)

    await api.get('auth/me').catch(() => undefined)

    // Both halves matter: the stale token must not be sent again, and the
    // application must be told, or the user sits on a screen that has quietly
    // stopped working.
    expect(currentAuth()).toBeNull()
    expect(listener).toHaveBeenCalledOnce()

    window.removeEventListener('habitat:unauthenticated', listener)
  })

  it('survives a corrupted stored session instead of failing to start', async () => {
    localStorage.setItem('habitat.auth', 'not json')

    const server = stubApi({ 'GET properties': { body: { data: [] } } })

    await request('properties')

    expect(server.callsTo('GET', 'properties')[0]?.headers.Authorization).toBeUndefined()
  })
})

/**
 * Account isolation in the client.
 *
 * The platform owner switches between client accounts without signing out,
 * and every request names the account in a header. Two things must then hold
 * whatever the timing: a response that was asked for under one account is
 * never handed to code running under another, and a response the server says
 * is for a different account than the one asked for is never handed to
 * anybody.
 */
describe('account isolation', () => {
  beforeEach(() => {
    storeAuth({ token: 'test-token', organizationId: 'org_1' })
  })

  it('discards a response that arrives after the account changed', async () => {
    let resolve!: (response: Response) => void

    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((r) => (resolve = r))),
    )

    const pending = api.get('properties')

    // The owner picks another client while the request is in flight.
    storeAuth({ token: 'test-token', organizationId: 'org_2' })

    resolve(
      new Response(JSON.stringify({ data: [{ id: 'prp_belongs_to_org_1' }] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json', 'X-Organization': 'org_1' },
      }),
    )

    await expect(pending).rejects.toBeInstanceOf(StaleOrganizationError)
  })

  it('discards a response the server scoped to a different account', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Organization': 'org_9' },
          }),
        ),
      ),
    )

    await expect(api.get('properties')).rejects.toBeInstanceOf(StaleOrganizationError)
  })

  it('accepts a response echoing the account it asked for', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(JSON.stringify({ data: ['ok'] }), {
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Organization': 'org_1' },
          }),
        ),
      ),
    )

    await expect(api.get('properties')).resolves.toEqual({ data: ['ok'] })
  })

  it('is not a stale response when the account was never set', async () => {
    // A client (one account, never switches) and a request made before any
    // account is selected both run with no header and no echo to compare.
    storeAuth({ token: 'test-token', organizationId: null })

    const server = stubApi({ 'GET auth/me': { body: { user: {} } } })

    await expect(api.get('auth/me')).resolves.toEqual({ user: {} })
    expect(server.callsTo('GET', 'auth/me')[0]?.headers['X-Organization']).toBeUndefined()
  })
})

describe('download', () => {
  beforeEach(() => {
    storeAuth({ token: 'test-token', organizationId: 'org_1' })
  })

  it('sends the credentials and the account, and saves the file', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve(new Response('%PDF-1.4', { status: 200, headers: { 'Content-Type': 'application/pdf' } })),
    )
    vi.stubGlobal('fetch', fetchMock)

    const createObjectURL = vi.fn(() => 'blob:statement')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))

    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await api.download('documents/doc_1/download', 'statement.pdf', { accept: 'application/pdf' })

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    const headers = init.headers as Record<string, string>

    expect(headers.Authorization).toBe('Bearer test-token')
    expect(headers['X-Organization']).toBe('org_1')
    expect(headers.Accept).toBe('application/pdf')
    expect(createObjectURL).toHaveBeenCalledOnce()
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:statement')

    click.mockRestore()
  })

  it('saves nothing when the account changed while the file was coming', async () => {
    let resolve!: (response: Response) => void
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((r) => (resolve = r))))

    const createObjectURL = vi.fn(() => 'blob:x')
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }))

    const pending = api.download('reports/revenue/export', 'revenue.csv')

    storeAuth({ token: 'test-token', organizationId: 'org_2' })
    resolve(new Response('a,b', { status: 200 }))

    await expect(pending).rejects.toBeInstanceOf(StaleOrganizationError)
    expect(createObjectURL).not.toHaveBeenCalled()
  })

  it('turns a refusal into an ApiError with the caller\u2019s message', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('', { status: 403 }))))

    const error = (await api
      .download('documents/doc_1/download', 'x.pdf', { failureMessage: 'Not yours to download.' })
      .catch((e: unknown) => e)) as ApiError

    expect(error).toBeInstanceOf(ApiError)
    expect(error.isForbidden).toBe(true)
    expect(error.message).toBe('Not yours to download.')
  })
})
