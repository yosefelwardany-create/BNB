import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, currentAuth, request, storeAuth } from '@/api/client'
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
    // A plan limit answers 402 with the cap and the current usage; a form that
    // only had the message could not show which limit was reached.
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
