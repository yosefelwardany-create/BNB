import { useEffect, useState } from 'react'
import { useIsFetching, useIsMutating } from '@tanstack/react-query'

/**
 * A lime thread across the top of the window while anything is loading or
 * saving. Waits a moment before appearing, so a response that comes straight
 * back does not make the screen flicker.
 */
export function TopProgress() {
  const busy = useIsFetching() + useIsMutating() > 0
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(() => setVisible(busy), busy ? 180 : 0)
    return () => window.clearTimeout(timer)
  }, [busy])

  if (!visible) return null

  return (
    <div className="top-progress" aria-hidden="true">
      <div className="top-progress__bar" />
    </div>
  )
}
