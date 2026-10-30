import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '@/api/client'
import type { SiteContent } from '@/api/types'

export type SaveStatus = 'saved' | 'pending' | 'saving' | 'error' | 'conflict' | 'invalid'

/**
 * The draft, saved a moment after the last change.
 *
 * Saves run one after another, never two at once: each carries the version the
 * one before it was answered with, so a fast typist cannot race themselves
 * into a conflict. A save the network lost is tried again; one refused because
 * someone else saved first stops saving, so nothing of theirs is overwritten.
 */
export function useAutosave(
  save: (doc: SiteContent) => Promise<void>,
  delay = 800,
  onInvalid?: (error: ApiError) => void,
) {
  const [status, setStatus] = useState<SaveStatus>('saved')
  const [error, setError] = useState<ApiError | null>(null)
  const saveRef = useRef(save)
  const invalidRef = useRef(onInvalid)
  const flushRef = useRef<() => Promise<void>>(() => Promise.resolve())
  const pending = useRef<SiteContent | null>(null)
  const chain = useRef<Promise<void>>(Promise.resolve())
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const stopped = useRef(false)

  useEffect(() => {
    saveRef.current = save
    invalidRef.current = onInvalid
  }, [save, onInvalid])

  useEffect(() => () => {
    if (timer.current !== null) clearTimeout(timer.current)
  }, [])

  const flush = useCallback((): Promise<void> => {
    if (timer.current !== null) {
      clearTimeout(timer.current)
      timer.current = null
    }

    chain.current = chain.current.then(async () => {
      const doc = pending.current
      if (doc === null || stopped.current) return

      pending.current = null
      setStatus('saving')

      try {
        await saveRef.current(doc)
        setError(null)
        if (pending.current === null) setStatus('saved')
      } catch (caught) {
        const apiError = caught instanceof ApiError ? caught : new ApiError(0, 'The draft could not be saved.')
        setError(apiError)

        if (apiError.isConflict) {
          stopped.current = true
          setStatus('conflict')
        } else if (apiError.isValidation) {
          setStatus('invalid')
          invalidRef.current?.(apiError)
        } else {
          // Lost on the way: tried again shortly, unless a newer draft is
          // already waiting to go.
          pending.current ??= doc
          setStatus('error')
          timer.current = setTimeout(() => void flushRef.current(), 4000)
        }
      }
    })

    return chain.current
  }, [])

  useEffect(() => {
    flushRef.current = flush
  }, [flush])

  const schedule = useCallback(
    (doc: SiteContent) => {
      if (stopped.current) return

      pending.current = doc
      setStatus('pending')

      if (timer.current !== null) clearTimeout(timer.current)
      timer.current = setTimeout(() => void flush(), delay)
    },
    [delay, flush],
  )

  /** Start afresh after a reload or a discard: whatever was waiting is moot. */
  const reset = useCallback(() => {
    if (timer.current !== null) clearTimeout(timer.current)
    pending.current = null
    stopped.current = false
    setError(null)
    setStatus('saved')
  }, [])

  return { status, error, schedule, flush, reset }
}
