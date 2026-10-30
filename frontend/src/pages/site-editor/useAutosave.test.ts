import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { ApiError } from '@/api/client'
import type { SiteContent } from '@/api/types'
import { useAutosave } from './useAutosave'

const DOC: SiteContent = { schema: 1, theme: 'coastal', style: { primary: '#1f4e5f', accent: '#e07a5f', fonts: 'fraunces-inter', buttons: 'rounded' }, pages: [] }

/** Move the clock on, letting every save and retry it starts settle. */
const wait = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms))

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useAutosave', () => {
  it('tries again after 1, 2, 4 and 8 seconds, then every 15', async () => {
    const save = vi.fn().mockRejectedValue(new ApiError(0, 'The network is down.'))
    const { result } = renderHook(() => useAutosave(save, 0))

    act(() => result.current.schedule(DOC))
    await wait(0)
    expect(save).toHaveBeenCalledTimes(1)
    expect(result.current.status).toBe('error')

    const tries: number[] = []
    for (const ms of [999, 1, 1999, 1, 3999, 1, 7999, 1, 14999, 1, 14999, 1]) {
      await wait(ms)
      tries.push(save.mock.calls.length)
    }

    expect(tries).toEqual([1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7])
  })

  it('starts again from a second once a save goes through', async () => {
    const save = vi.fn()
      .mockRejectedValueOnce(new ApiError(0, 'The network is down.'))
      .mockRejectedValueOnce(new ApiError(0, 'The network is down.'))
      .mockResolvedValueOnce(undefined)
      .mockRejectedValue(new ApiError(0, 'The network is down.'))
    const { result } = renderHook(() => useAutosave(save, 0))

    act(() => result.current.schedule(DOC))
    await wait(0)
    await wait(1000)
    await wait(2000)
    expect(save).toHaveBeenCalledTimes(3)
    expect(result.current.status).toBe('saved')

    act(() => result.current.schedule(DOC))
    await wait(0)
    expect(save).toHaveBeenCalledTimes(4)
    await wait(1000)
    expect(save).toHaveBeenCalledTimes(5)
  })
})
